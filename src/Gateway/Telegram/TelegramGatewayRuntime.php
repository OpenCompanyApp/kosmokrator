<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Gateway\GatewayApproval;
use Kosmokrator\Gateway\GatewayApprovalStore;
use Kosmokrator\Gateway\GatewayCheckpointStore;
use Kosmokrator\Gateway\GatewayMessageEvent;
use Kosmokrator\Gateway\GatewayMessageStore;
use Kosmokrator\Gateway\GatewayPendingInputStore;
use Kosmokrator\Gateway\GatewaySessionStore;
use Psr\Log\LoggerInterface;

final class TelegramGatewayRuntime
{
    private const MAX_POLL_BACKOFF_SECONDS = 30;

    /** @var list<array<string, mixed>> */
    private array $backlog = [];

    private string $botUsername = '';

    private readonly TelegramSessionRouter $router;

    /**
     * @var array<string, array{
     *   sessionId: string,
     *   pid: int|null,
     *   startedAt: float,
     *   handle: TelegramGatewayWorkerHandleInterface
     * }>
     */
    private array $activeRoutes = [];

    private int $pollFailureCount = 0;

    public function __construct(
        private readonly Container $container,
        private readonly TelegramClientInterface $client,
        private readonly TelegramGatewayConfig $config,
        private readonly GatewaySessionStore $sessionLinks,
        private readonly GatewayMessageStore $messages,
        private readonly GatewayApprovalStore $approvals,
        private readonly GatewayCheckpointStore $checkpoints,
        private readonly GatewayPendingInputStore $pendingInputs,
        private readonly LoggerInterface $log,
        private readonly ?TelegramGatewayWorkerLauncherInterface $launcher = null,
    ) {
        $this->router = new TelegramSessionRouter($config->sessionMode);
    }

    public function setBotUsername(string $botUsername): void
    {
        $this->botUsername = ltrim($botUsername, '@');
    }

    public function registerBotCommands(): void
    {
        $this->client->setMyCommands(TelegramBotCommandCatalog::commands());
    }

    public function run(): never
    {
        if ($this->botUsername === '') {
            $me = $this->client->getMe();
            $this->botUsername = (string) ($me['username'] ?? '');
        }
        $this->client->deleteWebhook();

        $offset = $this->loadOffset();
        $normalizer = new TelegramUpdateNormalizer($this->botUsername);

        while (true) {
            $this->syncActiveRoutes();
            try {
                $updates = $this->nextBatch($offset);
                $this->pollFailureCount = 0;
            } catch (\Throwable $e) {
                $this->pollFailureCount++;
                $delay = min(self::MAX_POLL_BACKOFF_SECONDS, max(1, 2 ** min($this->pollFailureCount, 4)));
                $this->log->warning('Telegram polling failed', [
                    'attempt' => $this->pollFailureCount,
                    'delay_seconds' => $delay,
                    'error' => $e->getMessage(),
                ]);
                sleep($delay);

                continue;
            }

            foreach ($updates as $update) {
                $offset = ((int) ($update['update_id'] ?? 0)) + 1;
                $this->storeOffset($offset);

                $event = $normalizer->normalize($update);
                if ($event === null) {
                    continue;
                }

                $event = $event->withRouteKey($this->router->routeKeyFor($event));
                $this->handleEvent($event);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     */
    public function processUpdates(array $updates): void
    {
        $this->syncActiveRoutes();
        $normalizer = new TelegramUpdateNormalizer($this->botUsername !== '' ? $this->botUsername : 'bot');

        foreach ($updates as $update) {
            $event = $normalizer->normalize($update);
            if ($event === null) {
                continue;
            }

            $event = $event->withRouteKey($this->router->routeKeyFor($event));
            $this->handleEvent($event);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nextBatch(?int $offset): array
    {
        if ($this->backlog !== []) {
            $batch = $this->backlog;
            $this->backlog = [];

            return $batch;
        }

        return $this->client->getUpdates($offset, $this->config->pollTimeoutSeconds);
    }

    private function handleEvent(GatewayMessageEvent $event): void
    {
        if (! $this->config->allowsChat($event->chatId)) {
            return;
        }

        if (! $this->config->allowsUser($event->userId, $event->username)) {
            return;
        }

        if ($event->callbackQueryId !== null) {
            $this->handleCallbackQuery($event);

            return;
        }

        if ($event->isCommand('/help')) {
            $this->client->sendMessage($event->chatId, $this->helpText(), $event->threadId, replyMarkup: $this->controlKeyboard());

            return;
        }

        if ($event->isCommand('/status')) {
            $this->syncActiveRoutes();
            $link = $this->sessionLinks->find('telegram', $event->routeKey);
            $active = $this->activeRoutes[$event->routeKey] ?? null;
            $checkpoint = $this->checkpoints->get('telegram', 'last_update_id') ?? 'none';
            $queued = $this->pendingInputs->count('telegram', $event->routeKey);
            $text = implode("\n", array_filter([
                'Telegram gateway status',
                $this->botUsername !== '' ? 'Bot: @'.$this->botUsername : null,
                'Session mode: '.$this->config->sessionMode,
                'Mention gating: '.($this->config->requireMention ? 'required in groups' : 'off'),
                'Checkpoint: '.$checkpoint,
                $link === null ? 'Session: none linked yet' : 'Session: '.$link->sessionId,
                'Route: '.$event->routeKey,
                'Running: '.($active !== null ? 'yes' : 'no'),
                $active !== null ? 'Elapsed: '.$this->formatElapsed((int) floor(microtime(true) - $active['startedAt'])) : null,
                $active !== null && $active['pid'] !== null ? 'Worker PID: '.$active['pid'] : null,
                'Queued inputs: '.$queued,
                'Active routes: '.count($this->activeRoutes),
            ]));
            $this->client->sendMessage($event->chatId, $text, $event->threadId, replyMarkup: $this->controlKeyboard());

            return;
        }

        if ($event->isCommand('/new')) {
            $this->sessionLinks->delete('telegram', $event->routeKey);
            $this->messages->delete('telegram', $event->routeKey, 'response');
            $this->pendingInputs->clear('telegram', $event->routeKey);
            $this->client->sendMessage($event->chatId, 'Started a fresh session for this chat. Your next message will create a new Kosmo session.', $event->threadId);

            return;
        }

        if ($event->isCommand('/resume')) {
            $link = $this->sessionLinks->find('telegram', $event->routeKey);
            if ($link !== null) {
                $this->client->sendMessage($event->chatId, "Resuming linked session {$link->sessionId}.", $event->threadId);

                return;
            }

            $this->client->sendMessage($event->chatId, 'No linked session exists yet for this chat. Send a message to start one.', $event->threadId);

            return;
        }

        if ($event->isCommand('/approve') || $event->isCommand('/deny')) {
            [$status, $message] = $this->resolveApprovalCommand($event);
            $approval = $status !== null ? $this->approvals->latestPending('telegram', $event->routeKey) : null;
            if ($approval !== null && ! $this->canResolveApproval($event, $approval)) {
                $this->log->warning('Unauthorized Telegram approval command denied', [
                    'route_key' => $event->routeKey,
                    'user_id' => $event->userId,
                    'username' => $event->username,
                ]);
                $this->client->sendMessage($event->chatId, 'Not authorized to resolve this approval.', $event->threadId);

                return;
            }

            $pending = null;
            if ($approval !== null && $status !== null) {
                $this->approvals->resolve($approval->id, $status);
                $pending = $approval;
            }
            $this->client->sendMessage(
                $event->chatId,
                $pending === null
                    ? 'No pending approval for this chat.'
                    : $message,
                $event->threadId,
            );

            return;
        }

        if ($event->isCommand('/cancel')) {
            $approval = $this->approvals->latestPending('telegram', $event->routeKey);
            if ($approval !== null && ! $this->canResolveApproval($event, $approval)) {
                $this->log->warning('Unauthorized Telegram cancel approval denied', [
                    'route_key' => $event->routeKey,
                    'user_id' => $event->userId,
                    'username' => $event->username,
                ]);
                $this->client->sendMessage($event->chatId, 'Not authorized to cancel this approval.', $event->threadId);

                return;
            }

            $pending = null;
            if ($approval !== null) {
                $this->approvals->resolve($approval->id, 'denied');
                $pending = $approval;
            }
            $message = null;
            if ($pending !== null) {
                $message = 'Cancelled the pending approval request.';
            } else {
                $active = $this->activeRoutes[$event->routeKey] ?? null;
                if ($active !== null && $active['handle']->terminate()) {
                    $message = 'Cancelling the active run…';
                } else {
                    unset($this->activeRoutes[$event->routeKey]);
                    $message = 'No pending approval or active run to cancel.';
                }
            }
            $this->client->sendMessage($event->chatId, $message, $event->threadId);

            return;
        }

        if (! $event->isPrivate && ! $this->config->isFreeResponseChat($event->chatId) && $this->config->requireMention) {
            if (! $event->mentionsBot && ! $event->isReplyToBot) {
                return;
            }
        }

        $this->runAgentForEvent($event);
    }

    private function runAgentForEvent(GatewayMessageEvent $event): void
    {
        $this->syncActiveRoutes();
        if (isset($this->activeRoutes[$event->routeKey])) {
            $this->pendingInputs->enqueue('telegram', $event->routeKey, $event);
            $this->client->sendMessage(
                $event->chatId,
                'Queued for the next turn in this chat.',
                $event->threadId,
            );

            return;
        }

        $this->setProcessingReaction($event, '👀');
        $this->launchEvent($event);
    }

    private function launchEvent(GatewayMessageEvent $event): void
    {
        $handle = ($this->launcher ?? $this->defaultLauncher())->launch($event);
        $link = $this->sessionLinks->find('telegram', $event->routeKey);

        $this->activeRoutes[$event->routeKey] = [
            'sessionId' => $link?->sessionId ?? '',
            'pid' => $handle->pid(),
            'startedAt' => microtime(true),
            'handle' => $handle,
        ];
    }

    private function helpText(): string
    {
        return TelegramBotCommandCatalog::helpText();
    }

    private function loadOffset(): ?int
    {
        $value = $this->checkpoints->get('telegram', 'last_update_id');

        return $value !== null ? (int) $value : null;
    }

    private function storeOffset(int $offset): void
    {
        $this->checkpoints->set('telegram', 'last_update_id', (string) $offset);
    }

    private function defaultLauncher(): TelegramGatewayWorkerLauncherInterface
    {
        $projectRoot = InstructionLoader::gitRoot() ?? getcwd();

        return new SymfonyProcessTelegramWorkerLauncher($projectRoot);
    }

    private function syncActiveRoutes(): void
    {
        foreach ($this->activeRoutes as $routeKey => $active) {
            if (! $active['handle']->isRunning()) {
                unset($this->activeRoutes[$routeKey]);

                $pending = $this->pendingInputs->dequeueNext('telegram', $routeKey);
                if ($pending !== null) {
                    $this->launchEvent(GatewayMessageEvent::fromArray($pending->payload));
                }
            }
        }
    }

    private function handleCallbackQuery(GatewayMessageEvent $event): void
    {
        if ($event->callbackQueryId === null) {
            return;
        }

        if (preg_match('/^gc:cmd:(.+)$/', $event->text, $matches) === 1) {
            if (! $this->config->canUseControlCallback($event->userId, $event->username, $event->isPrivate, $event->chatId)) {
                $this->log->warning('Unauthorized Telegram control callback denied', [
                    'route_key' => $event->routeKey,
                    'action' => $event->text,
                    'user_id' => $event->userId,
                    'username' => $event->username,
                ]);
                $this->client->answerCallbackQuery($event->callbackQueryId, 'Not authorized.');

                return;
            }

            $this->handleControlCallback($event, '/'.ltrim((string) $matches[1], '/'));

            return;
        }

        if (preg_match('/^ga:(allow|deny|always|guardian|prometheus):(\d+)$/', $event->text, $matches) !== 1) {
            $this->client->answerCallbackQuery($event->callbackQueryId, 'Unsupported action.');

            return;
        }

        $approvalId = (int) $matches[2];
        $approval = $this->approvals->find($approvalId);
        if ($approval === null || $approval->routeKey !== $event->routeKey) {
            $this->client->answerCallbackQuery($event->callbackQueryId, 'Approval not found.');

            return;
        }

        if (! $this->canResolveApproval($event, $approval)) {
            $this->log->warning('Unauthorized Telegram approval callback denied', [
                'route_key' => $event->routeKey,
                'approval_id' => $approval->id,
                'user_id' => $event->userId,
                'username' => $event->username,
            ]);
            $this->client->answerCallbackQuery($event->callbackQueryId, 'Not authorized.');

            return;
        }

        $status = match ($matches[1]) {
            'allow' => 'approved',
            'always' => 'always',
            'guardian' => 'guardian',
            'prometheus' => 'prometheus',
            default => 'denied',
        };
        $this->approvals->resolve($approvalId, $status);
        $label = match ($status) {
            'approved' => 'Approved',
            'always' => 'Approved Always',
            'guardian' => 'Switched To Guardian',
            'prometheus' => 'Switched To Prometheus',
            default => 'Denied',
        };
        $this->client->answerCallbackQuery($event->callbackQueryId, $label.'.');

        if ($event->messageId !== null) {
            $this->client->editMessageText(
                $event->chatId,
                $event->messageId,
                '<b>'.$label.'</b> <code>'.htmlspecialchars($approval->toolName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>.',
                ['inline_keyboard' => []],
                'HTML',
            );
        }
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveApprovalCommand(GatewayMessageEvent $event): array
    {
        if ($event->isCommand('/deny')) {
            return ['denied', 'Denied.'];
        }

        $args = strtolower(trim(substr($event->text, strlen('/approve'))));

        return match (true) {
            str_contains($args, 'guardian') => ['guardian', 'Switched to Guardian mode and approved.'],
            str_contains($args, 'prometheus') => ['prometheus', 'Switched to Prometheus mode and approved.'],
            str_contains($args, 'always') => ['always', 'Approved for the rest of this session.'],
            default => ['approved', 'Approved.'],
        };
    }

    private function canResolveApproval(GatewayMessageEvent $event, GatewayApproval $approval): bool
    {
        return $this->config->canResolveApproval(
            $event->userId,
            $event->username,
            $event->isPrivate,
            $event->chatId,
            $approval->requesterUserId,
            $approval->requesterUsername,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function controlKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => 'Edit', 'callback_data' => 'gc:cmd:edit'],
                    ['text' => 'Plan', 'callback_data' => 'gc:cmd:plan'],
                    ['text' => 'Ask', 'callback_data' => 'gc:cmd:ask'],
                ],
                [
                    ['text' => 'Guardian', 'callback_data' => 'gc:cmd:guardian'],
                    ['text' => 'Argus', 'callback_data' => 'gc:cmd:argus'],
                    ['text' => 'Prometheus', 'callback_data' => 'gc:cmd:prometheus'],
                ],
                [
                    ['text' => 'Compact', 'callback_data' => 'gc:cmd:compact'],
                    ['text' => 'Status', 'callback_data' => 'gc:cmd:status'],
                    ['text' => 'Cancel', 'callback_data' => 'gc:cmd:cancel'],
                ],
                [
                    ['text' => 'New', 'callback_data' => 'gc:cmd:new'],
                    ['text' => 'Resume', 'callback_data' => 'gc:cmd:resume'],
                ],
            ],
        ];
    }

    private function handleControlCallback(GatewayMessageEvent $event, string $command): void
    {
        if ($event->callbackQueryId === null) {
            return;
        }

        $this->client->answerCallbackQuery($event->callbackQueryId, 'Working…');

        if (in_array($command, ['/help', '/status', '/new', '/resume', '/cancel'], true)) {
            $synthetic = new GatewayMessageEvent(
                updateId: $event->updateId,
                platform: $event->platform,
                chatId: $event->chatId,
                threadId: $event->threadId,
                routeKey: $event->routeKey,
                text: $command,
                userId: $event->userId,
                username: $event->username,
                isPrivate: $event->isPrivate,
                isReplyToBot: $event->isReplyToBot,
                mentionsBot: $event->mentionsBot,
                messageId: $event->messageId,
                callbackQueryId: null,
            );
            $this->handleEvent($synthetic);

            return;
        }

        $synthetic = new GatewayMessageEvent(
            updateId: $event->updateId,
            platform: $event->platform,
            chatId: $event->chatId,
            threadId: $event->threadId,
            routeKey: $event->routeKey,
            text: $command,
            userId: $event->userId,
            username: $event->username,
            isPrivate: $event->isPrivate,
            isReplyToBot: $event->isReplyToBot,
            mentionsBot: $event->mentionsBot,
            messageId: $event->messageId,
            callbackQueryId: null,
        );

        $this->runAgentForEvent($synthetic);
    }

    private function formatElapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $remaining > 0 ? "{$minutes}m {$remaining}s" : "{$minutes}m";
    }

    private function setProcessingReaction(GatewayMessageEvent $event, string $emoji): void
    {
        if (! $this->config->reactions || $event->messageId === null) {
            return;
        }

        try {
            $this->client->setMessageReaction($event->chatId, $event->messageId, $emoji);
        } catch (\Throwable) {
            // Best-effort only; reactions depend on Telegram chat capabilities.
        }
    }
}

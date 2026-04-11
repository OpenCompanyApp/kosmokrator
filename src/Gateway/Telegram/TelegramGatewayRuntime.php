<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Gateway\GatewayApprovalStore;
use Kosmokrator\Gateway\GatewayCheckpointStore;
use Kosmokrator\Gateway\GatewayMessageEvent;
use Kosmokrator\Gateway\GatewayMessageStore;
use Kosmokrator\Gateway\GatewaySessionStore;
use Psr\Log\LoggerInterface;

final class TelegramGatewayRuntime
{
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

    public function __construct(
        private readonly Container $container,
        private readonly TelegramClientInterface $client,
        private readonly TelegramGatewayConfig $config,
        private readonly GatewaySessionStore $sessionLinks,
        private readonly GatewayMessageStore $messages,
        private readonly GatewayApprovalStore $approvals,
        private readonly GatewayCheckpointStore $checkpoints,
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
            $updates = $this->nextBatch($offset);

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
            $this->client->sendMessage($event->chatId, $this->helpText(), $event->threadId);

            return;
        }

        if ($event->isCommand('/status')) {
            $this->syncActiveRoutes();
            $link = $this->sessionLinks->find('telegram', $event->routeKey);
            $active = $this->activeRoutes[$event->routeKey] ?? null;
            $checkpoint = $this->checkpoints->get('telegram', 'last_update_id') ?? 'none';
            $text = implode("\n", array_filter([
                'Telegram gateway status',
                $this->botUsername !== '' ? 'Bot: @'.$this->botUsername : null,
                'Session mode: '.$this->config->sessionMode,
                'Mention gating: '.($this->config->requireMention ? 'required in groups' : 'off'),
                'Checkpoint: '.$checkpoint,
                $link === null ? 'Session: none linked yet' : 'Session: '.$link->sessionId,
                'Route: '.$event->routeKey,
                'Running: '.($active !== null ? 'yes' : 'no'),
                $active !== null && $active['pid'] !== null ? 'Worker PID: '.$active['pid'] : null,
                'Active routes: '.count($this->activeRoutes),
            ]));
            $this->client->sendMessage($event->chatId, $text, $event->threadId);

            return;
        }

        if ($event->isCommand('/new')) {
            $this->sessionLinks->delete('telegram', $event->routeKey);
            $this->messages->delete('telegram', $event->routeKey, 'response');
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
            $pending = $this->approvals->resolveLatestPending(
                'telegram',
                $event->routeKey,
                $event->isCommand('/approve') ? 'approved' : 'denied',
            );
            $this->client->sendMessage(
                $event->chatId,
                $pending === null
                    ? 'No pending approval for this chat.'
                    : ($event->isCommand('/approve') ? 'Approved.' : 'Denied.'),
                $event->threadId,
            );

            return;
        }

        if ($event->isCommand('/cancel')) {
            $pending = $this->approvals->resolveLatestPending('telegram', $event->routeKey, 'denied');
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
            $this->client->sendMessage(
                $event->chatId,
                'A run is already active for this chat. Use /status to inspect it or /cancel to stop it.',
                $event->threadId,
            );

            return;
        }

        $handle = ($this->launcher ?? $this->defaultLauncher())->launch($event);
        $link = $this->sessionLinks->find('telegram', $event->routeKey);

        $this->activeRoutes[$event->routeKey] = [
            'sessionId' => $link?->sessionId ?? '',
            'pid' => $handle->pid(),
            'startedAt' => microtime(true),
            'handle' => $handle,
        ];
    }

    private function awaitApproval(int $approvalId, GatewayMessageEvent $origin): string
    {
        $normalizer = new TelegramUpdateNormalizer($this->botUsername);
        $offset = $this->loadOffset();

        while (true) {
            $approval = $this->approvals->find($approvalId);
            if ($approval !== null && $approval->status === 'approved') {
                return 'allow';
            }

            if ($approval !== null && $approval->status === 'denied') {
                return 'deny';
            }

            $updates = $this->nextBatch($offset);
            foreach ($updates as $update) {
                $offset = ((int) ($update['update_id'] ?? 0)) + 1;
                $this->storeOffset($offset);

                $event = $normalizer->normalize($update);
                if ($event === null) {
                    continue;
                }

                $event = $event->withRouteKey($this->router->routeKeyFor($event));

                if ($event->routeKey === $origin->routeKey) {
                    if ($event->isCommand('/approve')) {
                        $this->approvals->resolve($approvalId, 'approved');

                        return 'allow';
                    }

                    if ($event->isCommand('/deny') || $event->isCommand('/cancel')) {
                        $this->approvals->resolve($approvalId, 'denied');

                        return 'deny';
                    }
                }

                $this->backlog[] = $update;
            }
        }
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
            }
        }
    }

    private function handleCallbackQuery(GatewayMessageEvent $event): void
    {
        if ($event->callbackQueryId === null) {
            return;
        }

        if (preg_match('/^ga:(approve|deny):(\d+)$/', $event->text, $matches) !== 1) {
            $this->client->answerCallbackQuery($event->callbackQueryId, 'Unsupported action.');

            return;
        }

        $approvalId = (int) $matches[2];
        $approval = $this->approvals->find($approvalId);
        if ($approval === null || $approval->routeKey !== $event->routeKey) {
            $this->client->answerCallbackQuery($event->callbackQueryId, 'Approval not found.');

            return;
        }

        $status = $matches[1] === 'approve' ? 'approved' : 'denied';
        $this->approvals->resolve($approvalId, $status);
        $label = $status === 'approved' ? 'Approved' : 'Denied';
        $this->client->answerCallbackQuery($event->callbackQueryId, $label.'.');

        if ($event->messageId !== null) {
            $this->client->editMessageText(
                $event->chatId,
                $event->messageId,
                "{$label} `{$approval->toolName}`.",
                ['inline_keyboard' => []],
            );
        }
    }
}

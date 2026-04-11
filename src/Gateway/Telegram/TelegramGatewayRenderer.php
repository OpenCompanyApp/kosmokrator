<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Gateway\GatewayApprovalStore;
use Kosmokrator\Gateway\GatewayMessageStore;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiAnimation;
use Kosmokrator\UI\RendererInterface;

final class TelegramGatewayRenderer implements RendererInterface
{
    private const EMPTY_INLINE_KEYBOARD = ['inline_keyboard' => []];

    private string $buffer = '';

    private string $placeholderText = 'Thinking…';

    private ?string $statusNotice = null;

    private ?string $activeToolName = null;

    private ?int $statusMessageId = null;

    private ?int $answerMessageId = null;

    private float $lastFlushAt = 0.0;

    /**
     * @param  \Closure(int, string, array<string, mixed>): string  $approvalCallback
     */
    public function __construct(
        private readonly TelegramClientInterface $client,
        private readonly GatewayMessageStore $messages,
        private readonly GatewayApprovalStore $approvals,
        private readonly string $routeKey,
        private string $sessionId,
        private readonly string $chatId,
        private readonly ?string $threadId,
        private readonly \Closure $approvalCallback,
        private readonly \Closure|Cancellation|null $cancellation = null,
    ) {}

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function initialize(): void {}

    public function renderIntro(bool $animated): void {}

    public function prompt(): string
    {
        return '';
    }

    public function showUserMessage(string $text): void {}

    public function setPhase(AgentPhase $phase): void
    {
        if ($phase === AgentPhase::Thinking) {
            $this->updateStatusMessage($this->composeStatusText());
        } elseif ($phase === AgentPhase::Tools) {
            $this->updateStatusMessage(
                $this->activeToolName !== null && $this->activeToolName !== ''
                    ? "Using tool: {$this->activeToolName}"
                    : 'Using tools…',
            );
        } elseif ($phase === AgentPhase::Idle) {
            $this->updateStatusMessage('Done');
        }
    }

    public function showThinking(): void {}

    public function clearThinking(): void {}

    public function showCompacting(): void
    {
        $this->appendNotice('Compacting context…');
    }

    public function clearCompacting(): void {}

    public function getCancellation(): ?Cancellation
    {
        if ($this->cancellation instanceof \Closure) {
            return ($this->cancellation)();
        }

        return $this->cancellation;
    }

    public function showReasoningContent(string $content): void {}

    public function streamChunk(string $text): void
    {
        $this->buffer .= $text;
        $this->flushBufferedText(false);
    }

    public function streamComplete(): void
    {
        $this->flushBufferedText(true);
        $this->deliverMediaAttachments();
        $this->updateStatusMessage('Done');
    }

    public function showError(string $message): void
    {
        $this->statusNotice = null;
        $this->updateStatusMessage("Error: {$message}");
    }

    public function showNotice(string $message): void
    {
        $this->appendNotice($message);
    }

    public function showMode(string $label, string $color = ''): void {}

    public function setPermissionMode(string $label, string $color): void {}

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void {}

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void {}

    public function consumeQueuedMessage(): ?string
    {
        return null;
    }

    public function setImmediateCommandHandler(?\Closure $handler): void {}

    public function teardown(): void {}

    public function showWelcome(): void {}

    public function setTaskStore(TaskStore $store): void {}

    public function refreshTaskBar(): void {}

    public function playTheogony(): void {}

    public function playPrometheus(): void {}

    public function playUnleash(): void {}

    public function playAnimation(AnsiAnimation $animation): void {}

    public function setSkillCompletions(array $completions): void {}

    public function showSettings(array $currentSettings): array
    {
        return [];
    }

    public function pickSession(array $items): ?string
    {
        return null;
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        return null;
    }

    public function askUser(string $question): string
    {
        return '';
    }

    public function askChoice(string $question, array $choices): string
    {
        return 'dismissed';
    }

    public function clearConversation(): void {}

    public function replayHistory(array $messages): void {}

    public function showSubagentStatus(array $stats): void {}

    public function clearSubagentStatus(): void {}

    public function showSubagentRunning(array $entries): void {}

    public function showSubagentSpawn(array $entries): void {}

    public function showSubagentBatch(array $entries): void {}

    public function refreshSubagentTree(array $tree): void {}

    public function setAgentTreeProvider(?\Closure $provider): void {}

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        $lines = [
            'Swarm summary',
            sprintf(
                'Total: %d · Done: %d · Running: %d · Queued: %d · Failed: %d',
                (int) ($summary['total'] ?? 0),
                (int) ($summary['done'] ?? 0),
                (int) ($summary['running'] ?? 0),
                (int) ($summary['queued'] ?? 0),
                (int) ($summary['failed'] ?? 0),
            ),
        ];

        $active = $summary['active'] ?? [];
        if (is_array($active) && $active !== []) {
            $lines[] = sprintf('Active agents: %d', count($active));
        }

        $this->appendNotice(implode("\n", $lines));
    }

    public function showToolCall(string $name, array $args): void
    {
        $this->activeToolName = $name;
        $this->updateStatusMessage("Preparing tool: {$name}");
    }

    public function showToolResult(string $name, string $output, bool $success): void {}

    public function askToolPermission(string $toolName, array $args): string
    {
        $approval = $this->approvals->createPending(
            platform: 'telegram',
            routeKey: $this->routeKey,
            sessionId: $this->sessionId,
            toolName: $toolName,
            arguments: $args,
            chatId: $this->chatId,
            threadId: $this->threadId,
            requestMessageId: $this->answerMessageId,
        );

        $lines = [
            "Approval required for `{$toolName}`.",
            'Use the buttons below or reply with /approve or /deny.',
        ];
        $this->client->sendMessage(
            $this->chatId,
            implode("\n", $lines),
            $this->threadId,
            replyMarkup: [
                'inline_keyboard' => [[
                    ['text' => 'Approve', 'callback_data' => 'ga:approve:'.$approval->id],
                    ['text' => 'Deny', 'callback_data' => 'ga:deny:'.$approval->id],
                ]],
            ],
        );

        $decision = ($this->approvalCallback)($approval->id, $toolName, $args);
        $this->approvals->resolve($approval->id, $decision === 'deny' ? 'denied' : 'approved');

        return $decision;
    }

    public function showAutoApproveIndicator(string $toolName): void {}

    public function showToolExecuting(string $name): void
    {
        $this->activeToolName = $name;
        $this->updateStatusMessage($name === 'concurrent' ? 'Running tools…' : "Using tool: {$name}");
    }

    public function updateToolExecuting(string $output): void
    {
        $output = trim($output);
        if ($output !== '') {
            $this->updateStatusMessage($output);
        }
    }

    public function clearToolExecuting(): void
    {
        $this->activeToolName = null;
    }

    private function ensureStatusMessage(string $text): void
    {
        if ($this->statusMessageId !== null) {
            return;
        }

        $message = $this->client->sendMessage($this->chatId, $this->limit($text), $this->threadId);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($messageId > 0) {
            $this->statusMessageId = $messageId;
            $this->messages->save('telegram', $this->routeKey, 'status', $this->chatId, $messageId, $this->threadId);
        }
    }

    private function ensureAnswerMessage(string $text): void
    {
        if ($this->answerMessageId !== null) {
            return;
        }

        $message = $this->client->sendMessage($this->chatId, $this->limit($text), $this->threadId);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($messageId > 0) {
            $this->answerMessageId = $messageId;
            $this->messages->save('telegram', $this->routeKey, 'response', $this->chatId, $messageId, $this->threadId);
        }
    }

    private function flushBufferedText(bool $force): void
    {
        if ($this->buffer === '') {
            return;
        }

        $display = $this->visibleText();
        if ($display === '') {
            return;
        }

        $now = microtime(true);
        if (! $force && ($now - $this->lastFlushAt) < 0.75) {
            return;
        }

        $this->ensureAnswerMessage($display);
        if ($this->answerMessageId !== null) {
            $this->client->editMessageText($this->chatId, $this->answerMessageId, $this->limit($display));
            $this->lastFlushAt = $now;
        }
    }

    private function appendNotice(string $message): void
    {
        $this->statusNotice = $message;
        $this->updateStatusMessage($this->composeStatusText());
    }

    private function limit(string $text): string
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return '…';
        }

        if (mb_strlen($normalized) <= 3900) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, 3897)).'...';
    }

    private function visibleText(): string
    {
        return $this->extractMediaPayload($this->buffer)['text'];
    }

    private function composeStatusText(): string
    {
        if ($this->statusNotice !== null && $this->statusNotice !== '') {
            return $this->placeholderText."\n\n".$this->statusNotice;
        }

        return $this->placeholderText;
    }

    private function updateStatusMessage(string $text): void
    {
        $this->ensureStatusMessage($text);

        if ($this->statusMessageId !== null) {
            $this->client->editMessageText($this->chatId, $this->statusMessageId, $this->limit($text));
        }
    }

    private function deliverMediaAttachments(): void
    {
        $payload = $this->extractMediaPayload($this->buffer);
        foreach ($payload['media'] as $item) {
            $path = $item['path'];
            if ($path === '' || ! is_file($path)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                $this->client->sendPhoto($this->chatId, $path, $this->threadId);

                continue;
            }

            if ($item['voice'] && in_array($extension, ['ogg', 'opus', 'mp3', 'm4a', 'wav'], true)) {
                $this->client->sendVoice($this->chatId, $path, $this->threadId);

                continue;
            }

            $this->client->sendDocument($this->chatId, $path, $this->threadId);
        }
    }

    /**
     * @return array{text: string, media: list<array{path: string, voice: bool}>}
     */
    private function extractMediaPayload(string $text): array
    {
        $voice = str_contains($text, '[[audio_as_voice]]');
        $cleaned = str_replace('[[audio_as_voice]]', '', $text);
        preg_match_all('/[`"\']?MEDIA:\s*([^\s`"\']+)[`"\']?/', $cleaned, $matches);

        $media = [];
        foreach ($matches[1] ?? [] as $path) {
            if (trim($path) === '') {
                continue;
            }

            $media[] = [
                'path' => trim($path),
                'voice' => $voice,
            ];
        }

        $display = preg_replace('/[`"\']?MEDIA:\s*([^\s`"\']+)[`"\']?/', '', $cleaned) ?? $cleaned;
        $display = preg_replace("/\n{3,}/", "\n\n", $display) ?? $display;

        return [
            'text' => trim($display),
            'media' => $media,
        ];
    }
}

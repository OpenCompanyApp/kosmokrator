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

    private const TELEGRAM_PARSE_MODE = 'HTML';

    /** @var list<string> */
    private array $answerSegments = [''];

    private string $placeholderText = 'Thinking…';

    private ?string $statusNotice = null;

    private ?string $activeToolName = null;

    private ?int $statusMessageId = null;

    /** @var list<int> */
    private array $answerMessageIds = [];

    private ?int $toolMessageId = null;

    private float $lastFlushAt = 0.0;

    private float $lastTypingAt = 0.0;

    private float $lastProgressNoticeAt = 0.0;

    private ?float $firstAnswerSentAt = null;

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
        private readonly ?string $requesterUserId = null,
        private readonly ?string $requesterUsername = null,
        private readonly ?int $replyToMessageId = null,
        private readonly string $replyToMode = 'first',
        private readonly int $freshFinalAfterSeconds = 60,
        private readonly int $progressNoticeIntervalSeconds = 60,
        private readonly bool $reactionsEnabled = false,
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
        $this->maybeSendTyping();
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
            $this->setInputReaction('👍');
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
        $this->maybeSendTyping();
        $this->answerSegments[array_key_last($this->answerSegments)] .= $text;
        $this->flushBufferedText(false);
    }

    public function streamComplete(): void
    {
        $this->flushBufferedText(true);
        $this->sendFreshFinalIfUseful();
        $this->deliverMediaAttachments();
        $this->updateStatusMessage('Done');
        $this->setInputReaction('👍');
    }

    public function showError(string $message): void
    {
        $this->statusNotice = null;
        $this->updateStatusMessage("Error: {$message}");
        $this->setInputReaction('👎');
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
        $this->maybeSendTyping();
        $this->startNewAnswerSegment();
        $this->toolMessageId = null;
        $this->toolMessageId = $this->sendOrEditToolMessage(
            $this->toolMessageId,
            TelegramTextFormatter::formatToolSummary($name, $args),
        );
        $this->updateStatusMessage("Preparing tool: {$name}");
        $this->maybeSendProgressNotice("Still working... preparing {$name}");
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $this->toolMessageId = $this->sendOrEditToolMessage(
            $this->toolMessageId,
            TelegramTextFormatter::formatToolResult($name, $output, $success),
        );
    }

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
            requestMessageId: $this->answerMessageIds === [] ? null : $this->answerMessageIds[array_key_last($this->answerMessageIds)],
            requesterUserId: $this->requesterUserId,
            requesterUsername: $this->requesterUsername,
        );

        $lines = [
            '<b>Approval required</b> <code>'.htmlspecialchars($toolName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>',
            'Use the buttons below or reply with /approve, /approve always, /approve guardian, /approve prometheus, or /deny.',
        ];
        $json = json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($json) && $json !== '') {
            if (mb_strlen($json) > 2400) {
                $json = mb_substr($json, 0, 2397).'...';
            }
            $lines[] = '<pre><code>'.htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code></pre>';
        }
        $this->sendFormattedMessage(
            $this->chatId,
            implode("\n", $lines),
            $this->threadId,
            replyMarkup: [
                'inline_keyboard' => [
                    [
                        ['text' => 'Approve', 'callback_data' => 'ga:allow:'.$approval->id],
                        ['text' => 'Always', 'callback_data' => 'ga:always:'.$approval->id],
                    ],
                    [
                        ['text' => 'Guardian', 'callback_data' => 'ga:guardian:'.$approval->id],
                        ['text' => 'Prometheus', 'callback_data' => 'ga:prometheus:'.$approval->id],
                    ],
                    [
                        ['text' => 'Deny', 'callback_data' => 'ga:deny:'.$approval->id],
                    ],
                ],
            ],
        );

        $decision = ($this->approvalCallback)($approval->id, $toolName, $args);

        return $decision;
    }

    public function showAutoApproveIndicator(string $toolName): void {}

    public function showToolExecuting(string $name): void
    {
        $this->activeToolName = $name;
        $this->maybeSendTyping();
        $this->toolMessageId = $this->sendOrEditToolMessage(
            $this->toolMessageId,
            '<b>Running tool</b> <code>'.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>',
        );
        $this->updateStatusMessage($name === 'concurrent' ? 'Running tools…' : "Using tool: {$name}");
    }

    public function updateToolExecuting(string $output): void
    {
        $output = trim($output);
        if ($output !== '') {
            $this->maybeSendTyping();
            $summary = $this->activeToolName !== null && $this->activeToolName !== ''
                ? '<b>Running tool</b> <code>'.htmlspecialchars($this->activeToolName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code>\n<pre><code>".htmlspecialchars($this->limitToolOutput($output), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code></pre>'
                : '<pre><code>'.htmlspecialchars($this->limitToolOutput($output), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code></pre>';
            $this->toolMessageId = $this->sendOrEditToolMessage($this->toolMessageId, $summary);
            $this->updateStatusMessage($output);
            $this->maybeSendProgressNotice($output);
        }
    }

    public function clearToolExecuting(): void
    {
        $this->activeToolName = null;
        $this->toolMessageId = null;
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

    private function flushBufferedText(bool $force): void
    {
        $display = $this->visibleText();
        if ($display === '') {
            return;
        }

        $now = microtime(true);
        if (! $force && ($now - $this->lastFlushAt) < 0.75) {
            return;
        }

        $chunks = $this->answerChunks();
        foreach ($chunks as $index => $chunk) {
            $messageId = $this->answerMessageIds[$index] ?? null;
            $formatted = TelegramTextFormatter::formatHtml($chunk);
            if ($messageId === null) {
                $message = $this->sendFormattedMessage(
                    $this->chatId,
                    $formatted,
                    $this->threadId,
                    $this->replyToMessageIdForChunk($index),
                );
                $messageId = (int) ($message['message_id'] ?? 0);
                if ($messageId > 0) {
                    $this->answerMessageIds[$index] = $messageId;
                    $this->firstAnswerSentAt ??= microtime(true);
                    if ($index === 0) {
                        $this->messages->save('telegram', $this->routeKey, 'response', $this->chatId, $messageId, $this->threadId);
                    }
                }
            } elseif ($messageId > 0) {
                $this->editFormattedMessage(
                    $this->chatId,
                    $messageId,
                    $formatted,
                );
            }
        }

        $this->lastFlushAt = $now;
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

        if (TelegramTextFormatter::utf16Length($normalized) <= 3900) {
            return $normalized;
        }

        return rtrim(TelegramTextFormatter::prefixWithinUtf16Limit($normalized, 3897)).'...';
    }

    private function limitToolOutput(string $text): string
    {
        $normalized = trim($text);
        if (TelegramTextFormatter::utf16Length($normalized) <= 2200) {
            return $normalized;
        }

        return rtrim(TelegramTextFormatter::prefixWithinUtf16Limit($normalized, 2197)).'...';
    }

    private function visibleText(): string
    {
        return implode("\n\n", array_values(array_filter(
            array_map(fn (string $segment): string => $this->extractMediaPayload($segment)['text'], $this->answerSegments),
            static fn (string $segment): bool => $segment !== '',
        )));
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
        $this->maybeSendTyping();
        $this->ensureStatusMessage($text);

        if ($this->statusMessageId !== null) {
            $this->client->editMessageText($this->chatId, $this->statusMessageId, $this->limit($text));
        }
    }

    private function deliverMediaAttachments(): void
    {
        foreach ($this->answerSegments as $segment) {
            $payload = $this->extractMediaPayload($segment);
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

    /**
     * @return list<string>
     */
    private function answerChunks(): array
    {
        $chunks = [];
        foreach ($this->answerSegments as $segment) {
            foreach (TelegramTextFormatter::splitPlainText($this->extractMediaPayload($segment)['text']) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    private function startNewAnswerSegment(): void
    {
        $current = $this->answerSegments[array_key_last($this->answerSegments)] ?? '';
        if (trim($this->extractMediaPayload($current)['text']) !== '') {
            $this->answerSegments[] = '';
        }
    }

    private function sendOrEditToolMessage(?int $messageId, string $html): int
    {
        if ($messageId !== null) {
            $this->editFormattedMessage(
                $this->chatId,
                $messageId,
                $html,
            );

            return $messageId;
        }

        $message = $this->sendFormattedMessage(
            $this->chatId,
            $html,
            $this->threadId,
        );

        return (int) ($message['message_id'] ?? 0);
    }

    private function sendFormattedMessage(
        string $chatId,
        string $html,
        ?string $threadId = null,
        ?int $replyToMessageId = null,
        ?array $replyMarkup = null,
    ): array {
        try {
            return $this->client->sendMessage(
                $chatId,
                $html,
                $threadId,
                $replyToMessageId,
                $replyMarkup,
                self::TELEGRAM_PARSE_MODE,
            );
        } catch (\Throwable) {
            return $this->client->sendMessage(
                $chatId,
                $this->limit(TelegramTextFormatter::stripHtml($html)),
                $threadId,
                $replyToMessageId,
                $replyMarkup,
            );
        }
    }

    private function editFormattedMessage(
        string $chatId,
        int $messageId,
        string $html,
        ?array $replyMarkup = null,
    ): array {
        try {
            return $this->client->editMessageText(
                $chatId,
                $messageId,
                $html,
                $replyMarkup,
                self::TELEGRAM_PARSE_MODE,
            );
        } catch (\Throwable) {
            return $this->client->editMessageText(
                $chatId,
                $messageId,
                $this->limit(TelegramTextFormatter::stripHtml($html)),
                $replyMarkup,
            );
        }
    }

    private function maybeSendTyping(): void
    {
        $now = microtime(true);
        if (($now - $this->lastTypingAt) < 4.0) {
            return;
        }

        $this->client->sendChatAction($this->chatId, 'typing', $this->threadId);
        $this->lastTypingAt = $now;
    }

    private function replyToMessageIdForChunk(int $index): ?int
    {
        if ($this->replyToMessageId === null || $this->replyToMode === 'off') {
            return null;
        }

        if ($this->replyToMode === 'first' && $index > 0) {
            return null;
        }

        return $this->replyToMessageId;
    }

    private function sendFreshFinalIfUseful(): void
    {
        if ($this->freshFinalAfterSeconds <= 0 || $this->firstAnswerSentAt === null) {
            return;
        }

        if ((microtime(true) - $this->firstAnswerSentAt) < $this->freshFinalAfterSeconds) {
            return;
        }

        foreach ($this->answerChunks() as $index => $chunk) {
            $this->sendFormattedMessage(
                $this->chatId,
                TelegramTextFormatter::formatHtml($chunk),
                $this->threadId,
                $this->replyToMessageIdForChunk($index),
            );
        }
    }

    private function maybeSendProgressNotice(string $message): void
    {
        if ($this->progressNoticeIntervalSeconds <= 0) {
            return;
        }

        $now = microtime(true);
        if ($this->lastProgressNoticeAt === 0.0) {
            $this->lastProgressNoticeAt = $now;

            return;
        }

        if (($now - $this->lastProgressNoticeAt) < $this->progressNoticeIntervalSeconds) {
            return;
        }

        $elapsed = $this->firstAnswerSentAt !== null
            ? max(1, (int) floor($now - $this->firstAnswerSentAt))
            : 0;
        $prefix = $elapsed > 0 ? "Still working... {$elapsed}s elapsed" : 'Still working...';
        $this->updateStatusMessage($prefix."\n".$this->limit($message));
        $this->lastProgressNoticeAt = $now;
    }

    private function setInputReaction(string $emoji): void
    {
        if (! $this->reactionsEnabled || $this->replyToMessageId === null) {
            return;
        }

        try {
            $this->client->setMessageReaction($this->chatId, $this->replyToMessageId, $emoji);
        } catch (\Throwable) {
            // Reactions are best-effort; older chats/bots may not support them.
        }
    }
}

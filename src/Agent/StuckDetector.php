<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\ToolCallMapper;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Detects repetitive tool call patterns in headless agent loops.
 *
 * Maintains a rolling window of tool call signatures and escalates through
 * three stages: nudge → final notice → force return. Used exclusively by
 * AgentLoop::runHeadless() for subagent stuck detection.
 */
final class StuckDetector
{
    /** @var string[] Rolling window of tool call signatures */
    private array $toolCallWindow = [];

    /** @var string[] Rolling window of recent tool names */
    private array $toolNameWindow = [];

    /** @var string[] Rolling window of repeated content chunks */
    private array $contentWindow = [];

    /** @var string[] Rolling window of reasoning chunks */
    private array $thinkingWindow = [];

    private int $stuckEscalation = 0;

    private int $turnsSinceEscalation = 0;

    /** Number of consecutive non-stuck turns required to reset escalation (cool-down). */
    private int $cooldownCounter = 0;

    private ?string $lastReason = null;

    private ?string $lastToolName = null;

    private int $sameToolNameStreak = 0;

    private bool $hasSeenNonReadTool = false;

    /**
     * @param  int  $windowSize  Number of recent tool call signatures to track
     * @param  int  $repetitionThreshold  Times the same signature must appear to trigger stuck detection
     * @param  int  $cooldownThreshold  Consecutive diverse turns before resetting escalation
     */
    public function __construct(
        private readonly int $windowSize = 8,
        private readonly int $repetitionThreshold = 3,
        private readonly int $cooldownThreshold = 2,
    ) {}

    /**
     * Check tool calls for repetitive patterns and return escalation state.
     *
     * @param  ToolCall[]  $toolCalls  Tool calls from the current round
     * @return string 'ok'|'nudge'|'final_notice'|'force_return'
     */
    public function check(array $toolCalls): string
    {
        $isStuck = false;
        $reason = null;

        // Build signatures and add to rolling window. Evaluate after each call
        // so a large multi-tool batch cannot hide a repeated prefix by pushing
        // it out of the final sliced window.
        foreach ($toolCalls as $tc) {
            $latestSig = $this->signature($tc);
            $this->toolCallWindow[] = $latestSig;
            $this->toolCallWindow = array_slice($this->toolCallWindow, -$this->windowSize);
            $this->trackToolName($tc->name);

            $counts = array_count_values($this->toolCallWindow);
            if (($counts[$latestSig] ?? 0) >= $this->repetitionThreshold) {
                $isStuck = true;
                $reason ??= 'repeated tool call arguments';
            }
        }

        $counts = array_count_values($this->toolCallWindow);
        $maxCount = $counts !== [] ? max($counts) : 0;
        if (! $isStuck && $this->sameToolNameStreak >= max(10, $this->repetitionThreshold + 7)) {
            $isStuck = true;
            $reason = 'same tool name repeated with changing arguments';
        }

        if (! $isStuck && $this->hasSeenNonReadTool && $this->readLikeToolChurnDetected()) {
            $isStuck = true;
            $reason = 'read/search churn without progress';
        }

        if (! $isStuck && $this->repeatedChunkDetected($this->contentWindow, 5)) {
            $isStuck = true;
            $reason = 'repeated streamed content';
        }

        if (! $isStuck && $this->repeatedChunkDetected($this->thinkingWindow, 3)) {
            $isStuck = true;
            $reason = 'repeated reasoning content';
        }

        if (! $isStuck) {
            if ($this->stuckEscalation > 0 && $maxCount < $this->repetitionThreshold) {
                $this->cooldownCounter++;
                if ($this->cooldownCounter >= $this->cooldownThreshold) {
                    $this->stuckEscalation = 0;
                    $this->turnsSinceEscalation = 0;
                    $this->cooldownCounter = 0;
                }
            }

            return 'ok';
        }

        // Still stuck — reset cooldown
        $this->cooldownCounter = 0;
        $this->lastReason = $reason;

        // First detection → nudge
        if ($this->stuckEscalation === 0) {
            $this->stuckEscalation = 1;
            $this->turnsSinceEscalation = 0;

            return 'nudge';
        }

        $this->turnsSinceEscalation++;

        // Second escalation after 2 turns
        if ($this->stuckEscalation === 1 && $this->turnsSinceEscalation >= 2) {
            $this->stuckEscalation = 2;
            $this->turnsSinceEscalation = 0;

            return 'final_notice';
        }

        // Force return after 2 more turns
        if ($this->stuckEscalation >= 2 && $this->turnsSinceEscalation >= 2) {
            return 'force_return';
        }

        return 'ok';
    }

    /**
     * Reset all state for a new headless run.
     */
    public function reset(): void
    {
        $this->toolCallWindow = [];
        $this->toolNameWindow = [];
        $this->contentWindow = [];
        $this->thinkingWindow = [];
        $this->stuckEscalation = 0;
        $this->turnsSinceEscalation = 0;
        $this->cooldownCounter = 0;
        $this->lastReason = null;
        $this->lastToolName = null;
        $this->sameToolNameStreak = 0;
        $this->hasSeenNonReadTool = false;
    }

    public function observeText(string $delta): void
    {
        $this->trackContentChunk($delta, $this->contentWindow);
    }

    public function observeThinking(string $delta): void
    {
        $this->trackContentChunk($delta, $this->thinkingWindow);
    }

    /**
     * Extract the last non-empty assistant text from history.
     *
     * Used by the force-return path to return the agent's last meaningful output.
     */
    public function extractLastAssistantText(ConversationHistory $history): string
    {
        $messages = $history->messages();

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->content !== '') {
                return $messages[$i]->content;
            }
        }

        return '(no response generated)';
    }

    /**
     * Get the current escalation level.
     */
    public function getEscalation(): int
    {
        return $this->stuckEscalation;
    }

    /**
     * Get the current tool call window.
     *
     * @return string[]
     */
    public function getWindow(): array
    {
        return $this->toolCallWindow;
    }

    public function getReason(): ?string
    {
        return $this->lastReason;
    }

    private function signature(ToolCall $toolCall): string
    {
        $parts = [];
        foreach (ToolCallMapper::safeArguments($toolCall) as $key => $value) {
            $key = (string) $key;
            $parts[$key] = $this->isPathLikeArg($key) ? '*' : $this->signatureValue($value);
        }
        ksort($parts);

        return $toolCall->name.':'.json_encode($parts, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function isPathLikeArg(string $key): bool
    {
        return in_array($key, ['path', 'file', 'filename', 'cwd', 'dir', 'directory'], true)
            || str_ends_with($key, '_path')
            || str_ends_with($key, '_file');
    }

    private function signatureValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return mb_substr((string) $value, 0, 120);
        }

        if (is_array($value)) {
            return 'array:'.implode(',', array_map('strval', array_keys($value)));
        }

        return get_debug_type($value);
    }

    private function trackToolName(string $name): void
    {
        $this->toolNameWindow[] = $name;
        $this->toolNameWindow = array_slice($this->toolNameWindow, -15);

        if ($this->lastToolName === $name) {
            $this->sameToolNameStreak++;
        } else {
            $this->lastToolName = $name;
            $this->sameToolNameStreak = 1;
        }

        if (! $this->hasSeenNonReadTool && ! $this->isReadLikeTool($name)) {
            $this->hasSeenNonReadTool = true;
        }
    }

    private function readLikeToolChurnDetected(): bool
    {
        if (count($this->toolNameWindow) < 10) {
            return false;
        }

        $readLike = 0;
        foreach ($this->toolNameWindow as $name) {
            if ($this->isReadLikeTool($name)) {
                $readLike++;
            }
        }

        return $readLike >= 8;
    }

    private function isReadLikeTool(string $name): bool
    {
        return in_array($name, ['file_read', 'glob', 'grep', 'web_search', 'web_fetch', 'session_search', 'session_read', 'memory_search', 'lua_list_docs', 'lua_search_docs', 'lua_read_doc'], true)
            || str_starts_with($name, 'read_')
            || str_starts_with($name, 'list_')
            || str_contains($name, 'search');
    }

    /**
     * @param  string[]  $window
     */
    private function repeatedChunkDetected(array $window, int $threshold): bool
    {
        if (count($window) < $threshold) {
            return false;
        }

        $counts = array_count_values($window);

        return max($counts) >= $threshold;
    }

    /**
     * @param  string[]  $window
     */
    private function trackContentChunk(string $delta, array &$window): void
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $delta));
        if ($normalized === '' || str_contains($normalized, '```') || mb_strlen($normalized) < 24) {
            return;
        }

        foreach (str_split($normalized, 80) as $chunk) {
            $chunk = trim($chunk);
            if (mb_strlen($chunk) < 24) {
                continue;
            }
            $window[] = md5(mb_substr($chunk, 0, 160));
        }

        $window = array_slice($window, -40);
    }
}

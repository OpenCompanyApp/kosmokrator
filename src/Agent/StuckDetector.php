<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

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

    private int $stuckEscalation = 0;

    private int $turnsSinceEscalation = 0;

    public function __construct(
        private readonly int $windowSize = 8,
        private readonly int $repetitionThreshold = 3,
    ) {}

    /**
     * Check tool calls for repetitive patterns and return escalation state.
     *
     * @param  ToolCall[]  $toolCalls  Tool calls from the current round
     * @return string 'ok'|'nudge'|'final_notice'|'force_return'
     */
    public function check(array $toolCalls): string
    {
        // Build signatures and add to rolling window
        foreach ($toolCalls as $tc) {
            $this->toolCallWindow[] = $tc->name.':'.md5(json_encode($tc->arguments()));
        }
        $this->toolCallWindow = array_slice($this->toolCallWindow, -$this->windowSize);

        // Check if the latest call's signature appears N+ times in the window
        $latestSig = end($this->toolCallWindow);
        $latestCount = count(array_filter($this->toolCallWindow, fn ($s) => $s === $latestSig));
        $isStuck = $latestCount >= $this->repetitionThreshold;

        if (! $isStuck) {
            if ($this->stuckEscalation > 0) {
                $this->stuckEscalation = 0;
                $this->turnsSinceEscalation = 0;
            }

            return 'ok';
        }

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
        $this->stuckEscalation = 0;
        $this->turnsSinceEscalation = 0;
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
}

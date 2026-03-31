<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ContextPruner
{
    private const DEFAULT_PROTECT_TOKENS = 40_000;

    private const DEFAULT_MIN_SAVINGS = 20_000;

    public const PLACEHOLDER = '[Old tool result content cleared]';

    public function __construct(
        private int $protectTokens = self::DEFAULT_PROTECT_TOKENS,
        private int $minSavings = self::DEFAULT_MIN_SAVINGS,
    ) {}

    public function setProtectTokens(int $tokens): void
    {
        $this->protectTokens = $tokens;
    }

    public function setMinSavings(int $tokens): void
    {
        $this->minSavings = $tokens;
    }

    public function getProtectTokens(): int
    {
        return $this->protectTokens;
    }

    public function getMinSavings(): int
    {
        return $this->minSavings;
    }

    /**
     * Prune old tool results from conversation history.
     * Returns the estimated number of tokens saved.
     */
    public function prune(ConversationHistory $history): int
    {
        $messages = $history->messages();
        $count = count($messages);

        if ($count < 4) {
            return 0;
        }

        // Find boundary: protect last 2 user turns
        $protectFrom = $this->findProtectBoundary($messages);
        if ($protectFrom <= 0) {
            return 0;
        }

        // Walk backwards from boundary, accumulate tool result tokens.
        // Protect $protectTokens worth of the most recent tool output (before boundary).
        // Mark everything older for pruning.
        $tokensSeen = 0;
        $toPrune = [];

        for ($i = $protectFrom - 1; $i >= 0; $i--) {
            // Stop at a compaction summary (SystemMessage)
            if ($messages[$i] instanceof SystemMessage) {
                break;
            }

            if (! $messages[$i] instanceof ToolResultMessage) {
                continue;
            }

            foreach ($messages[$i]->toolResults as $rIdx => $result) {
                if (! is_string($result->result)) {
                    continue;
                }
                if ($result->result === self::PLACEHOLDER || str_starts_with($result->result, '[Superseded')) {
                    continue;
                }

                $tokens = TokenEstimator::estimate($result->result);
                $tokensSeen += $tokens;

                if ($tokensSeen > $this->protectTokens) {
                    $toPrune[] = [$i, $rIdx, $tokens];
                }
            }
        }

        // Check if savings meet minimum threshold
        $totalSavings = array_sum(array_column($toPrune, 2));

        if ($totalSavings < $this->minSavings) {
            return 0;
        }

        $history->pruneToolResults($toPrune, self::PLACEHOLDER);

        return $totalSavings;
    }

    /**
     * Find the message index where protection starts (the 2nd user turn from the end).
     *
     * @param  array<int, Message>  $messages
     */
    private function findProtectBoundary(array $messages): int
    {
        $userTurns = 0;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof UserMessage) {
                $userTurns++;
                if ($userTurns >= 2) {
                    return $i;
                }
            }
        }

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Fast, non-LLM context pruning: replaces old tool result content with lightweight placeholders
 * to reclaim tokens without the cost of a full LLM compaction.
 *
 * Protects the last 2 user turns and their surrounding context. Ranks candidates by an
 * importance score (tool type weight + whether the result is referenced by subsequent assistant messages).
 * Used by ContextManager as a first-pass before LLM-based compaction.
 */
class ContextPruner
{
    private const DEFAULT_PROTECT_TOKENS = 40_000;

    private const DEFAULT_MIN_SAVINGS = 20_000;

    public const PLACEHOLDER = '[Old tool result content cleared]';

    /** Tool types weighted by typical output size — higher weight = less important to keep. */
    private const TOOL_WEIGHTS = [
        'bash' => 70,
        'shell_read' => 65,
        'file_read' => 30,
        'grep' => 50,
        'glob' => 10,
        'web_fetch' => 55,
        'web_search' => 40,
        'file_edit' => 20,
        'file_write' => 20,
    ];

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
     * Scan old tool results, score them by importance, and replace the least important
     * with placeholders. Only prunes if estimated savings exceed minSavings.
     *
     * @param  ConversationHistory  $history  The conversation to prune (mutated in place)
     * @return int Estimated tokens reclaimed, or 0 if pruning was skipped
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
        $candidates = [];

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
                if (
                    $result->result === self::PLACEHOLDER
                    || str_starts_with($result->result, '[Superseded')
                    || str_starts_with($result->result, '[Old ')
                ) {
                    continue;
                }

                $tokens = TokenEstimator::estimate($result->result);
                $tokensSeen += $tokens;

                if ($tokensSeen > $this->protectTokens) {
                    $candidates[] = [
                        'msgIdx' => $i,
                        'resultIdx' => $rIdx,
                        'tokens' => $tokens,
                        'placeholder' => $this->placeholderFor($result->toolName, $result->args),
                        'score' => $this->importanceScore($messages, $i, $protectFrom, $result->toolName, $result->args, $result->result),
                    ];
                }
            }
        }

        usort($candidates, fn (array $a, array $b): int => $a['score'] <=> $b['score']);

        $totalSavings = array_sum(array_column($candidates, 'tokens'));

        if ($totalSavings < $this->minSavings) {
            return 0;
        }

        $history->pruneToolResultsWithPlaceholders(array_map(
            fn (array $candidate): array => [$candidate['msgIdx'], $candidate['resultIdx'], $candidate['placeholder']],
            $candidates,
        ));

        return $totalSavings;
    }

    /**
     * Find the message index where protection starts (the 2nd user turn from the end).
     * All tool results before this index are candidates for pruning.
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

    /**
     * Score a tool result's importance: higher = less likely to be pruned.
     * Considers tool type weight, whether the result is referenced in later assistant messages,
     * and content overlap with reasoning patterns.
     *
     * @param  array<int, Message>  $messages
     */
    private function importanceScore(array $messages, int $messageIndex, int $protectFrom, string $toolName, array $args, string $result): int
    {
        $score = self::TOOL_WEIGHTS[$toolName] ?? 25;
        $path = (string) ($args['path'] ?? '');
        $basename = $path !== '' ? basename($path) : '';

        for ($i = $messageIndex + 1; $i < $protectFrom; $i++) {
            if (! isset($messages[$i])) {
                continue;
            }

            $message = $messages[$i];
            if ($message instanceof AssistantMessage) {
                $content = mb_strtolower($message->content);
                if ($basename !== '' && str_contains($content, mb_strtolower($basename))) {
                    $score += 15;
                }
                if (str_contains($content, 'based on') || str_contains($content, "i'll use") || str_contains($content, 'the issue is')) {
                    $score += 10;
                }
                if ($result !== '' && mb_strlen($result) > 20 && str_contains($content, mb_strtolower(mb_substr($result, 0, 20)))) {
                    $score += 15;
                }
            }
        }

        return $score;
    }

    /**
     * Generate a context-aware placeholder string for a pruned tool result.
     */
    private function placeholderFor(string $toolName, array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        if ($toolName === 'file_read' && $path !== '') {
            return "[Old file_read output cleared for {$path}]";
        }
        if ($toolName === 'grep' && $path !== '') {
            return "[Old grep output cleared for {$path}]";
        }
        if ($toolName === 'glob') {
            return '[Old glob output cleared]';
        }
        if ($toolName === 'bash' || $toolName === 'shell_read') {
            return '[Old shell output cleared; inspect truncation storage or rerun targeted commands if needed]';
        }

        return self::PLACEHOLDER;
    }
}

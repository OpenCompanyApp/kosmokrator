<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolResult;

class ToolResultDeduplicator
{
    private const EXACT_SUPERSEDE = '[Superseded — identical result returned by later call]';

    private const STALE_SUPERSEDE = '[Superseded — file was re-read after modification]';

    private const SUBSET_SUPERSEDE = '[Superseded — content included in later file_read of %s]';

    /** Tools eligible for deduplication (tier 1 exact match + tier 3 subsumption) */
    private const READ_TOOLS = ['file_read', 'grep', 'glob'];

    /** Tools tracked for write positions (tier 2 stale-after-edit detection) */
    private const WRITE_TOOLS = ['file_edit', 'file_write'];

    /** All tools the deduplicator cares about */
    private const ALL_TOOLS = ['file_read', 'file_edit', 'file_write', 'grep', 'glob'];

    /**
     * Replace superseded tool results with compact references.
     * Returns the number of results superseded.
     */
    public function deduplicate(ConversationHistory $history): int
    {
        $messages = $history->messages();
        $count = count($messages);

        if ($count < 2) {
            return 0;
        }

        // Build indexes by scanning backwards (latest occurrence first)
        $latestBySig = [];    // signature → [msgIdx, resultIdx]
        $latestReads = [];    // normalizedPath → [msgIdx, resultIdx]
        $writtenPaths = [];   // normalizedPath → [msgIdx, ...] (all write positions)

        for ($i = $count - 1; $i >= 0; $i--) {
            if (! $messages[$i] instanceof ToolResultMessage) {
                continue;
            }

            // Iterate results in reverse so the LAST result in a batch is recorded as "latest"
            $results = $messages[$i]->toolResults;
            for ($rIdx = count($results) - 1; $rIdx >= 0; $rIdx--) {
                $result = $results[$rIdx];

                if (! in_array($result->toolName, self::ALL_TOOLS, true)) {
                    continue;
                }
                if ($this->isSuperseded($result->result)) {
                    continue;
                }

                // Signature index (tier 1) — read tools only, includes result hash
                if (in_array($result->toolName, self::READ_TOOLS, true)) {
                    $sig = $this->signature($result);
                    if (! isset($latestBySig[$sig])) {
                        $latestBySig[$sig] = [$i, $rIdx];
                    }
                }

                // Latest file_read per path (tier 2 + 3)
                if ($result->toolName === 'file_read') {
                    $path = $this->normalizePath($result->args['path'] ?? '');
                    if ($path !== '' && ! isset($latestReads[$path])) {
                        $latestReads[$path] = [$i, $rIdx];
                    }
                }

                // Track write positions per path (tier 2)
                if (in_array($result->toolName, self::WRITE_TOOLS, true)) {
                    $path = $this->normalizePath($result->args['path'] ?? '');
                    if ($path !== '') {
                        $writtenPaths[$path][] = $i;
                    }
                }
            }
        }

        // Scan forward: mark older duplicates for superseding
        $superseded = 0;

        for ($i = 0; $i < $count; $i++) {
            if (! $messages[$i] instanceof ToolResultMessage) {
                continue;
            }

            foreach ($messages[$i]->toolResults as $rIdx => $result) {
                if (! in_array($result->toolName, self::ALL_TOOLS, true)) {
                    continue;
                }
                if ($this->isSuperseded($result->result)) {
                    continue;
                }

                // Tier 1: Exact duplicate — same tool + same args + same result (read tools only)
                $sig = in_array($result->toolName, self::READ_TOOLS, true) ? $this->signature($result) : null;
                if ($sig !== null && isset($latestBySig[$sig]) && $latestBySig[$sig] !== [$i, $rIdx]) {
                    $history->supersedeToolResult($i, $rIdx, self::EXACT_SUPERSEDE);
                    $superseded++;

                    continue;
                }

                // Tier 2: Stale file_read — supersede if the file was edited/written between this read and a later re-read
                if ($result->toolName === 'file_read') {
                    $path = $this->normalizePath($result->args['path'] ?? '');
                    if ($path !== '' && isset($latestReads[$path]) && $latestReads[$path] !== [$i, $rIdx]) {
                        $latestReadIdx = $latestReads[$path][0];
                        if ($this->hasWriteBetween($writtenPaths[$path] ?? [], $i, $latestReadIdx)) {
                            $history->supersedeToolResult($i, $rIdx, self::STALE_SUPERSEDE);
                            $superseded++;

                            continue;
                        }
                    }
                }

                // Tier 3: Grep on specific file subsumed by later file_read
                if ($result->toolName === 'grep') {
                    $grepPath = $this->normalizePath($result->args['path'] ?? '');
                    if ($grepPath !== '' && ! is_dir($grepPath) && isset($latestReads[$grepPath]) && $latestReads[$grepPath][0] > $i) {
                        $placeholder = sprintf(self::SUBSET_SUPERSEDE, basename($grepPath));
                        $history->supersedeToolResult($i, $rIdx, $placeholder);
                        $superseded++;

                        continue;
                    }
                }
            }
        }

        return $superseded;
    }

    private function signature(ToolResult $result): string
    {
        $args = $result->args;
        ksort($args);
        $resultStr = is_string($result->result) ? $result->result : json_encode($result->result);

        return $result->toolName . ':' . json_encode($args, JSON_THROW_ON_ERROR) . ':' . md5($resultStr);
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $resolved = realpath($path);

        return $resolved !== false ? $resolved : $path;
    }

    /**
     * Check if any write (file_edit/file_write) to the given path occurred between two message indexes.
     *
     * @param int[] $writePositions Message indexes where writes occurred for this path
     */
    private function hasWriteBetween(array $writePositions, int $afterIdx, int $beforeIdx): bool
    {
        foreach ($writePositions as $writeIdx) {
            if ($writeIdx > $afterIdx && $writeIdx < $beforeIdx) {
                return true;
            }
        }

        return false;
    }

    private function isSuperseded(int|float|string|array|null $result): bool
    {
        if (! is_string($result)) {
            return false;
        }

        return str_starts_with($result, '[Superseded')
            || $result === ContextPruner::PLACEHOLDER;
    }
}

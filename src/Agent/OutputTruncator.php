<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\IO\AtomicFileWriter;

/**
 * Truncates oversized tool output before it enters the conversation context.
 *
 * When tool output exceeds configured line/byte limits, the full content is
 * persisted to disk and replaced with a truncated version plus a file path.
 * This keeps ConversationHistory lean while ensuring nothing is lost.
 *
 * @see ConversationHistory::pruneToolResults() For post-hoc pruning of already-stored results
 */
class OutputTruncator
{
    private const DEFAULT_MAX_LINES = 2000;

    private const DEFAULT_MAX_BYTES = 50_000;

    /** Default file retention: 7 days. */
    private const DEFAULT_RETENTION_SECONDS = 604800;

    /**
     * @param  int  $maxLines  Maximum lines allowed in truncated output
     * @param  int  $maxBytes  Maximum bytes allowed in truncated output
     * @param  string|null  $storagePath  Directory for full-output files (defaults to ~/.kosmo/data/truncations)
     * @param  int  $retentionSeconds  How long full-output files are kept before cleanup
     */
    public function __construct(
        private int $maxLines = self::DEFAULT_MAX_LINES,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?string $storagePath = null,
        private int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS,
    ) {
        if ($this->storagePath === null) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');
            $this->storagePath = $home.'/.kosmo/data/truncations';
        }

        // Purge stale files on every instantiation to prevent unbounded disk growth
        $this->cleanupOldFiles($this->retentionSeconds);
    }

    /**
     * Delete truncation files older than the given age.
     *
     * @param  int  $maxAgeSeconds  Maximum age in seconds; files older than this are removed
     */
    public function cleanupOldFiles(int $maxAgeSeconds = 86400): void
    {
        if (! is_dir($this->storagePath)) {
            return;
        }

        $cutoff = time() - $maxAgeSeconds;
        $files = glob($this->storagePath.'/tool_*.txt');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Truncate tool output if it exceeds configured line or byte limits.
     * Saves the full output to disk first so nothing is lost.
     *
     * @param  string  $output  Raw tool output string
     * @param  string  $toolCallId  Identifier for the tool call, used as the storage filename
     * @return string Original output if within limits, otherwise truncated version with a disk reference
     */
    public function truncate(string $output, string $toolCallId): string
    {
        $lines = substr_count($output, "\n") + 1;
        $bytes = strlen($output);

        if ($lines <= $this->maxLines && $bytes <= $this->maxBytes) {
            return $output;
        }

        // Persist full output before truncating so the agent can re-read it later
        $fullPath = $this->saveFull($output, $toolCallId);

        if ($lines > $this->maxLines) {
            $output = $this->firstLines($output, $this->maxLines);
        }

        // Byte check runs after line truncation to catch oversized single lines
        if (strlen($output) > $this->maxBytes) {
            $output = mb_strcut($output, 0, $this->maxBytes, 'UTF-8');
        }

        return $output."\n\n[truncated - full output saved to {$fullPath}; inspect with targeted grep/file_read rather than pasting it back into context]";
    }

    private function firstLines(string $output, int $maxLines): string
    {
        if ($maxLines <= 0) {
            return '';
        }

        $offset = 0;
        for ($line = 1; $line < $maxLines; $line++) {
            $next = strpos($output, "\n", $offset);
            if ($next === false) {
                return $output;
            }

            $offset = $next + 1;
        }

        $end = strpos($output, "\n", $offset);

        return $end === false ? $output : substr($output, 0, $end);
    }

    /**
     * Persist the full tool output to a file on disk.
     *
     * @param  string  $output  Full tool output to save
     * @param  string  $toolCallId  Used to derive a safe filename
     * @return string Absolute path to the saved file
     */
    private function saveFull(string $output, string $toolCallId): string
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        // Sanitize the ID to produce a filesystem-safe filename
        $safeId = $toolCallId !== '' ? $toolCallId : uniqid('anon_');
        $path = $this->storagePath.'/tool_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $safeId).'.txt';

        try {
            AtomicFileWriter::write($path, $output);
        } catch (\RuntimeException) {
            return '[error: could not save full output to disk]';
        }

        return $path;
    }
}

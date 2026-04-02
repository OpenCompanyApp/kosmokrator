<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

class OutputTruncator
{
    private const DEFAULT_MAX_LINES = 2000;

    private const DEFAULT_MAX_BYTES = 50_000;

    private const DEFAULT_RETENTION_SECONDS = 604800;

    public function __construct(
        private int $maxLines = self::DEFAULT_MAX_LINES,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private ?string $storagePath = null,
        private int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS,
    ) {
        if ($this->storagePath === null) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');
            $this->storagePath = $home.'/.kosmokrator/data/truncations';
        }

        $this->cleanupOldFiles($this->retentionSeconds);
    }

    /**
     * Delete truncation files older than the given age.
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
     * Truncate tool output if it exceeds limits.
     * Saves full output to disk when truncated.
     */
    public function truncate(string $output, string $toolCallId): string
    {
        $lines = substr_count($output, "\n") + 1;
        $bytes = strlen($output);

        if ($lines <= $this->maxLines && $bytes <= $this->maxBytes) {
            return $output;
        }

        $fullPath = $this->saveFull($output, $toolCallId);

        if ($lines > $this->maxLines) {
            $output = implode("\n", array_slice(explode("\n", $output), 0, $this->maxLines));
        }

        if (strlen($output) > $this->maxBytes) {
            $output = substr($output, 0, $this->maxBytes);
        }

        return $output."\n\n[truncated - full output saved to {$fullPath}; inspect with targeted grep/file_read rather than pasting it back into context]";
    }

    private function saveFull(string $output, string $toolCallId): string
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $safeId = $toolCallId !== '' ? $toolCallId : uniqid('anon_');
        $path = $this->storagePath.'/tool_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $safeId).'.txt';
        file_put_contents($path, $output);

        return $path;
    }
}

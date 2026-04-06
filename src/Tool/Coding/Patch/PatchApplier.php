<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding\Patch;

use Kosmokrator\Exception\FileOperationException;
use Kosmokrator\IO\AtomicFileWriter;
use Kosmokrator\Tool\Permission\Check\ProjectBoundaryCheck;
use Kosmokrator\Tool\Permission\PathResolver;
use Kosmokrator\Tool\Permission\PermissionRule;

/**
 * Applies parsed patch operations to the filesystem — the execution backend for the apply_patch tool.
 *
 * Validates paths against blocked patterns, then creates/updates/deletes files
 * using a custom unified-diff hunk replacement strategy.
 */
final class PatchApplier
{
    private readonly ?string $resolvedProjectRoot;

    /** @var string[] */
    private readonly array $resolvedAllowedPaths;

    /**
     * @param  string[]  $blockedPaths  Glob patterns for paths that must not be touched
     * @param  string|null  $projectRoot  Project root for boundary enforcement (null = no boundary)
     * @param  string[]  $allowedPaths  Pre-resolved allowed path prefixes for boundary checking
     */
    public function __construct(
        private readonly array $blockedPaths = [],
        ?string $projectRoot = null,
        array $allowedPaths = [],
    ) {
        // Resolve symlinks (e.g. /var → /private/var on macOS) to match PathResolver behavior
        $this->resolvedProjectRoot = $projectRoot !== null ? (realpath($projectRoot) ?: $projectRoot) : null;
        $this->resolvedAllowedPaths = array_values(array_filter(
            array_map(fn (string $p) => realpath($p) ?: null, $allowedPaths),
        ));
    }

    /**
     * Execute a batch of patch operations and return a summary counts.
     *
     * @param  PatchOperation[]  $operations
     * @return array{added:int, updated:int, deleted:int, moved:int}
     */
    public function apply(array $operations): array
    {
        $this->assertPathsAllowed($operations);

        $summary = ['added' => 0, 'updated' => 0, 'deleted' => 0, 'moved' => 0];

        foreach ($operations as $operation) {
            match ($operation->kind) {
                'add' => $summary['added'] += $this->applyAdd($operation),
                'update' => $this->applyUpdate($operation, $summary),
                'delete' => $summary['deleted'] += $this->applyDelete($operation),
                default => throw new FileOperationException("Unsupported patch operation '{$operation->kind}'."),
            };
        }

        return $summary;
    }

    /**
     * Verify every operation targets an allowed (non-blocked) path.
     *
     * @param  PatchOperation[]  $operations
     */
    private function assertPathsAllowed(array $operations): void
    {
        foreach ($operations as $operation) {
            $this->assertPathAllowed($operation->path);
            if ($operation->moveTo !== null) {
                $this->assertPathAllowed($operation->moveTo);
            }
        }
    }

    /**
     * Reject a path if it matches any blocked glob pattern (checked against both raw and resolved paths).
     */
    private function assertPathAllowed(string $path): void
    {
        $path = trim($path);
        if ($path === '' || $path === '.') {
            throw new FileOperationException('Patch contains an invalid file path.');
        }

        // Check both the raw path and any symlink-resolved variant
        $candidates = [$path];
        $resolved = PathResolver::resolve($path);
        if ($resolved !== null && $resolved !== $path) {
            $candidates[] = $resolved;
        }

        foreach ($this->blockedPaths as $pattern) {
            foreach ($candidates as $candidate) {
                $basename = basename($candidate);
                if (PermissionRule::matchesGlob($candidate, $pattern)
                    || PermissionRule::matchesGlob($basename, $pattern)) {
                    throw new FileOperationException("Cannot access '{$path}' — matches blocked pattern '{$pattern}'.");
                }
            }
        }

        // Project boundary enforcement
        if ($this->resolvedProjectRoot !== null
            && ! ProjectBoundaryCheck::pathWithinBoundary($path, $this->resolvedProjectRoot, $this->resolvedAllowedPaths)) {
            throw new FileOperationException("Cannot access '{$path}' — path is outside the project boundary.");
        }
    }

    /** Create a new file with the given body content. */
    private function applyAdd(PatchOperation $operation): int
    {
        if (file_exists($operation->path)) {
            throw new FileOperationException("Cannot add file '{$operation->path}' because it already exists.");
        }

        $this->ensureParentDirectory($operation->path);
        $content = implode("\n", $operation->bodyLines);

        try {
            AtomicFileWriter::write($operation->path, $content);
        } catch (\RuntimeException $e) {
            throw new FileOperationException("Failed to write file '{$operation->path}'.");
        }

        return 1;
    }

    /**
     * Apply update hunks to an existing file, optionally moving it to a new path.
     *
     * @param  array{added:int, updated:int, deleted:int, moved:int}  $summary
     */
    private function applyUpdate(PatchOperation $operation, array &$summary): void
    {
        if (! is_file($operation->path)) {
            throw new FileOperationException("Cannot update missing file '{$operation->path}'.");
        }

        $content = file_get_contents($operation->path);
        if ($content === false) {
            throw new FileOperationException("Failed to read file '{$operation->path}'.");
        }

        $updatedContent = $this->applyUpdateHunks($content, $operation->bodyLines, $operation->path);
        $targetPath = $operation->moveTo ?? $operation->path;

        // When moving, verify the destination doesn't already exist before writing
        if ($operation->moveTo !== null && $operation->moveTo !== $operation->path) {
            if (file_exists($operation->moveTo)) {
                throw new FileOperationException("Cannot move to '{$operation->moveTo}' because it already exists.");
            }
            $this->ensureParentDirectory($operation->moveTo);
        }

        try {
            AtomicFileWriter::write($targetPath, $updatedContent);
        } catch (\RuntimeException $e) {
            throw new FileOperationException("Failed to write file '{$targetPath}'.");
        }

        // Remove the original file after a successful move
        if ($operation->moveTo !== null && $operation->moveTo !== $operation->path) {
            if (! unlink($operation->path)) {
                throw new FileOperationException("Failed to remove original file '{$operation->path}' after move.");
            }
            $summary['moved']++;
        }

        $summary['updated']++;
    }

    /** Delete a single file (directories are not allowed). */
    private function applyDelete(PatchOperation $operation): int
    {
        if (! file_exists($operation->path)) {
            throw new FileOperationException("Cannot delete missing file '{$operation->path}'.");
        }

        if (is_dir($operation->path)) {
            throw new FileOperationException("Cannot delete directory '{$operation->path}' with apply_patch.");
        }

        if (! unlink($operation->path)) {
            throw new FileOperationException("Failed to delete file '{$operation->path}'.");
        }

        return 1;
    }

    /**
     * Split body lines into hunks (separated by @@ markers) and apply each via unique string replacement.
     *
     * @param  string[]  $bodyLines
     */
    private function applyUpdateHunks(string $content, array $bodyLines, string $path): string
    {
        if ($bodyLines === []) {
            return $content;
        }

        // Split into per-hunk line arrays, delimited by @@ or @@ <header>
        $chunks = [];
        $current = [];
        foreach ($bodyLines as $line) {
            if ($line === '@@' || str_starts_with($line, '@@ ')) {
                if ($current !== []) {
                    $chunks[] = $current;
                    $current = [];
                }

                continue;
            }

            if ($line === '*** End of File') {
                continue;
            }

            $current[] = $line;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        if ($chunks === []) {
            throw new FileOperationException("Update for '{$path}' did not contain any patch hunks.");
        }

        foreach ($chunks as $chunk) {
            [$old, $new] = $this->buildChunkStrings($chunk);
            // Skip no-op hunks where old and new are identical
            if ($old === $new) {
                continue;
            }

            $content = $this->replaceUnique($content, $old, $new, $path);
        }

        return $content;
    }

    /**
     * Build the old and new text representations from hunk lines using prefix characters (space/+/-).
     *
     * @param  string[]  $chunk
     * @return array{string, string} [old text, new text]
     */
    private function buildChunkStrings(array $chunk): array
    {
        $oldLines = [];
        $newLines = [];

        foreach ($chunk as $line) {
            $prefix = $line[0];
            $text = substr($line, 1);

            if ($prefix === ' ' || $prefix === '-') {
                $oldLines[] = $text;
            }
            if ($prefix === ' ' || $prefix === '+') {
                $newLines[] = $text;
            }
        }

        return [implode("\n", $oldLines), implode("\n", $newLines)];
    }

    /**
     * Replace exactly one occurrence of $old with $new; fails if not found or ambiguous.
     */
    private function replaceUnique(string $content, string $old, string $new, string $path): string
    {
        [$search, $replacement] = $this->resolveLineEndings($content, $old, $new);
        $first = strpos($content, $search);
        if ($first === false) {
            throw new FileOperationException("Patch context not found in '{$path}'.");
        }

        $second = strpos($content, $search, $first + 1);
        if ($second !== false) {
            throw new FileOperationException("Patch context is ambiguous in '{$path}'.");
        }

        return substr($content, 0, $first).$replacement.substr($content, $first + strlen($search));
    }

    /**
     * Normalize line endings: try LF first, fall back to CRLF if that's what the file uses.
     *
     * @return array{string, string} [search text, replacement text]
     */
    private function resolveLineEndings(string $content, string $old, string $new): array
    {
        if ($old !== '' && str_contains($content, $old)) {
            return [$old, $new];
        }

        $crlfOld = str_replace("\n", "\r\n", $old);
        if ($old !== '' && $crlfOld !== $old && str_contains($content, $crlfOld)) {
            return [$crlfOld, str_replace("\n", "\r\n", $new)];
        }

        return [$old, $new];
    }

    /** Recursively create the parent directory if it doesn't exist. */
    private function ensureParentDirectory(string $path): void
    {
        $dir = dirname($path);
        if (is_dir($dir)) {
            return;
        }

        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new FileOperationException("Failed to create directory '{$dir}'.");
        }
    }
}

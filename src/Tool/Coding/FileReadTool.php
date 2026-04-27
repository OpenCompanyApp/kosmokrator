<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Throwable;

/**
 * Reads file contents with line numbers, supporting offset/limit for partial reads.
 * Large files (>10 MB) are streamed line-by-line to keep memory usage low.
 * Prefer this over shell commands (`cat`, `head`) for inspecting files.
 */
class FileReadTool extends AbstractTool
{
    private const LARGE_FILE_THRESHOLD = 10 * 1024 * 1024;

    private const CACHE_LIMIT = 128;

    /** @var array<string, string> */
    private static array $cache = [];

    /** @var list<string> */
    private static array $cacheOrder = [];

    /**
     * @param  string|null  $projectRoot  Absolute path to project root for boundary enforcement
     * @param  string[]  $allowedPaths  Pre-resolved path prefixes allowed in addition to the project root
     */
    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly array $allowedPaths = [],
    ) {}

    public function name(): string
    {
        return 'file_read';
    }

    public function description(): string
    {
        return 'Read the contents of a file. Returns the file contents with line numbers.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'Absolute or relative path to the file to read'],
            'offset' => ['type' => 'integer', 'description' => 'Line number to start reading from (1-based). Optional.'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum number of lines to read. Optional, defaults to 2000.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['path'];
    }

    /**
     * @param  array{path: string, offset?: int, limit?: int}  $args  File path and optional line range
     * @return ToolResult File contents with line numbers
     */
    protected function handle(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $offset = max(1, (int) ($args['offset'] ?? 1));
        $limit = min(5000, max(1, (int) ($args['limit'] ?? 2000)));

        // Validate path stays within project root
        if ($this->projectRoot !== null) {
            try {
                $path = PathValidator::resolveAndValidatePath($path, $this->projectRoot, $this->allowedPaths);
            } catch (Throwable $e) {
                return ToolResult::error($e->getMessage());
            }
        }

        if (! file_exists($path)) {
            return ToolResult::error("File not found: {$path}");
        }

        if (! is_readable($path)) {
            return ToolResult::error("File not readable: {$path}");
        }

        if (is_dir($path)) {
            return ToolResult::error("Path is a directory, not a file: {$path}");
        }

        $stat = stat($path);
        if ($stat === false) {
            return ToolResult::error("Failed to stat file: {$path}");
        }

        $cacheKey = $this->cacheKey($path, $stat, $offset, $limit);
        $cached = self::readCache($cacheKey);
        if ($cached !== null) {
            return ToolResult::success($cached);
        }

        $fileSize = (int) $stat['size'];
        if ($fileSize > self::LARGE_FILE_THRESHOLD) {
            return $this->remember($cacheKey, $this->readLargeFile($path, $offset, $limit));
        }

        $lines = file($path);
        if ($lines === false) {
            return ToolResult::error("Failed to read file: {$path}");
        }

        $totalLines = count($lines);
        $slice = array_slice($lines, $offset - 1, $limit, true);

        $output = '';
        foreach ($slice as $lineNum => $line) {
            $num = str_pad((string) ($lineNum + 1), strlen((string) $totalLines), ' ', STR_PAD_LEFT);
            $output .= "{$num}\t{$line}";
        }

        if ($offset + $limit - 1 < $totalLines) {
            $remaining = $totalLines - ($offset + $limit - 1);
            $output .= "\n... {$remaining} more lines";
        }

        return $this->remember($cacheKey, ToolResult::success(rtrim($output)));
    }

    public function resetCache(): void
    {
        self::resetGlobalCache();
    }

    public static function resetGlobalCache(): void
    {
        self::$cache = [];
        self::$cacheOrder = [];
    }

    /**
     * Stream-read a large file line by line to avoid loading it entirely into memory.
     */
    private function readLargeFile(string $path, int $offset, int $limit): ToolResult
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ToolResult::error("Failed to open file: {$path}");
        }

        $lineNum = 0;
        $collected = [];

        while (($line = fgets($handle)) !== false) {
            $lineNum++;
            if ($lineNum >= $offset && count($collected) < $limit) {
                $collected[$lineNum] = $line;
            }
        }
        $totalLines = $lineNum;
        fclose($handle);

        $output = '';
        $padWidth = strlen((string) $totalLines);
        foreach ($collected as $num => $line) {
            $numStr = str_pad((string) $num, $padWidth, ' ', STR_PAD_LEFT);
            $output .= "{$numStr}\t{$line}";
        }

        if ($offset + $limit - 1 < $totalLines) {
            $remaining = $totalLines - ($offset + $limit - 1);
            $output .= "\n... {$remaining} more lines";
        }

        return ToolResult::success(rtrim($output));
    }

    /**
     * @param  array<int|string, mixed>  $stat
     */
    private function cacheKey(string $path, array $stat, int $offset, int $limit): string
    {
        $resolved = realpath($path) ?: $path;
        $fingerprint = [
            'path' => $resolved,
            'size' => (int) ($stat['size'] ?? 0),
            'mtime' => (int) ($stat['mtime'] ?? 0),
            'ctime' => (int) ($stat['ctime'] ?? 0),
            'inode' => (int) ($stat['ino'] ?? 0),
            'offset' => $offset,
            'limit' => $limit,
        ];

        return hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
    }

    private static function readCache(string $key): ?string
    {
        if (! isset(self::$cache[$key])) {
            return null;
        }

        self::touchCacheKey($key);

        return self::$cache[$key];
    }

    private function remember(string $key, ToolResult $result): ToolResult
    {
        if (! $result->success) {
            return $result;
        }

        self::$cache[$key] = $result->output;
        self::touchCacheKey($key);

        while (count(self::$cacheOrder) > self::CACHE_LIMIT) {
            $oldest = array_shift(self::$cacheOrder);
            if ($oldest !== null) {
                unset(self::$cache[$oldest]);
            }
        }

        return $result;
    }

    private static function touchCacheKey(string $key): void
    {
        $index = array_search($key, self::$cacheOrder, true);
        if ($index !== false) {
            array_splice(self::$cacheOrder, $index, 1);
        }

        self::$cacheOrder[] = $key;
    }
}

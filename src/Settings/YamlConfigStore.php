<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Low-level read/write operations on YAML config files.
 *
 * Handles loading, saving, and dot-path navigation of nested config arrays.
 * Used by SettingsManager as the persistence layer for both global and project configs.
 */
final class YamlConfigStore
{
    public function __construct(
        private readonly ?LoggerInterface $log = null,
    ) {}

    /**
     * Parse a YAML config file into a nested array.
     *
     * @param  string|null  $path  Absolute file path, or null to skip
     * @return array<string, mixed> Parsed config data, or empty array on failure
     */
    public function load(?string $path): array
    {
        if ($path === null || ! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return [];
        }

        try {
            return Yaml::parse($content) ?? [];
        } catch (ParseException $e) {
            $this->log?->warning("Failed to parse YAML config file {$path}: ".$e->getMessage());

            return [];
        }
    }

    /**
     * Persist a nested config array to a YAML file.
     *
     * Creates the parent directory if needed. Removes the file when data is empty.
     * Uses atomic write (temp file + rename) to prevent partial writes.
     *
     * @param  string  $path  Absolute file path
     * @param  array<string, mixed>  $data  Config data to write
     */
    public function save(string $path, array $data): void
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);

        if ($data === []) {
            if (file_exists($path)) {
                @unlink($path);
            }

            return;
        }

        // Atomic write: write to temp file then rename
        $tmpPath = $dir.'/'.basename($path).'.tmp.'.uniqid('', true);
        file_put_contents($tmpPath, Yaml::dump($data, 8, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
        rename($tmpPath, $path);
    }

    /**
     * Mutate a YAML file while holding an exclusive lock.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    public function update(string $path, callable $mutate): void
    {
        $this->ensureDirectory(dirname($path));
        $lockPath = $path.'.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException("Unable to open YAML config lock: {$lockPath}");
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock YAML config: {$path}");
            }

            $this->save($path, $mutate($this->load($path)));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Retrieve a value from a nested array using dot notation.
     *
     * @param  array<string, mixed>  $data  Config array to search
     * @param  string  $path  Dot-separated key path (e.g. "kosmokrator.agent.mode")
     * @return mixed The value at the given path, or null if not found
     */
    public function get(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set a value in a nested array using dot notation, creating intermediate keys as needed.
     *
     * @param  array<string, mixed>  $data  Config array to modify (by reference)
     * @param  string  $path  Dot-separated key path
     * @param  mixed  $value  Value to assign
     */
    public function set(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current = &$data;

        foreach ($segments as $segment) {
            if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current = $value;
    }

    /**
     * Remove a value from a nested array using dot notation.
     *
     * @param  array<string, mixed>  $data  Config array to modify (by reference)
     * @param  string  $path  Dot-separated key path to unset
     */
    public function unset(array &$data, string $path): void
    {
        $segments = explode('.', $path);
        $leaf = array_pop($segments);
        if ($segments === [] || $leaf === '') {
            return;
        }

        $current = &$data;
        foreach ($segments as $segment) {
            if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                return;
            }
            $current = &$current[$segment];
        }

        unset($current[$leaf]);
        $this->cleanupEmptyParents($data);
    }

    /** Recursively remove empty array leaves left after an unset.
     * @param  array<string, mixed>  $data
     */
    private function cleanupEmptyParents(array &$data): void
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $this->cleanupEmptyParents($value);
                if ($value === []) {
                    unset($data[$key]);
                }
            }
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory: {$dir}");
        }
    }
}

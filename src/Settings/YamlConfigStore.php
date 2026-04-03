<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

use Symfony\Component\Yaml\Yaml;

final class YamlConfigStore
{
    /**
     * @return array<string, mixed>
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

        return Yaml::parse($content) ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(string $path, array $data): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        if ($data === []) {
            if (file_exists($path)) {
                @unlink($path);
            }

            return;
        }

        file_put_contents($path, Yaml::dump($data, 8, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

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

    public function set(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current =& $data;

        foreach ($segments as $segment) {
            if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current =& $current[$segment];
        }

        $current = $value;
    }

    public function unset(array &$data, string $path): void
    {
        $segments = explode('.', $path);
        $leaf = array_pop($segments);
        if ($leaf === null) {
            return;
        }

        $current =& $data;
        foreach ($segments as $segment) {
            if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                return;
            }
            $current =& $current[$segment];
        }

        unset($current[$leaf]);
        $this->cleanupEmptyParents($data);
    }

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
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Integration;

use OpenCompany\IntegrationCore\Contracts\AgentFileStorage;

class KosmokratorFileStorage implements AgentFileStorage
{
    public function saveFile(
        object $agent,
        string $filename,
        string $content,
        string $mimeType,
        ?string $subfolder = null,
    ): array {
        $baseDir = (getcwd() ?: '.').'/.kosmo/output';
        $dir = $subfolder !== null
            ? $baseDir.'/'.$this->safeRelativePath($subfolder)
            : $baseDir;

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $safeFilename = basename($filename);
        if ($safeFilename === '' || $safeFilename === '.' || $safeFilename === '..' || $safeFilename !== $filename) {
            throw new \InvalidArgumentException('Invalid output filename.');
        }

        $path = $dir.'/'.$safeFilename;
        $realBase = realpath($baseDir) ?: $baseDir;
        $realDir = realpath($dir) ?: $dir;
        if (! $this->isPathInside($realDir, $realBase)) {
            throw new \InvalidArgumentException('Output path escapes the KosmoKrator output directory.');
        }

        file_put_contents($path, $content);

        return [
            'id' => $safeFilename,
            'path' => $path,
            'url' => $path,
        ];
    }

    private function safeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid output subfolder.');
        }

        return $path;
    }

    private function isPathInside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path.DIRECTORY_SEPARATOR, $root) || $path === rtrim($root, DIRECTORY_SEPARATOR);
    }
}

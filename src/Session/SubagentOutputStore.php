<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

final class SubagentOutputStore
{
    public function __construct(private readonly ?string $root = null) {}

    /**
     * @return array{ref: string, bytes: int, preview: string}
     */
    public function write(string $sessionId, string $agentId, string $output): array
    {
        $bytes = strlen($output);
        $dir = $this->sessionDir($sessionId);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create subagent output directory: {$dir}");
        }

        $path = $dir.'/'.$this->safeName($agentId).'.txt';
        if (@file_put_contents($path, $output) === false) {
            throw new \RuntimeException("Unable to write subagent output: {$path}");
        }

        return [
            'ref' => $path,
            'bytes' => $bytes,
            'preview' => $this->preview($output),
        ];
    }

    public function read(string $ref): string
    {
        $content = @file_get_contents($ref);
        if ($content === false) {
            throw new \RuntimeException("Unable to read subagent output: {$ref}");
        }

        return $content;
    }

    private function sessionDir(string $sessionId): string
    {
        return $this->root().'/'.$this->safeName($sessionId);
    }

    private function root(): string
    {
        if ($this->root !== null) {
            return $this->root;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return $home.'/.kosmokrator/data/swarm-output';
    }

    private function safeName(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value);

        return trim((string) $safe, '._') ?: 'agent';
    }

    private function preview(string $output): string
    {
        foreach (explode("\n", trim($output)) as $line) {
            $stripped = trim($line);
            if ($stripped === '' || str_starts_with($stripped, '#') || str_starts_with($stripped, '---')) {
                continue;
            }

            $stripped = (string) preg_replace('/^[-*]\s+/', '', $stripped);
            $stripped = trim((string) preg_replace('/\s+/', ' ', $stripped));
            if (mb_strlen($stripped) > 500) {
                return mb_substr($stripped, 0, 497).'...';
            }

            return $stripped;
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

final class McpConfigStore
{
    private ?string $projectRoot = null;

    public function __construct(
        private readonly ?string $home = null,
    ) {}

    public function setProjectRoot(?string $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * @return array<string, McpServerConfig>
     */
    public function effectiveServers(bool $includeDisabled = true): array
    {
        $servers = [];

        foreach ($this->readSources() as $source) {
            foreach ($source['servers'] as $name => $config) {
                $servers[$name] = $config;
            }
        }

        if (! $includeDisabled) {
            $servers = array_filter($servers, static fn (McpServerConfig $server): bool => $server->enabled);
        }

        ksort($servers, SORT_STRING);

        return $servers;
    }

    /**
     * @return list<array{source: string, path: string, schema: string, servers: array<string, McpServerConfig>}>
     */
    public function readSources(): array
    {
        $sources = [];

        foreach ($this->candidatePaths() as $source => $path) {
            if (! is_file($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                continue;
            }

            [$schema, $rawServers] = $this->serverMap($decoded);
            if ($rawServers === []) {
                continue;
            }

            $servers = [];
            foreach ($rawServers as $name => $raw) {
                if (! is_string($name) || ! is_array($raw)) {
                    continue;
                }

                $servers[$name] = McpServerConfig::fromArray($name, $raw, $source, $path);
            }

            $sources[] = [
                'source' => $source,
                'path' => $path,
                'schema' => $schema,
                'servers' => $servers,
            ];
        }

        return $sources;
    }

    public function get(string $name): ?McpServerConfig
    {
        return $this->effectiveServers()[$name] ?? null;
    }

    public function writeServer(McpServerConfig $server, string $scope = 'project'): string
    {
        $path = $scope === 'global' ? $this->globalPath() : $this->projectPath();
        $this->updateJson($path, function (array $data) use ($server): array {
            $data['mcpServers'] ??= [];
            if (! is_array($data['mcpServers'])) {
                $data['mcpServers'] = [];
            }

            $data['mcpServers'][$server->name] = $server->toPortableArray();

            return $data;
        });

        return $path;
    }

    public function removeServer(string $name, string $scope = 'project'): string
    {
        $path = $scope === 'global' ? $this->globalPath() : $this->projectPath();
        $this->updateJson($path, function (array $data) use ($name): array {
            foreach (['mcpServers', 'servers'] as $key) {
                if (is_array($data[$key] ?? null)) {
                    unset($data[$key][$name]);
                }
            }

            return $data;
        });

        return $path;
    }

    public function setEnabled(string $name, bool $enabled, string $scope = 'project'): string
    {
        $path = $scope === 'global' ? $this->globalPath() : $this->projectPath();
        $this->updateJson($path, function (array $data) use ($name, $enabled): array {
            $key = is_array($data['mcpServers'] ?? null) ? 'mcpServers' : 'servers';
            $data[$key] ??= [];
            $data[$key][$name] ??= [];
            $data[$key][$name]['enabled'] = $enabled;

            return $data;
        });

        return $path;
    }

    public function projectPath(): string
    {
        $root = $this->projectRoot ?: getcwd() ?: sys_get_temp_dir();

        return rtrim($root, '/').'/.mcp.json';
    }

    public function globalPath(): string
    {
        $home = $this->home ?: (getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir());

        return rtrim($home, '/').'/.kosmokrator/mcp.json';
    }

    /**
     * @return array<string, string>
     */
    public function candidatePaths(): array
    {
        $paths = ['global' => $this->globalPath()];
        $root = $this->projectRoot ?: getcwd();
        if (is_string($root) && $root !== '') {
            $root = rtrim($root, '/');
            $paths['project-vscode'] = $root.'/.vscode/mcp.json';
            $paths['project-cursor'] = $root.'/.cursor/mcp.json';
            $paths['project'] = $root.'/.mcp.json';
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function serverMap(array $data): array
    {
        if (is_array($data['mcpServers'] ?? null)) {
            return ['mcpServers', $data['mcpServers']];
        }

        if (is_array($data['servers'] ?? null)) {
            return ['servers', $data['servers']];
        }

        return ['none', []];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path): array
    {
        if (! is_file($path)) {
            return ['mcpServers' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['mcpServers' => []];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveJson(string $path, array $data): void
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);

        $tmp = $path.'.tmp.'.uniqid('', true);
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        rename($tmp, $path);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function updateJson(string $path, callable $mutate): void
    {
        $this->ensureDirectory(dirname($path));

        $lockPath = $path.'.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException("Unable to open MCP config lock: {$lockPath}");
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock MCP config: {$path}");
            }

            $this->saveJson($path, $mutate($this->loadJson($path)));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory: {$dir}");
        }
    }
}

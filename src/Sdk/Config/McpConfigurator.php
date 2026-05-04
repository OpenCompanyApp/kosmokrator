<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Config;

use Kosmokrator\IO\AtomicFileWriter;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use Kosmokrator\Mcp\McpSecretStore;
use Kosmokrator\Mcp\McpServerConfig;
use Symfony\Component\Yaml\Yaml;

final class McpConfigurator extends RuntimeConfigurator
{
    public static function forProject(string $cwd, ?string $basePath = null): self
    {
        return new self($cwd, $basePath);
    }

    public static function global(?string $basePath = null): self
    {
        return new self(null, $basePath);
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     * @param  array{read?: string, write?: string}  $permissions
     */
    public function addStdioServer(
        string $name,
        string $command,
        array $args = [],
        array $env = [],
        array $permissions = [],
        bool $enabled = true,
        bool $trust = false,
        string $scope = 'project',
    ): self {
        $container = $this->container();
        $store = $container->make(McpConfigStore::class);
        if ($this->cwd !== null) {
            $store->setProjectRoot($this->cwd);
        }

        $server = new McpServerConfig(
            name: $name,
            type: 'stdio',
            command: $command,
            args: array_map('strval', $args),
            env: array_map('strval', $env),
            enabled: $enabled,
            source: $scope,
        );

        $store->writeServer($server, $scope);

        if ($trust) {
            $permissionsEvaluator = $container->make(McpPermissionEvaluator::class);
            $this->setRawSetting("mcp.trust.{$name}.fingerprint", $permissionsEvaluator->fingerprint($server), $scope);
        }

        foreach ($permissions as $operation => $permission) {
            if (in_array($permission, ['allow', 'ask', 'deny'], true)) {
                $this->setRawSetting("mcp.servers.{$name}.permissions.{$operation}", $permission, $scope);
            }
        }

        return $this;
    }

    private function setRawSetting(string $path, mixed $value, string $scope): void
    {
        $file = $scope === 'global'
            ? rtrim(getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir(), '/').'/.kosmo/config.yaml'
            : rtrim($this->cwd ?: (getcwd() ?: sys_get_temp_dir()), '/').'/.kosmo/config.yaml';

        $data = is_file($file) ? (Yaml::parseFile($file) ?: []) : [];
        if (! is_array($data)) {
            $data = [];
        }

        $cursor = &$data;
        foreach (explode('.', 'kosmo.'.$path) as $segment) {
            if (! is_array($cursor)) {
                $cursor = [];
            }
            if (! array_key_exists($segment, $cursor) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor = $value;
        unset($cursor);

        AtomicFileWriter::write($file, Yaml::dump($data, 8, 2), $scope === 'global' ? 0700 : 0755);
    }

    public function setSecret(string $server, string $key, string $value): self
    {
        $this->container()->make(McpSecretStore::class)->set($server, $key, $value);

        return $this;
    }
}

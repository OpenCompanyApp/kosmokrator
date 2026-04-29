<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;

final class McpPermissionEvaluator
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly ?PermissionEvaluator $toolPermissions = null,
    ) {}

    public function assertTrusted(McpServerConfig $server, bool $force = false): void
    {
        if ($this->isTrusted($server, $force)) {
            return;
        }

        throw new \RuntimeException("Project MCP server '{$server->name}' is not trusted. Run `kosmokrator mcp:trust {$server->name} --project --json` after reviewing .mcp.json, or pass --force for trusted automation.");
    }

    public function isTrusted(McpServerConfig $server, bool $force = false): bool
    {
        if ($force || ! str_starts_with($server->source, 'project')) {
            return true;
        }

        $trusted = $this->settings->getRaw("mcp.trust.{$server->name}.fingerprint")
            ?? $this->settings->getRaw("kosmokrator.mcp.trust.{$server->name}.fingerprint");

        return $trusted === $this->fingerprint($server);
    }

    public function assertAllowed(McpServerConfig $server, McpToolDefinition $tool, bool $force = false): void
    {
        $this->assertTrusted($server, $force);
        $operation = $tool->operation();
        $permission = $this->permission($server->name, $operation);

        if ($permission === 'deny' && ! $force) {
            throw new \RuntimeException("MCP server '{$server->name}' {$operation} access denied.");
        }

        if ($permission === 'ask' && ! $force && $this->toolPermissions?->getPermissionMode() !== PermissionMode::Prometheus) {
            throw new \RuntimeException("MCP server '{$server->name}' {$operation} requires approval. In headless mode configure `mcp.servers.{$server->name}.permissions.{$operation}` or pass --force for trusted automation.");
        }
    }

    public function assertReadAllowed(McpServerConfig $server, bool $force = false): void
    {
        $this->assertTrusted($server, $force);
        $permission = $this->permission($server->name, 'read');

        if ($permission === 'deny' && ! $force) {
            throw new \RuntimeException("MCP server '{$server->name}' read access denied.");
        }

        if ($permission === 'ask' && ! $force && $this->toolPermissions?->getPermissionMode() !== PermissionMode::Prometheus) {
            throw new \RuntimeException("MCP server '{$server->name}' read requires approval. Configure `mcp.servers.{$server->name}.permissions.read` or pass --force for trusted automation.");
        }
    }

    public function permission(string $server, string $operation): string
    {
        $value = $this->settings->getRaw("mcp.servers.{$server}.permissions.{$operation}")
            ?? $this->settings->getRaw("kosmokrator.mcp.servers.{$server}.permissions.{$operation}");
        if (in_array($value, ['allow', 'ask', 'deny'], true)) {
            return $value;
        }

        $default = $this->settings->getRaw('mcp.permissions_default')
            ?? $this->settings->getRaw('kosmokrator.mcp.permissions_default');

        return in_array($default, ['allow', 'ask', 'deny'], true) ? $default : 'ask';
    }

    public function trust(McpServerConfig $server, string $scope = 'project'): string
    {
        $fingerprint = $this->fingerprint($server);
        $this->settings->setRaw("mcp.trust.{$server->name}.fingerprint", $fingerprint, $scope);

        return $fingerprint;
    }

    public function fingerprint(McpServerConfig $server): string
    {
        return hash('sha256', json_encode($server->toPortableArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp\Server;

final class McpGatewayToolMapper
{
    public static function integrationToolName(string $provider, string $function): string
    {
        return 'integration__'.self::identifier($provider).'__'.self::identifier($function);
    }

    public static function upstreamToolName(string $server, string $tool): string
    {
        return 'mcp__'.self::identifier($server).'__'.self::identifier($tool);
    }

    /**
     * @return array{kind: 'integration'|'mcp', namespace: string, function: string}|null
     */
    public static function parse(string $name): ?array
    {
        $parts = explode('__', $name, 3);
        if (count($parts) !== 3) {
            return null;
        }

        if ($parts[0] === 'integration') {
            return ['kind' => 'integration', 'namespace' => $parts[1], 'function' => $parts[2]];
        }

        if ($parts[0] === 'mcp') {
            return ['kind' => 'mcp', 'namespace' => $parts[1], 'function' => $parts[2]];
        }

        return null;
    }

    public static function identifier(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value === '' ? 'tool' : $value;
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk;

use Kosmokrator\Integration\Runtime\LuaExecutionResult;
use Kosmokrator\Mcp\McpRuntime;

final class McpClient
{
    public function __construct(private readonly McpRuntime $runtime) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(string $function, array $args = [], bool $force = false, bool $dryRun = false): array
    {
        return $this->runtime->call($function, $args, $force, $dryRun);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function lua(string $code, array $options = []): LuaExecutionResult
    {
        return $this->runtime->executeLua($code, $options);
    }
}

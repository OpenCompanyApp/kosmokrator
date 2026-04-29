<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk;

use Kosmokrator\Integration\Runtime\IntegrationCallResult;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Integration\Runtime\IntegrationRuntimeOptions;
use Kosmokrator\Integration\Runtime\LuaExecutionResult;

final class IntegrationClient
{
    public function __construct(private readonly IntegrationRuntime $runtime) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function call(string $function, array $args = [], ?string $account = null, bool $force = false, bool $dryRun = false): IntegrationCallResult
    {
        return $this->runtime->call(
            $function,
            $args,
            $account,
            new IntegrationRuntimeOptions(account: $account, force: $force, dryRun: $dryRun),
        );
    }

    /**
     * @param  array{memoryLimit?: int, cpuLimit?: float, force?: bool}  $options
     */
    public function lua(string $code, array $options = []): LuaExecutionResult
    {
        return $this->runtime->executeLua($code, $options);
    }
}

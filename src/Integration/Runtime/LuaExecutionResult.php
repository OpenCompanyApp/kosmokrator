<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

use Kosmokrator\Lua\LuaResult;

final readonly class LuaExecutionResult
{
    /**
     * @param  list<array<string, mixed>>  $callLog
     */
    public function __construct(
        public LuaResult $lua,
        public array $callLog = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->lua->succeeded();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->succeeded(),
            'output' => $this->lua->output,
            'error' => $this->lua->error,
            'result' => $this->lua->result,
            'execution_time_ms' => $this->lua->executionTime,
            'memory_usage' => $this->lua->memoryUsage,
            'integration_calls' => $this->callLog,
        ];
    }
}

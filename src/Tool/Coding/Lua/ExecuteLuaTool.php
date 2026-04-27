<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding\Lua;

use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Lua\NativeToolBridge;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

class ExecuteLuaTool extends AbstractTool
{
    /** @var \Closure(): NativeToolBridge|null Lazy resolver to avoid circular dependency */
    private static ?\Closure $nativeBridgeResolver = null;

    public function __construct(
        private readonly IntegrationRuntime $runtime,
    ) {}

    /**
     * Set the lazy resolver for NativeToolBridge (called from ToolServiceProvider after registry is built).
     */
    public static function setNativeBridgeResolver(\Closure $resolver): void
    {
        self::$nativeBridgeResolver = $resolver;
    }

    public function name(): string
    {
        return 'execute_lua';
    }

    public function description(): string
    {
        return 'Execute Lua code with app.* namespace access. Use app.integrations.* for API calls, app.tools.* for native tools (file_read, glob, grep, bash, subagent, etc.). Always use lua_read_doc first to look up function names and parameters. Use print() or dump() for output.';
    }

    public function parameters(): array
    {
        return [
            'code' => ['type' => 'string', 'description' => 'Lua code to execute. Use print()/dump() for output. Access integrations via app.integrations.{name}.{function}().'],
            'memoryLimit' => ['type' => 'integer', 'description' => 'Memory limit in bytes. Default: 33554432 (32 MB).'],
            'cpuLimit' => ['type' => 'number', 'description' => 'CPU time limit in seconds. Default: 30.0.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['code'];
    }

    protected function handle(array $args): ToolResult
    {
        $code = $args['code'] ?? '';

        if (! is_string($code) || trim($code) === '') {
            return ToolResult::error('Missing required parameter "code". Provide the Lua source code to execute.');
        }

        $options = [];
        if (isset($args['memoryLimit'])) {
            $options['memoryLimit'] = (int) $args['memoryLimit'];
        }
        if (isset($args['cpuLimit'])) {
            $options['cpuLimit'] = (float) $args['cpuLimit'];
        }

        $nativeBridge = null;
        if (self::$nativeBridgeResolver !== null) {
            $nativeBridge = (self::$nativeBridgeResolver)();
        }

        $execution = $this->runtime->executeLua($code, $options, $nativeBridge);
        $result = $execution->lua;

        $lines = [];

        if ($result->output !== '') {
            $lines[] = "Output:\n{$result->output}";
        }

        if ($result->error !== null) {
            $lines[] = "Error: {$result->error}";
        }

        if ($result->result !== null) {
            $lines[] = 'Return value: '.$this->formatResult($result->result);
        }

        $lines[] = "Execution time: {$result->executionTime}ms";

        if ($result->memoryUsage !== null) {
            $lines[] = 'Memory: '.$this->formatBytes($result->memoryUsage);
        }

        if ($execution->callLog !== []) {
            $lines[] = 'Integration calls: '.count($execution->callLog);
        }

        $output = empty($lines)
            ? 'Script executed successfully with no output.'
            : implode("\n", $lines);

        if ($result->succeeded()) {
            return ToolResult::success($output);
        }

        return ToolResult::error($output);
    }

    private function formatResult(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return (string) $value;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}

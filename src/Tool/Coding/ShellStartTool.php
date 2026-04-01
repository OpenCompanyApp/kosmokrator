<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

final class ShellStartTool implements ToolInterface
{
    public function __construct(
        private readonly ShellSessionManager $sessions,
        private readonly bool $readOnly = false,
    ) {}

    public function name(): string
    {
        return 'shell_start';
    }

    public function description(): string
    {
        return 'Start an interactive shell session or long-running command. Returns a session id and any initial output.';
    }

    public function parameters(): array
    {
        return [
            'command' => ['type' => 'string', 'description' => 'Command to start, such as "bash", "python", or a watch task.'],
            'cwd' => ['type' => 'string', 'description' => 'Optional working directory for the session. Defaults to the current directory.'],
            'timeout' => ['type' => 'integer', 'description' => 'Maximum runtime in seconds before the session is killed. Defaults to the shell timeout.'],
            'wait_ms' => ['type' => 'integer', 'description' => 'How long to wait for initial output before returning. Defaults to 100ms.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['command'];
    }

    public function execute(array $args): ToolResult
    {
        $command = trim((string) ($args['command'] ?? ''));
        if ($command === '') {
            return ToolResult::error('No command provided.');
        }

        try {
            $result = $this->sessions->start(
                command: $command,
                cwd: isset($args['cwd']) ? (string) $args['cwd'] : null,
                readOnly: (bool) ($args['read_only'] ?? $this->readOnly),
                timeoutSeconds: isset($args['timeout']) ? (int) $args['timeout'] : null,
                waitMs: isset($args['wait_ms']) ? (int) $args['wait_ms'] : null,
            );

            return ToolResult::success($result['output']);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}

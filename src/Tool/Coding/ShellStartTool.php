<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Tool that spawns a long-running interactive shell session (bash, python, etc.).
 *
 * Returns a session ID for use with ShellWriteTool, ShellReadTool, and ShellKillTool.
 */
final class ShellStartTool extends AbstractTool
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

    /**
     * Start a new shell session and return initial output.
     *
     * @param  array<string, mixed>  $args  Tool arguments from the AI agent
     */
    protected function handle(array $args): ToolResult
    {
        $command = trim((string) ($args['command'] ?? ''));
        if ($command === '') {
            return ToolResult::error('No command provided.');
        }

        $result = $this->sessions->start(
            command: $command,
            cwd: isset($args['cwd']) ? (string) $args['cwd'] : null,
            readOnly: (bool) ($args['read_only'] ?? $this->readOnly),
            timeoutSeconds: isset($args['timeout']) ? max(1, min((int) $args['timeout'], 7200)) : null,
            waitMs: isset($args['wait_ms']) ? (int) $args['wait_ms'] : null,
        );

        $lines = ["Session ID: {$result['id']}"];
        // Extract just the output portion (after the header line)
        $output = $result['output'];
        $header = "Session {$result['id']} started in";
        if (str_starts_with($output, $header)) {
            $afterHeader = substr($output, strlen($header));
            // Skip the "in {cwd}" part and get actual output
            $cwdEnd = strpos($afterHeader, "\n\n");
            if ($cwdEnd !== false) {
                $actualOutput = trim(substr($afterHeader, $cwdEnd + 2));
                if ($actualOutput !== '' && $actualOutput !== '(no new output yet)') {
                    $lines[] = '';
                    $lines[] = $actualOutput;
                }
            }
        }

        return ToolResult::success(implode("\n", $lines));
    }
}

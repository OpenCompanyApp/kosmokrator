<?php

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;
use Symfony\Component\Process\Process;

class BashTool implements ToolInterface
{
    private int $timeout;

    public function __construct(int $timeout = 120)
    {
        $this->timeout = $timeout;
    }

    public function name(): string { return 'bash'; }

    public function description(): string
    {
        return 'Execute a shell command and return its output. Use for running tests, installing packages, git operations, etc.';
    }

    public function parameters(): array
    {
        return [
            'command' => ['type' => 'string', 'description' => 'The shell command to execute'],
        ];
    }

    public function requiredParameters(): array { return ['command']; }

    public function execute(array $args): ToolResult
    {
        $command = trim($args['command'] ?? '');

        if ($command === '') {
            return ToolResult::error('No command provided');
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ToolResult::error("Process error: {$e->getMessage()}");
        }

        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        $result = '';
        if ($output !== '') {
            $result .= $output;
        }
        if ($errorOutput !== '') {
            $result .= ($result !== '' ? "\n" : '') . $errorOutput;
        }
        if ($result === '') {
            $result = "(no output)";
        }

        $result .= "\nExit code: {$exitCode}";

        return new ToolResult($result, $exitCode === 0);
    }
}

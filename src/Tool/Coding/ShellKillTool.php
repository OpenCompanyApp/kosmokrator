<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

final class ShellKillTool implements ToolInterface
{
    public function __construct(
        private readonly ShellSessionManager $sessions,
    ) {}

    public function name(): string
    {
        return 'shell_kill';
    }

    public function description(): string
    {
        return 'Terminate an existing shell session.';
    }

    public function parameters(): array
    {
        return [
            'session_id' => ['type' => 'string', 'description' => 'The shell session id returned by shell_start.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['session_id'];
    }

    public function execute(array $args): ToolResult
    {
        $sessionId = trim((string) ($args['session_id'] ?? ''));
        if ($sessionId === '') {
            return ToolResult::error('session_id is required.');
        }

        try {
            return ToolResult::success($this->sessions->kill($sessionId));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}

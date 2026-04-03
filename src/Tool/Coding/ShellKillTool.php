<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

/**
 * Tool that forcefully terminates a running shell session by ID.
 *
 * Sessions are also cleaned up automatically on idle timeout, but this tool
 * provides explicit agent-controlled termination.
 */
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

    /**
     * Kill the session and return any remaining output.
     *
     * @param  array<string, mixed>  $args  Tool arguments from the AI agent
     */
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

<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Tool that forcefully terminates a running shell session by ID.
 *
 * Sessions are also cleaned up automatically on idle timeout, but this tool
 * provides explicit agent-controlled termination.
 */
final class ShellKillTool extends AbstractTool
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

    /**
     * Kill the session and return any remaining output.
     *
     * @param  array<string, mixed>  $args  Tool arguments from the AI agent
     */
    protected function handle(array $args): ToolResult
    {
        $sessionId = trim((string) ($args['session_id'] ?? ''));
        if ($sessionId === '') {
            return ToolResult::error('session_id is required.');
        }

        return ToolResult::success($this->sessions->kill($sessionId));
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Tool that reads any new (unread) output from a running or exited shell session.
 *
 * Non-destructive: calling read does not consume or alter the session state.
 */
final class ShellReadTool extends AbstractTool
{
    public function __construct(
        private readonly ShellSessionManager $sessions,
    ) {}

    public function name(): string
    {
        return 'shell_read';
    }

    public function description(): string
    {
        return 'Read any new output from an existing shell session.';
    }

    public function parameters(): array
    {
        return [
            'session_id' => ['type' => 'string', 'description' => 'The shell session id returned by shell_start.'],
            'wait_ms' => ['type' => 'integer', 'description' => 'How long to wait for output before returning. Defaults to 100ms.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['session_id'];
    }

    /**
     * Read unread output from the given session.
     *
     * @param  array<string, mixed>  $args  Tool arguments from the AI agent
     */
    protected function handle(array $args): ToolResult
    {
        $sessionId = trim((string) ($args['session_id'] ?? ''));
        if ($sessionId === '') {
            return ToolResult::error('session_id is required.');
        }

        $output = $this->sessions->read(
            id: $sessionId,
            waitMs: isset($args['wait_ms']) ? (int) $args['wait_ms'] : null,
        );

        return ToolResult::success($output);
    }
}

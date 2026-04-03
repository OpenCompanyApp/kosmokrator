<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\ToolResult;

/**
 * Tool that sends stdin input to a running shell session.
 *
 * Enforces read-only mode by blocking mutative commands via PermissionEvaluator.
 */
final class ShellWriteTool extends AbstractTool
{
    public function __construct(
        private readonly ShellSessionManager $sessions,
        private readonly ?PermissionEvaluator $permissions = null,
    ) {}

    public function name(): string
    {
        return 'shell_write';
    }

    public function description(): string
    {
        return 'Send input to an existing shell session and return any new output.';
    }

    public function parameters(): array
    {
        return [
            'session_id' => ['type' => 'string', 'description' => 'The shell session id returned by shell_start.'],
            'input' => ['type' => 'string', 'description' => 'Text to send to the session stdin.'],
            'submit' => ['type' => 'boolean', 'description' => 'Whether to append a newline after the input. Defaults to true.'],
            'wait_ms' => ['type' => 'integer', 'description' => 'How long to wait for output after writing. Defaults to 100ms.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['session_id', 'input'];
    }

    /**
     * Write input to a session's stdin and return any resulting output.
     *
     * @param  array<string, mixed>  $args  Tool arguments from the AI agent
     */
    protected function handle(array $args): ToolResult
    {
        $sessionId = trim((string) ($args['session_id'] ?? ''));
        if ($sessionId === '') {
            return ToolResult::error('session_id is required.');
        }

        $input = (string) ($args['input'] ?? '');

        // Block mutative commands in read-only sessions
        if ($this->sessions->isReadOnly($sessionId) && $this->permissions?->isMutativeCommand($input)) {
            return ToolResult::error("Command blocked for read-only shell session '{$sessionId}'.");
        }

        $output = $this->sessions->write(
            id: $sessionId,
            input: $input,
            submit: ! array_key_exists('submit', $args) || (bool) $args['submit'],
            waitMs: isset($args['wait_ms']) ? (int) $args['wait_ms'] : null,
        );

        return ToolResult::success($output);
    }
}

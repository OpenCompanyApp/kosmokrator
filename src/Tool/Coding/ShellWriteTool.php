<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

final class ShellWriteTool implements ToolInterface
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

    public function execute(array $args): ToolResult
    {
        $sessionId = trim((string) ($args['session_id'] ?? ''));
        if ($sessionId === '') {
            return ToolResult::error('session_id is required.');
        }

        $input = (string) ($args['input'] ?? '');
        try {
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
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}

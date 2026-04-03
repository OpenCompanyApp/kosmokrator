<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Coding\ShellWriteTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ShellWriteToolTest extends TestCase
{
    private ShellSessionManager $sessions;

    private ShellWriteTool $tool;

    protected function setUp(): void
    {
        $this->sessions = new ShellSessionManager(new NullLogger, 100, 5, 5);
        $this->tool = new ShellWriteTool($this->sessions);
    }

    public function test_name_returns_shell_write(): void
    {
        $this->assertSame('shell_write', $this->tool->name());
    }

    public function test_description_is_non_empty(): void
    {
        $this->assertNotEmpty($this->tool->description());
    }

    public function test_parameters_structure(): void
    {
        $params = $this->tool->parameters();

        $this->assertArrayHasKey('session_id', $params);
        $this->assertSame('string', $params['session_id']['type']);
        $this->assertArrayHasKey('input', $params);
        $this->assertSame('string', $params['input']['type']);
        $this->assertArrayHasKey('submit', $params);
        $this->assertSame('boolean', $params['submit']['type']);
        $this->assertArrayHasKey('wait_ms', $params);
        $this->assertSame('integer', $params['wait_ms']['type']);
    }

    public function test_required_parameters_contains_session_id_and_input(): void
    {
        $required = $this->tool->requiredParameters();

        $this->assertContains('session_id', $required);
        $this->assertContains('input', $required);
    }

    public function test_execute_calls_write_and_returns_success(): void
    {
        $start = $this->sessions->start('cat', waitMs: 200);

        $result = $this->tool->execute([
            'session_id' => $start['id'],
            'input' => 'hello',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('hello', $result->output);

        $this->sessions->kill($start['id']);
    }

    public function test_execute_with_empty_session_id_returns_error(): void
    {
        $result = $this->tool->execute([
            'session_id' => '',
            'input' => 'echo hello',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('session_id is required', $result->output);
    }

    public function test_execute_with_whitespace_session_id_returns_error(): void
    {
        $result = $this->tool->execute([
            'session_id' => '   ',
            'input' => 'echo hello',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('session_id is required', $result->output);
    }

    public function test_execute_when_session_not_found_returns_error(): void
    {
        $result = $this->tool->execute([
            'session_id' => 'nonexistent-session-id',
            'input' => 'echo hello',
        ]);

        $this->assertFalse($result->success);
    }
}

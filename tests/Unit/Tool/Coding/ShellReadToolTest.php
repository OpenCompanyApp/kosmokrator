<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\ShellReadTool;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ShellReadToolTest extends TestCase
{
    private ShellSessionManager $sessions;

    private ShellReadTool $tool;

    protected function setUp(): void
    {
        $this->sessions = new ShellSessionManager(new NullLogger, 100, 5, 5);
        $this->tool = new ShellReadTool($this->sessions);
    }

    public function test_name_returns_shell_read(): void
    {
        $this->assertSame('shell_read', $this->tool->name());
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
        $this->assertArrayHasKey('wait_ms', $params);
        $this->assertSame('integer', $params['wait_ms']['type']);
    }

    public function test_required_parameters_contains_session_id(): void
    {
        $this->assertContains('session_id', $this->tool->requiredParameters());
    }

    public function test_execute_calls_read_and_returns_success(): void
    {
        $start = $this->sessions->start('sleep 5', waitMs: 100);

        // Write input so there is something to read
        $this->sessions->write($start['id'], 'test', waitMs: 100);

        $result = $this->tool->execute(['session_id' => $start['id']]);

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->output);

        $this->sessions->kill($start['id']);
    }

    public function test_execute_with_empty_session_id_returns_error(): void
    {
        $result = $this->tool->execute(['session_id' => '']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('session_id is required', $result->output);
    }

    public function test_execute_with_whitespace_session_id_returns_error(): void
    {
        $result = $this->tool->execute(['session_id' => '   ']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('session_id is required', $result->output);
    }

    public function test_execute_when_session_not_found_returns_error(): void
    {
        $result = $this->tool->execute(['session_id' => 'nonexistent-session-id']);

        $this->assertFalse($result->success);
    }
}

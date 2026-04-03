<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Coding\ShellStartTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ShellStartToolTest extends TestCase
{
    private ShellStartTool $tool;

    private ShellSessionManager $sessions;

    protected function setUp(): void
    {
        $this->sessions = new ShellSessionManager(new NullLogger, 100, 5, 5);
        $this->tool = new ShellStartTool($this->sessions);
    }

    public function test_name_returns_shell_start(): void
    {
        $this->assertSame('shell_start', $this->tool->name());
    }

    public function test_description_is_non_empty(): void
    {
        $this->assertNotEmpty($this->tool->description());
    }

    public function test_parameters_structure(): void
    {
        $params = $this->tool->parameters();

        $this->assertArrayHasKey('command', $params);
        $this->assertSame('string', $params['command']['type']);
        $this->assertArrayHasKey('cwd', $params);
        $this->assertArrayHasKey('timeout', $params);
        $this->assertArrayHasKey('wait_ms', $params);
    }

    public function test_required_parameters_contains_command(): void
    {
        $this->assertContains('command', $this->tool->requiredParameters());
    }

    public function test_execute_with_valid_command_calls_start(): void
    {
        $result = $this->tool->execute(['command' => 'printf hello']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('hello', $result->output);
    }

    public function test_execute_with_empty_command_returns_error(): void
    {
        $result = $this->tool->execute(['command' => '']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No command provided', $result->output);
    }

    public function test_execute_with_whitespace_only_command_returns_error(): void
    {
        $result = $this->tool->execute(['command' => '   ']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No command provided', $result->output);
    }

    public function test_execute_when_start_throws_returns_error(): void
    {
        // Use a non-existent working directory to trigger an exception from Process::start()
        $result = $this->tool->execute([
            'command' => 'echo fail',
            'cwd' => '/no/such/directory/kosmokrator_test_'.uniqid(),
        ]);

        $this->assertFalse($result->success);
    }
}

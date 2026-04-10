<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\Tool;

use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\Tool\Coding\ShellKillTool;
use Kosmokrator\Tool\Coding\ShellReadTool;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Coding\ShellStartTool;
use Kosmokrator\Tool\Coding\ShellWriteTool;
use Psr\Log\NullLogger;

/**
 * Integration tests for the full shell session lifecycle: start → write → read → kill.
 * Uses real Amp processes.
 */
class ShellLifecycleTest extends IntegrationTestCase
{
    private ShellSessionManager $sessionManager;

    private ShellStartTool $startTool;

    private ShellWriteTool $writeTool;

    private ShellReadTool $readTool;

    private ShellKillTool $killTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionManager = new ShellSessionManager(new NullLogger);
        $this->startTool = new ShellStartTool($this->sessionManager);
        $this->writeTool = new ShellWriteTool($this->sessionManager);
        $this->readTool = new ShellReadTool($this->sessionManager);
        $this->killTool = new ShellKillTool($this->sessionManager);
    }

    protected function tearDown(): void
    {
        // Kill all active sessions before temp dir cleanup
        // (SessionManager doesn't expose a cleanup method, so we rely on process termination)
        parent::tearDown();
    }

    public function test_start_bash_and_read_prompt(): void
    {
        $result = \Amp\async(fn () => $this->startTool->execute([
            'command' => 'bash',
            'wait_ms' => 500,
        ]))->await();

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->output);
    }

    public function test_start_echo_and_read_output(): void
    {
        $result = \Amp\async(fn () => $this->startTool->execute([
            'command' => 'echo "immediate output"',
            'wait_ms' => 200,
        ]))->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('immediate output', $result->output);
    }

    public function test_start_write_read_lifecycle(): void
    {
        // Start a bash session
        $startResult = \Amp\async(fn () => $this->startTool->execute([
            'command' => 'bash --norc --noprofile',
            'wait_ms' => 200,
        ]))->await();

        $this->assertTrue($startResult->success);

        // Extract session ID from either the legacy or current output format.
        preg_match('/Session(?: ID:)? (sh_\d+)/', $startResult->output, $matches);
        $sessionId = $matches[1] ?? null;
        $this->assertNotNull($sessionId, 'Could not extract session ID from: '.$startResult->output);

        // Write a command
        $writeResult = \Amp\async(fn () => $this->writeTool->execute([
            'session_id' => $sessionId,
            'input' => 'echo "hello from shell"',
            'wait_ms' => 300,
        ]))->await();

        $this->assertTrue($writeResult->success);
        $this->assertStringContainsString('hello from shell', $writeResult->output);

        // Kill the session
        $killResult = \Amp\async(fn () => $this->killTool->execute([
            'session_id' => $sessionId,
        ]))->await();

        $this->assertTrue($killResult->success);
    }

    public function test_kill_nonexistent_session_returns_error(): void
    {
        $result = $this->killTool->execute([
            'session_id' => 'nonexistent-session',
        ]);

        $this->assertFalse($result->success);
    }

    public function test_write_to_nonexistent_session_returns_error(): void
    {
        $result = $this->writeTool->execute([
            'session_id' => 'nonexistent-session',
            'input' => 'echo hi',
        ]);

        $this->assertFalse($result->success);
    }
}

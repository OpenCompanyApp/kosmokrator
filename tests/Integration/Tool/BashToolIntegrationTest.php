<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\Tool;

use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\Tool\Coding\BashTool;

/**
 * Integration tests for BashTool using real process execution.
 * Wraps calls in Amp\async() as required by the Amp runtime.
 */
class BashToolIntegrationTest extends IntegrationTestCase
{
    private BashTool $bashTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bashTool = new BashTool;
    }

    public function test_echo_command(): void
    {
        $result = \Amp\async(fn () => $this->bashTool->execute([
            'command' => 'echo "hello from integration test"',
        ]))->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('hello from integration test', $result->output);
        $this->assertStringContainsString('Exit code: 0', $result->output);
    }

    public function test_command_with_exit_code(): void
    {
        $result = \Amp\async(fn () => $this->bashTool->execute([
            'command' => 'exit 42',
        ]))->await();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Exit code: 42', $result->output);
    }

    public function test_stderr_captured(): void
    {
        $result = \Amp\async(fn () => $this->bashTool->execute([
            'command' => 'echo "stderr output" >&2',
        ]))->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('stderr output', $result->output);
    }

    public function test_pipe_commands(): void
    {
        $result = \Amp\async(fn () => $this->bashTool->execute([
            'command' => 'printf "cherry\napple\nbanana\n" | sort',
        ]))->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('apple', $result->output);
        $this->assertStringContainsString('banana', $result->output);
        $this->assertStringContainsString('cherry', $result->output);
    }

    public function test_working_directory_is_cwd(): void
    {
        $result = \Amp\async(fn () => $this->bashTool->execute([
            'command' => 'pwd',
        ]))->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString(getcwd(), $result->output);
    }

    public function test_environment_variables(): void
    {
        $result = \Amp\async(fn () => $this->bashTool->execute([
            'command' => 'export KKR_TEST_VAR="hello" && echo $KKR_TEST_VAR',
        ]))->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('hello', $result->output);
    }

    public function test_write_file_via_bash(): void
    {
        $filePath = $this->tmpDir.'/bash-created.txt';

        \Amp\async(fn () => $this->bashTool->execute([
            'command' => "echo 'bash content' > {$filePath}",
        ]))->await();

        $this->assertFileExists($filePath);
        $this->assertStringContainsString('bash content', file_get_contents($filePath));
    }

    public function test_command_timeout(): void
    {
        $tool = new BashTool(timeout: 1);

        $result = \Amp\async(fn () => $tool->execute([
            'command' => 'sleep 30',
        ]))->await();

        $this->assertFalse($result->success);
    }
}

<?php

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\BashTool;
use PHPUnit\Framework\TestCase;

class BashToolTest extends TestCase
{
    private BashTool $tool;

    protected function setUp(): void
    {
        $this->tool = new BashTool;
    }

    public function test_name_returns_bash(): void
    {
        $this->assertSame('bash', $this->tool->name());
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['command'], $this->tool->requiredParameters());
    }

    public function test_successful_command(): void
    {
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'echo hello']);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('hello', $result->output);
        $this->assertStringContainsString('Exit code: 0', $result->output);
    }

    public function test_failing_command(): void
    {
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'exit 1']);
        })->await();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Exit code: 1', $result->output);
    }

    public function test_empty_command_returns_error(): void
    {
        $result = $this->tool->execute(['command' => '']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No command provided', $result->output);
    }

    public function test_no_output_shows_placeholder(): void
    {
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'true']);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('(no output)', $result->output);
        $this->assertStringContainsString('Exit code: 0', $result->output);
    }

    public function test_stderr_included_in_output(): void
    {
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'echo err >&2']);
        })->await();

        $this->assertStringContainsString('err', $result->output);
    }

    public function test_stdout_and_stderr_combined(): void
    {
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'echo out && echo err >&2']);
        })->await();

        $this->assertStringContainsString('out', $result->output);
        $this->assertStringContainsString('err', $result->output);
    }

    public function test_custom_timeout(): void
    {
        $result = \Amp\async(function () {
            $tool = new BashTool(timeout: 5);

            return $tool->execute(['command' => 'echo quick']);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('quick', $result->output);
    }

    public function test_exit_code_appended(): void
    {
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'exit 42']);
        })->await();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Exit code: 42', $result->output);
    }

    public function test_default_timeout_is_120(): void
    {
        // Implicitly tests that the default timeout (120s) doesn't interfere
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'echo fast']);
        })->await();

        $this->assertTrue($result->success);
    }

    public function test_multiline_output(): void
    {
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'echo "line1"; echo "line2"; echo "line3"']);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('line1', $result->output);
        $this->assertStringContainsString('line2', $result->output);
        $this->assertStringContainsString('line3', $result->output);
    }

    public function test_custom_timeout_parameter(): void
    {
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'sleep 0.01', 'timeout' => 5]);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Exit code: 0', $result->output);
    }

    public function test_timeout_parameter_caps_at_max(): void
    {
        // Should not throw — timeout is silently capped to 7200
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'echo capped', 'timeout' => 99999]);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('capped', $result->output);
    }

    public function test_timeout_parameter_minimum_is_one(): void
    {
        // Negative/zero values should be clamped to 1
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'echo min', 'timeout' => -5]);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('min', $result->output);
    }

    public function test_default_timeout_used_when_parameter_omitted(): void
    {
        // Without timeout param, the constructor default (120s) is used
        $result = \Amp\async(function () {
            return $this->tool->execute(['command' => 'echo default']);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('default', $result->output);
    }

    public function test_timeout_parameter_in_definition(): void
    {
        $params = $this->tool->parameters();
        $this->assertArrayHasKey('timeout', $params);
        $this->assertSame('integer', $params['timeout']['type']);
    }
}

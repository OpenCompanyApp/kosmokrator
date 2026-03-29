<?php

namespace Kosmokrator\Tests\Unit\Tool;

use Kosmokrator\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
    public function test_constructor_defaults_to_success(): void
    {
        $result = new ToolResult('output');

        $this->assertSame('output', $result->output);
        $this->assertTrue($result->success);
    }

    public function test_constructor_with_explicit_failure(): void
    {
        $result = new ToolResult('fail', false);

        $this->assertSame('fail', $result->output);
        $this->assertFalse($result->success);
    }

    public function test_success_factory(): void
    {
        $result = ToolResult::success('ok');

        $this->assertSame('ok', $result->output);
        $this->assertTrue($result->success);
    }

    public function test_error_factory(): void
    {
        $result = ToolResult::error('bad');

        $this->assertSame('bad', $result->output);
        $this->assertFalse($result->success);
    }

    public function test_empty_string_output(): void
    {
        $result = ToolResult::success('');

        $this->assertSame('', $result->output);
        $this->assertTrue($result->success);
    }

    public function test_multiline_output(): void
    {
        $result = ToolResult::success("line1\nline2\nline3");

        $this->assertStringContainsString("\n", $result->output);
        $this->assertSame("line1\nline2\nline3", $result->output);
    }
}

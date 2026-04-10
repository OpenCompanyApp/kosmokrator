<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\ToolCallMapper;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

class ToolCallMapperTest extends TestCase
{
    public function test_to_tool_result_creates_result_with_correct_fields(): void
    {
        $result = ToolCallMapper::toToolResult('tc1', 'file_read', ['path' => '/foo.txt'], 'file contents');

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('tc1', $result->toolCallId);
        $this->assertSame('file_read', $result->toolName);
        $this->assertSame(['path' => '/foo.txt'], $result->args);
        $this->assertSame('file contents', $result->result);
    }

    public function test_to_error_result_creates_result_with_error_text(): void
    {
        $result = ToolCallMapper::toErrorResult('tc2', 'bash', ['command' => 'ls'], 'command failed');

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('tc2', $result->toolCallId);
        $this->assertSame('bash', $result->toolName);
        $this->assertSame(ToolCallMapper::ERROR_PREFIX.'command failed', $result->result);
    }

    public function test_with_replaced_content_preserves_metadata(): void
    {
        $original = new ToolResult('tc1', 'file_read', ['path' => '/big.txt'], 'very long content...');

        $replaced = ToolCallMapper::withReplacedContent($original, '[pruned]');

        $this->assertSame('tc1', $replaced->toolCallId);
        $this->assertSame('file_read', $replaced->toolName);
        $this->assertSame(['path' => '/big.txt'], $replaced->args);
        $this->assertSame('[pruned]', $replaced->result);
    }

    public function test_extract_call_returns_name_args_id(): void
    {
        $call = new ToolCall(id: 'tc3', name: 'grep', arguments: ['pattern' => 'foo', 'path' => '/src']);

        $extracted = ToolCallMapper::extractCall($call);

        $this->assertSame('grep', $extracted['name']);
        $this->assertSame(['pattern' => 'foo', 'path' => '/src'], $extracted['args']);
        $this->assertSame('tc3', $extracted['id']);
    }

    public function test_extract_call_handles_json_string_arguments(): void
    {
        $call = new ToolCall(id: 'tc4', name: 'bash', arguments: '{"command": "ls -la"}');

        $extracted = ToolCallMapper::extractCall($call);

        $this->assertSame(['command' => 'ls -la'], $extracted['args']);
    }

    public function test_safe_arguments_returns_empty_array_for_malformed_json(): void
    {
        $call = new ToolCall(id: 'tc_bad', name: 'bash', arguments: '{"command":');

        $this->assertSame([], ToolCallMapper::safeArguments($call));
    }

    public function test_try_extract_call_reports_decode_errors_without_throwing(): void
    {
        $call = new ToolCall(id: 'tc_bad', name: 'bash', arguments: '{"command":');

        $extracted = ToolCallMapper::tryExtractCall($call);

        $this->assertSame('bash', $extracted['name']);
        $this->assertSame([], $extracted['args']);
        $this->assertSame('tc_bad', $extracted['id']);
        $this->assertSame('Syntax error', $extracted['argumentsError']);
    }

    public function test_normalize_tool_output_string(): void
    {
        $this->assertSame('hello', ToolCallMapper::normalizeToolOutput('hello'));
    }

    public function test_normalize_tool_output_tool_output(): void
    {
        $output = new ToolOutput('file contents here');

        $this->assertSame('file contents here', ToolCallMapper::normalizeToolOutput($output));
    }

    public function test_normalize_tool_output_tool_error(): void
    {
        $error = new ToolError('something went wrong');

        $this->assertSame(ToolCallMapper::ERROR_PREFIX.'something went wrong', ToolCallMapper::normalizeToolOutput($error));
    }

    public function test_normalize_tool_output_other_type(): void
    {
        $this->assertSame('42', ToolCallMapper::normalizeToolOutput(42));
    }
}

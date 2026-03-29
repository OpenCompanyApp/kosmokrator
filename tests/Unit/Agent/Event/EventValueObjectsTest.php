<?php

namespace Kosmokrator\Tests\Unit\Agent\Event;

use Kosmokrator\Agent\Event\ResponseCompleteEvent;
use Kosmokrator\Agent\Event\StreamChunkEvent;
use Kosmokrator\Agent\Event\ThinkingEvent;
use Kosmokrator\Agent\Event\ToolCallEvent;
use Kosmokrator\Agent\Event\ToolResultEvent;
use PHPUnit\Framework\TestCase;

class EventValueObjectsTest extends TestCase
{
    public function test_thinking_event(): void
    {
        $event = new ThinkingEvent(model: 'claude-4', provider: 'anthropic');

        $this->assertSame('claude-4', $event->model);
        $this->assertSame('anthropic', $event->provider);
    }

    public function test_stream_chunk_event(): void
    {
        $event = new StreamChunkEvent(text: 'Hello world');

        $this->assertSame('Hello world', $event->text);
    }

    public function test_stream_chunk_event_with_empty_text(): void
    {
        $event = new StreamChunkEvent(text: '');

        $this->assertSame('', $event->text);
    }

    public function test_tool_call_event(): void
    {
        $event = new ToolCallEvent(name: 'file_read', arguments: ['path' => '/tmp/test']);

        $this->assertSame('file_read', $event->name);
        $this->assertSame(['path' => '/tmp/test'], $event->arguments);
    }

    public function test_tool_call_event_with_empty_arguments(): void
    {
        $event = new ToolCallEvent(name: 'bash', arguments: []);

        $this->assertEmpty($event->arguments);
    }

    public function test_tool_result_event_success(): void
    {
        $event = new ToolResultEvent(name: 'file_read', output: 'contents', success: true);

        $this->assertSame('file_read', $event->name);
        $this->assertSame('contents', $event->output);
        $this->assertTrue($event->success);
    }

    public function test_tool_result_event_failure(): void
    {
        $event = new ToolResultEvent(name: 'bash', output: 'Error: failed', success: false);

        $this->assertFalse($event->success);
        $this->assertSame('Error: failed', $event->output);
    }

    public function test_response_complete_event(): void
    {
        $event = new ResponseCompleteEvent(text: 'Final response', tokensIn: 1000, tokensOut: 500);

        $this->assertSame('Final response', $event->text);
        $this->assertSame(1000, $event->tokensIn);
        $this->assertSame(500, $event->tokensOut);
    }
}

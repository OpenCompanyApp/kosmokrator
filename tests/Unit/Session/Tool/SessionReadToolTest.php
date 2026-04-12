<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\Tool\SessionReadTool;
use PHPUnit\Framework\TestCase;

class SessionReadToolTest extends TestCase
{
    private SessionManager $session;

    private SessionReadTool $tool;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionManager::class);
        $this->tool = new SessionReadTool($this->session);
    }

    public function test_name(): void
    {
        $this->assertSame('session_read', $this->tool->name());
    }

    public function test_session_id_is_required(): void
    {
        $this->assertSame(['session_id'], $this->tool->requiredParameters());
    }

    public function test_returns_formatted_transcript(): void
    {
        $this->session->expects($this->once())
            ->method('findSession')
            ->with('abcd1234')
            ->willReturn([
                'id' => 'abcd1234-full-uuid',
                'title' => 'Auth refactor',
                'model' => 'claude-3',
                'created_at' => '2026-04-09T10:00:00+00:00',
            ]);

        $this->session->expects($this->once())
            ->method('loadSessionTranscript')
            ->with('abcd1234-full-uuid', 50)
            ->willReturn([
                ['role' => 'user', 'content' => 'Fix the auth bug', 'tool_calls' => null, 'created_at' => '2026-04-09T10:00:00'],
                ['role' => 'assistant', 'content' => 'I found the issue in AuthController', 'tool_calls' => null, 'created_at' => '2026-04-09T10:01:00'],
            ]);

        $result = $this->tool->execute(['session_id' => 'abcd1234']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Auth refactor', $result->output);
        $this->assertStringContainsString('claude-3', $result->output);
        $this->assertStringContainsString('[USER]: Fix the auth bug', $result->output);
        $this->assertStringContainsString('[ASSISTANT]: I found the issue', $result->output);
    }

    public function test_session_not_found(): void
    {
        $this->session->expects($this->once())
            ->method('findSession')
            ->willReturn(null);

        $result = $this->tool->execute(['session_id' => 'nonexistent']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No session found', $result->output);
    }

    public function test_empty_session_id_returns_error(): void
    {
        $this->session->expects($this->never())->method('findSession');

        $result = $this->tool->execute(['session_id' => '  ']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('session_id is required', $result->output);
    }

    public function test_respects_limit_parameter(): void
    {
        $this->session->method('findSession')->willReturn([
            'id' => 'sid', 'title' => 'Test', 'model' => 'm', 'created_at' => '2026-01-01',
        ]);
        $this->session->expects($this->once())
            ->method('loadSessionTranscript')
            ->with('sid', 10)
            ->willReturn([]);

        $this->tool->execute(['session_id' => 'sid', 'limit' => 10]);
    }

    public function test_limit_bounded_to_200(): void
    {
        $this->session->method('findSession')->willReturn([
            'id' => 'sid', 'title' => 'Test', 'model' => 'm', 'created_at' => '2026-01-01',
        ]);
        $this->session->expects($this->once())
            ->method('loadSessionTranscript')
            ->with('sid', 200)
            ->willReturn([]);

        $this->tool->execute(['session_id' => 'sid', 'limit' => 999]);
    }

    public function test_shows_tool_calls_when_no_content(): void
    {
        $this->session->method('findSession')->willReturn([
            'id' => 'sid', 'title' => 'Test', 'model' => 'm', 'created_at' => '2026-01-01',
        ]);
        $this->session->method('loadSessionTranscript')->willReturn([
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => json_encode([['name' => 'file_read'], ['name' => 'grep']]),
                'created_at' => '2026-01-01',
            ],
        ]);

        $result = $this->tool->execute(['session_id' => 'sid']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('[Called: file_read, grep]', $result->output);
    }

    public function test_empty_session_shows_message(): void
    {
        $this->session->method('findSession')->willReturn([
            'id' => 'sid', 'title' => 'Empty Session', 'model' => 'm', 'created_at' => '2026-01-01',
        ]);
        $this->session->method('loadSessionTranscript')->willReturn([]);

        $result = $this->tool->execute(['session_id' => 'sid']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('exists but has no messages', $result->output);
    }
}

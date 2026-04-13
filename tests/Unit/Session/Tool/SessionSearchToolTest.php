<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\Tool\SessionSearchTool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SessionSearchToolTest extends TestCase
{
    private SessionManager $session;

    private SessionSearchTool $tool;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionManager::class);
        $this->tool = new SessionSearchTool($this->session);
    }

    public function test_name(): void
    {
        $this->assertSame('session_search', $this->tool->name());
    }

    public function test_no_required_parameters(): void
    {
        $this->assertSame([], $this->tool->requiredParameters());
    }

    public function test_browse_mode_returns_recent_sessions(): void
    {
        $this->session->expects($this->once())
            ->method('listSessions')
            ->with(5)
            ->willReturn([
                [
                    'id' => 'abcd1234-0000-0000-0000-000000000000',
                    'title' => 'Auth refactor',
                    'updated_at' => '1712000000.000000',
                    'message_count' => 12,
                    'last_user_message' => 'Fix the JWT token refresh',
                ],
            ]);

        $result = $this->tool->execute([]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Recent sessions', $result->output);
        $this->assertStringContainsString('[abcd1234]', $result->output);
        $this->assertStringContainsString('Auth refactor', $result->output);
        $this->assertStringContainsString('12 msgs', $result->output);
        $this->assertStringContainsString('Fix the JWT token refresh', $result->output);
    }

    public function test_browse_mode_empty_project(): void
    {
        $this->session->expects($this->once())
            ->method('listSessions')
            ->willReturn([]);

        $result = $this->tool->execute([]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('No prior sessions', $result->output);
    }

    public function test_search_mode_returns_grouped_results(): void
    {
        $this->session->expects($this->once())
            ->method('searchSessionHistoryGrouped')
            ->with('jwt auth', 5)
            ->willReturn([
                [
                    'session_id' => 'sess1234-0000-0000-0000-000000000000',
                    'title' => 'Auth session',
                    'updated_at' => '2026-04-09T10:00:00+00:00',
                    'match_count' => 3,
                    'best_match' => [
                        'role' => 'assistant',
                        'content' => 'We switched to JWT auth with refresh tokens',
                        'created_at' => '2026-04-09T10:05:00+00:00',
                    ],
                    'context' => [
                        ['role' => 'user', 'content' => 'How should we handle auth?'],
                    ],
                ],
            ]);

        $result = $this->tool->execute(['query' => 'jwt auth']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('1 session(s)', $result->output);
        $this->assertStringContainsString('[sess1234]', $result->output);
        $this->assertStringContainsString('Auth session', $result->output);
        $this->assertStringContainsString('3 matches', $result->output);
        $this->assertStringContainsString('JWT auth with refresh tokens', $result->output);
        $this->assertStringContainsString('[USER]', $result->output);
    }

    public function test_search_mode_no_results(): void
    {
        $this->session->expects($this->once())
            ->method('searchSessionHistoryGrouped')
            ->willReturn([]);

        $result = $this->tool->execute(['query' => 'nonexistent']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('No session history matches', $result->output);
    }

    public function test_search_uses_bounded_limit(): void
    {
        $this->session->expects($this->once())
            ->method('searchSessionHistoryGrouped')
            ->with('jwt', 10)
            ->willReturn([]);

        $this->tool->execute(['query' => 'jwt', 'limit' => 99]);
    }

    public function test_empty_query_triggers_browse_mode(): void
    {
        $this->session->expects($this->once())
            ->method('listSessions')
            ->willReturn([]);
        $this->session->expects($this->never())
            ->method('searchSessionHistoryGrouped');

        $this->tool->execute(['query' => '   ']);
    }
}

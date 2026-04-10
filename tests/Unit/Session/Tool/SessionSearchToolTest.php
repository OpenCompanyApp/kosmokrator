<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\Tool\SessionSearchTool;
use PHPUnit\Framework\TestCase;

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

    public function test_query_is_required(): void
    {
        $this->assertSame(['query'], $this->tool->requiredParameters());
    }

    public function test_search_returns_formatted_results(): void
    {
        $this->session->expects($this->once())
            ->method('searchSessionHistory')
            ->with('jwt auth', 8)
            ->willReturn([
                ['session_id' => 'sess1', 'title' => 'Auth session', 'role' => 'assistant', 'content' => 'We switched to JWT auth', 'updated_at' => '2026-04-09T10:00:00+00:00'],
            ]);

        $result = $this->tool->execute(['query' => 'jwt auth']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Found 1 session history matches', $result->output);
        $this->assertStringContainsString('Auth session (2026-04-09) [assistant]: We switched to JWT auth', $result->output);
    }

    public function test_search_uses_bounded_limit(): void
    {
        $this->session->expects($this->once())
            ->method('searchSessionHistory')
            ->with('jwt', 20)
            ->willReturn([]);

        $result = $this->tool->execute(['query' => 'jwt', 'limit' => 99]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('No session history matches found.', $result->output);
    }

    public function test_empty_query_returns_error(): void
    {
        $this->session->expects($this->never())->method('searchSessionHistory');

        $result = $this->tool->execute(['query' => '   ']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Query is required.', $result->output);
    }
}

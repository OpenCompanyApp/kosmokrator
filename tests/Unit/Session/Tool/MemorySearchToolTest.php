<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\Tool\MemorySearchTool;
use PHPUnit\Framework\TestCase;

class MemorySearchToolTest extends TestCase
{
    private SessionManager $session;

    private MemorySearchTool $tool;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionManager::class);
        $this->tool = new MemorySearchTool($this->session);
    }

    public function test_name(): void
    {
        $this->assertSame('memory_search', $this->tool->name());
    }

    public function test_no_required_parameters(): void
    {
        $this->assertSame([], $this->tool->requiredParameters());
    }

    public function test_search_returns_formatted_results(): void
    {
        $this->session->expects($this->once())
            ->method('searchMemories')
            ->willReturn([
                ['id' => 5, 'type' => 'project', 'title' => 'JWT Auth', 'content' => 'Uses JWT tokens', 'created_at' => '2026-03-15T10:00:00+00:00'],
                ['id' => 8, 'type' => 'decision', 'title' => 'DB Driver', 'content' => 'Chose SQLite', 'created_at' => '2026-03-20T10:00:00+00:00'],
            ]);

        $result = $this->tool->execute([]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Found 2 memories', $result->output);
        $this->assertStringContainsString('#5 [project] JWT Auth (2026-03-15)', $result->output);
        $this->assertStringContainsString('Uses JWT tokens', $result->output);
        $this->assertStringContainsString('#8 [decision] DB Driver (2026-03-20)', $result->output);
    }

    public function test_search_empty(): void
    {
        $this->session->expects($this->once())
            ->method('searchMemories')
            ->willReturn([]);

        $result = $this->tool->execute([]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('No memories found', $result->output);
    }

    public function test_search_with_type_filter(): void
    {
        $this->session->expects($this->once())
            ->method('searchMemories')
            ->with('project', null);

        $this->tool->execute(['type' => 'project']);
    }

    public function test_search_with_query(): void
    {
        $this->session->expects($this->once())
            ->method('searchMemories')
            ->with(null, 'JWT');

        $this->tool->execute(['query' => 'JWT']);
    }

    public function test_invalid_type_filter(): void
    {
        $result = $this->tool->execute(['type' => 'bogus']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid memory type', $result->output);
    }
}

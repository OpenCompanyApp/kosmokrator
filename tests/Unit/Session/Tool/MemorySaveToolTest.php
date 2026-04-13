<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\Tool\MemorySaveTool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MemorySaveToolTest extends TestCase
{
    private SessionManager $session;

    private MemorySaveTool $tool;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionManager::class);
        $this->tool = new MemorySaveTool($this->session);
    }

    public function test_name(): void
    {
        $this->assertSame('memory_save', $this->tool->name());
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['type', 'title', 'content'], $this->tool->requiredParameters());
    }

    public function test_create_memory(): void
    {
        $this->session->expects($this->once())
            ->method('addMemory')
            ->with('project', 'JWT Auth', 'Uses JWT tokens', 'durable', false, null)
            ->willReturn(42);

        $result = $this->tool->execute([
            'type' => 'project',
            'title' => 'JWT Auth',
            'content' => 'Uses JWT tokens',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('#42', $result->output);
        $this->assertStringContainsString('JWT Auth', $result->output);
    }

    public function test_update_memory(): void
    {
        $this->session->expects($this->once())
            ->method('findMemory')
            ->with(5)
            ->willReturn(['id' => 5, 'type' => 'project', 'title' => 'Old title', 'content' => 'Old content']);

        $this->session->expects($this->once())
            ->method('updateMemory')
            ->with(5, 'New content', 'New title', 'durable', false, null);

        $result = $this->tool->execute([
            'type' => 'project',
            'title' => 'New title',
            'content' => 'New content',
            'id' => '5',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Updated memory #5', $result->output);
    }

    public function test_update_nonexistent_memory(): void
    {
        $this->session->expects($this->once())
            ->method('findMemory')
            ->with(999)
            ->willReturn(null);

        $result = $this->tool->execute([
            'type' => 'project',
            'title' => 'Title',
            'content' => 'Content',
            'id' => '999',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('#999', $result->output);
    }

    public function test_invalid_type(): void
    {
        $result = $this->tool->execute([
            'type' => 'bogus',
            'title' => 'Title',
            'content' => 'Content',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid memory type', $result->output);
    }

    public function test_invalid_memory_class(): void
    {
        $result = $this->tool->execute([
            'type' => 'project',
            'class' => 'bogus',
            'title' => 'Title',
            'content' => 'Content',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid memory class', $result->output);
    }

    public function test_rejects_compaction_type(): void
    {
        $result = $this->tool->execute([
            'type' => 'compaction',
            'title' => 'Title',
            'content' => 'Content',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid memory type', $result->output);
    }
}

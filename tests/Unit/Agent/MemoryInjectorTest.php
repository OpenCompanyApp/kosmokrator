<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\MemoryInjector;
use PHPUnit\Framework\TestCase;

class MemoryInjectorTest extends TestCase
{
    // ── format() ──────────────────────────────────────────────────────

    public function test_format_empty_array_returns_empty_string(): void
    {
        $this->assertSame('', MemoryInjector::format([]));
    }

    public function test_format_priority_memories(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Critical rule',
                'content' => 'Never delete files',
                'type' => 'project',
                'memory_class' => 'priority',
                'created_at' => '2025-01-01 12:00:00',
            ],
        ]);

        $this->assertStringContainsString('## Priority Context', $result);
        $this->assertStringContainsString('Critical rule: Never delete files', $result);
        $this->assertStringNotContainsString('## Project Knowledge', $result);
    }

    public function test_format_project_memories_durable_only(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Architecture',
                'content' => 'Uses PHP 8.4',
                'type' => 'project',
                'memory_class' => 'durable',
                'created_at' => '2025-03-15 10:00:00',
            ],
        ]);

        $this->assertStringContainsString('## Project Knowledge', $result);
        $this->assertStringContainsString('Architecture: Uses PHP 8.4', $result);
        $this->assertStringContainsString('(2025-03-15)', $result);
    }

    public function test_format_project_memories_skips_non_durable(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Working note',
                'content' => 'Temporary',
                'type' => 'project',
                'memory_class' => 'working',
                'created_at' => '2025-03-15 10:00:00',
            ],
        ]);

        $this->assertStringNotContainsString('## Project Knowledge', $result);
    }

    public function test_format_user_preferences_durable_only(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Code style',
                'content' => 'Prefer strict types',
                'type' => 'user',
                'memory_class' => 'durable',
                'created_at' => '2025-01-01 00:00:00',
            ],
        ]);

        $this->assertStringContainsString('## User Preferences', $result);
        $this->assertStringContainsString('Code style: Prefer strict types', $result);
    }

    public function test_format_user_preferences_skips_non_durable(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Temp pref',
                'content' => 'Something',
                'type' => 'user',
                'memory_class' => 'working',
                'created_at' => '2025-01-01 00:00:00',
            ],
        ]);

        $this->assertStringNotContainsString('## User Preferences', $result);
    }

    public function test_format_decision_memories(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Use PostgreSQL',
                'content' => 'Chosen over MySQL for JSON support',
                'type' => 'decision',
                'memory_class' => 'durable',
                'created_at' => '2025-06-01 09:00:00',
            ],
        ]);

        $this->assertStringContainsString('## Key Decisions', $result);
        $this->assertStringContainsString('Use PostgreSQL: Chosen over MySQL for JSON support', $result);
        $this->assertStringContainsString('(2025-06-01)', $result);
    }

    public function test_format_decision_skips_non_durable(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Draft decision',
                'content' => 'Not finalized',
                'type' => 'decision',
                'memory_class' => 'working',
                'created_at' => '2025-01-01 00:00:00',
            ],
        ]);

        $this->assertStringNotContainsString('## Key Decisions', $result);
    }

    public function test_format_working_memory_capped_at_five(): void
    {
        $memories = [];
        for ($i = 1; $i <= 8; $i++) {
            $memories[] = [
                'title' => "Working item {$i}",
                'content' => "Content {$i}",
                'type' => 'project',
                'memory_class' => 'working',
                'created_at' => '2025-01-01 00:00:00',
            ];
        }

        $result = MemoryInjector::format($memories);

        $this->assertStringContainsString('## Working Memory', $result);
        $this->assertStringContainsString('Working item 1', $result);
        $this->assertStringContainsString('Working item 5', $result);
        $this->assertStringNotContainsString('Working item 6', $result);
        $this->assertStringNotContainsString('Working item 7', $result);
        $this->assertStringNotContainsString('Working item 8', $result);
    }

    public function test_format_compaction_capped_at_three(): void
    {
        $memories = [];
        for ($i = 1; $i <= 5; $i++) {
            $memories[] = [
                'title' => "Session {$i}",
                'content' => 'Summary...',
                'type' => 'compaction',
                'memory_class' => 'working',
                'created_at' => "2025-01-0{$i} 10:00:00",
            ];
        }

        $result = MemoryInjector::format($memories);

        $this->assertStringContainsString('## Previous Sessions', $result);
        $this->assertStringContainsString('Session 1', $result);
        $this->assertStringContainsString('Session 3', $result);
        $this->assertStringNotContainsString('Session 4', $result);
        $this->assertStringNotContainsString('Session 5', $result);
    }

    public function test_format_compaction_shows_date_prefix(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Refactored auth',
                'content' => 'Long summary here',
                'type' => 'compaction',
                'memory_class' => 'working',
                'created_at' => '2025-04-02 14:30:00',
            ],
        ]);

        $this->assertStringContainsString('## Previous Sessions', $result);
        $this->assertStringContainsString('[2025-04-02] Refactored auth', $result);
    }

    public function test_format_mixed_types_produce_multiple_sections(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Urgent',
                'content' => 'Do this first',
                'type' => 'project',
                'memory_class' => 'priority',
                'created_at' => '2025-01-01 00:00:00',
            ],
            [
                'title' => 'Framework',
                'content' => 'Laravel 12',
                'type' => 'project',
                'memory_class' => 'durable',
                'created_at' => '2025-01-01 00:00:00',
            ],
            [
                'title' => 'Theme',
                'content' => 'Dark mode',
                'type' => 'user',
                'memory_class' => 'durable',
                'created_at' => '2025-01-01 00:00:00',
            ],
            [
                'title' => 'Use Redis',
                'content' => 'For caching layer',
                'type' => 'decision',
                'memory_class' => 'durable',
                'created_at' => '2025-01-01 00:00:00',
            ],
            [
                'title' => 'Active task',
                'content' => 'Fixing bug #42',
                'type' => 'project',
                'memory_class' => 'working',
                'created_at' => '2025-01-01 00:00:00',
            ],
            [
                'title' => 'Past session',
                'content' => 'Implemented login',
                'type' => 'compaction',
                'memory_class' => 'working',
                'created_at' => '2025-01-01 00:00:00',
            ],
        ]);

        $this->assertStringContainsString('## Priority Context', $result);
        $this->assertStringContainsString('## Project Knowledge', $result);
        $this->assertStringContainsString('## User Preferences', $result);
        $this->assertStringContainsString('## Key Decisions', $result);
        $this->assertStringContainsString('## Working Memory', $result);
        $this->assertStringContainsString('## Previous Sessions', $result);
    }

    public function test_format_wraps_in_memories_header(): void
    {
        $result = MemoryInjector::format([
            [
                'title' => 'Test',
                'content' => 'Content',
                'type' => 'project',
                'memory_class' => 'durable',
                'created_at' => '2025-01-01 00:00:00',
            ],
        ]);

        $this->assertStringStartsWith("\n\n# Memories\n\n", $result);
    }

    public function test_format_truncates_long_content(): void
    {
        $longContent = str_repeat('x', 300);
        $result = MemoryInjector::format([
            [
                'title' => 'Long',
                'content' => $longContent,
                'type' => 'project',
                'memory_class' => 'durable',
                'created_at' => '2025-01-01 00:00:00',
            ],
        ]);

        // 220 chars + "..." for project knowledge
        $this->assertStringContainsString(str_repeat('x', 220).'...', $result);
        $this->assertStringNotContainsString(str_repeat('x', 221).'...', $result);
    }

    // ── formatSessionRecall() ─────────────────────────────────────────

    public function test_format_session_recall_empty_returns_empty_string(): void
    {
        $this->assertSame('', MemoryInjector::formatSessionRecall([]));
    }

    public function test_format_session_recall_with_rows(): void
    {
        $result = MemoryInjector::formatSessionRecall([
            [
                'title' => 'Fix login',
                'role' => 'assistant',
                'content' => 'Updated auth controller',
            ],
            [
                'title' => 'Add tests',
                'role' => 'user',
                'content' => 'Write unit tests for login',
            ],
        ]);

        $this->assertStringContainsString('## Session Recall', $result);
        $this->assertStringContainsString('Fix login [assistant]: Updated auth controller', $result);
        $this->assertStringContainsString('Add tests [user]: Write unit tests for login', $result);
    }

    public function test_format_session_recall_defaults_role_to_message(): void
    {
        $result = MemoryInjector::formatSessionRecall([
            [
                'title' => 'Some title',
                'content' => 'Some content',
            ],
        ]);

        $this->assertStringContainsString('Some title [message]: Some content', $result);
    }

    public function test_format_session_recall_defaults_title_to_session(): void
    {
        $result = MemoryInjector::formatSessionRecall([
            [
                'role' => 'assistant',
                'content' => 'Content here',
            ],
        ]);

        $this->assertStringContainsString('session [assistant]: Content here', $result);
    }

    public function test_format_session_recall_uses_session_id_fallback(): void
    {
        $result = MemoryInjector::formatSessionRecall([
            [
                'session_id' => 'abc-123',
                'role' => 'assistant',
                'content' => 'Did work',
            ],
        ]);

        $this->assertStringContainsString('abc-123 [assistant]: Did work', $result);
    }
}

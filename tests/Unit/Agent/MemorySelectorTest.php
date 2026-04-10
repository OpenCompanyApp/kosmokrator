<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\MemorySelector;
use PHPUnit\Framework\TestCase;

class MemorySelectorTest extends TestCase
{
    private MemorySelector $selector;

    protected function setUp(): void
    {
        $this->selector = new MemorySelector;
    }

    public function test_empty_memories_returns_empty(): void
    {
        $result = $this->selector->select([], null);
        $this->assertSame([], $result);
    }

    public function test_single_memory_returns_it(): void
    {
        $memory = ['title' => 'only one', 'memory_class' => 'durable', 'type' => 'project'];

        $result = $this->selector->select([$memory], null);

        $this->assertCount(1, $result);
        $this->assertSame('only one', $result[0]['title']);
    }

    public function test_limit_truncates_results(): void
    {
        $memories = [];
        for ($i = 0; $i < 5; $i++) {
            $memories[] = [
                'title' => "memory $i",
                'memory_class' => 'durable',
                'type' => 'project',
                'created_at' => "2025-01-0{$i}",
            ];
        }

        $result = $this->selector->select($memories, null, limit: 2);

        $this->assertCount(2, $result);
    }

    public function test_priority_class_beats_durable(): void
    {
        $durable = ['title' => 'durable', 'memory_class' => 'durable', 'type' => 'project'];
        $priority = ['title' => 'priority', 'memory_class' => 'priority', 'type' => 'project'];

        $result = $this->selector->select([$durable, $priority], null);

        $this->assertSame('priority', $result[0]['title']);
        $this->assertSame('durable', $result[1]['title']);
    }

    public function test_pinned_boosts_score(): void
    {
        $unpinned = ['title' => 'unpinned', 'memory_class' => 'durable', 'type' => 'project', 'pinned' => 0];
        $pinned = ['title' => 'pinned', 'memory_class' => 'durable', 'type' => 'project', 'pinned' => 1];

        $result = $this->selector->select([$unpinned, $pinned], null);

        $this->assertSame('pinned', $result[0]['title']);
        $this->assertSame('unpinned', $result[1]['title']);
    }

    public function test_query_terms_boost_matching_memories(): void
    {
        $noMatch = ['title' => 'irrelevant', 'content' => 'nothing useful', 'memory_class' => 'durable', 'type' => 'project'];
        $titleMatch = ['title' => 'database schema', 'content' => 'details here', 'memory_class' => 'durable', 'type' => 'project'];
        $contentMatch = ['title' => 'some title', 'content' => 'database connection settings', 'memory_class' => 'durable', 'type' => 'project'];

        $result = $this->selector->select([$noMatch, $titleMatch, $contentMatch], 'database');

        $this->assertSame('database schema', $result[0]['title']);
        $this->assertSame('some title', $result[1]['title']);
        $this->assertSame('irrelevant', $result[2]['title']);
    }

    public function test_decision_type_scores_higher_than_compaction(): void
    {
        $compaction = ['title' => 'compacted', 'memory_class' => 'durable', 'type' => 'compaction'];
        $decision = ['title' => 'decided', 'memory_class' => 'durable', 'type' => 'decision'];

        $result = $this->selector->select([$compaction, $decision], null);

        $this->assertSame('decided', $result[0]['title']);
        $this->assertSame('compacted', $result[1]['title']);
    }

    public function test_tie_breaking_by_date_most_recent_first(): void
    {
        $older = [
            'title' => 'older',
            'memory_class' => 'durable',
            'type' => 'project',
            'updated_at' => '2025-01-01',
        ];
        $newer = [
            'title' => 'newer',
            'memory_class' => 'durable',
            'type' => 'project',
            'updated_at' => '2025-06-15',
        ];

        $result = $this->selector->select([$older, $newer], null);

        $this->assertSame('newer', $result[0]['title']);
        $this->assertSame('older', $result[1]['title']);
    }

    public function test_tie_breaking_falls_back_to_created_at(): void
    {
        $older = [
            'title' => 'older',
            'memory_class' => 'durable',
            'type' => 'project',
            'created_at' => '2025-01-01',
        ];
        $newer = [
            'title' => 'newer',
            'memory_class' => 'durable',
            'type' => 'project',
            'created_at' => '2025-06-15',
        ];

        $result = $this->selector->select([$older, $newer], null);

        $this->assertSame('newer', $result[0]['title']);
        $this->assertSame('older', $result[1]['title']);
    }

    public function test_null_query_is_handled(): void
    {
        $memory = ['title' => 'test', 'memory_class' => 'durable', 'type' => 'project'];

        $result = $this->selector->select([$memory], null);

        $this->assertCount(1, $result);
        $this->assertSame('test', $result[0]['title']);
    }

    public function test_empty_query_is_handled(): void
    {
        $memory = ['title' => 'test', 'memory_class' => 'durable', 'type' => 'project'];

        $result = $this->selector->select([$memory], '');

        $this->assertCount(1, $result);
        $this->assertSame('test', $result[0]['title']);
    }

    public function test_short_terms_are_ignored(): void
    {
        // "it" and "to" are < 3 chars and should not match
        $memory = ['title' => 'it to', 'content' => 'it to', 'memory_class' => 'durable', 'type' => 'project'];
        $relevant = ['title' => 'database', 'content' => 'database', 'memory_class' => 'durable', 'type' => 'project'];

        // Query has "it to database" — only "database" is >= 3 chars
        $result = $this->selector->select([$memory, $relevant], 'it to database');

        $this->assertSame('database', $result[0]['title']);
    }

    public function test_exact_phrase_match_beats_broader_content_match(): void
    {
        $broad = ['title' => 'Auth notes', 'content' => 'We discussed jwt auth setup and follow-up items', 'memory_class' => 'durable', 'type' => 'project'];
        $exact = ['title' => 'jwt auth setup', 'content' => 'Implementation details', 'memory_class' => 'durable', 'type' => 'project'];

        $result = $this->selector->select([$broad, $exact], 'jwt auth setup');

        $this->assertSame('jwt auth setup', $result[0]['title']);
    }

    public function test_identifier_like_terms_boost_path_matches(): void
    {
        $pathMatch = ['title' => 'src/Session/Database.php', 'content' => 'touches the sqlite session store', 'memory_class' => 'durable', 'type' => 'project'];
        $generic = ['title' => 'database notes', 'content' => 'sqlite store details', 'memory_class' => 'durable', 'type' => 'project'];

        $result = $this->selector->select([$generic, $pathMatch], 'src/Session/Database.php');

        $this->assertSame('src/Session/Database.php', $result[0]['title']);
    }

    public function test_recent_decision_beats_old_decision(): void
    {
        $older = [
            'title' => 'Old decision',
            'content' => 'Prefer sqlite',
            'memory_class' => 'durable',
            'type' => 'decision',
            'updated_at' => date('c', time() - 200 * 86400),
        ];
        $recent = [
            'title' => 'Recent decision',
            'content' => 'Prefer sqlite',
            'memory_class' => 'durable',
            'type' => 'decision',
            'updated_at' => date('c', time() - 2 * 86400),
        ];

        $result = $this->selector->select([$older, $recent], 'sqlite');

        $this->assertSame('Recent decision', $result[0]['title']);
    }

    public function test_stale_working_memory_is_penalized_against_durable_match(): void
    {
        $staleWorking = [
            'title' => 'Temporary auth note',
            'content' => 'jwt auth details',
            'memory_class' => 'working',
            'type' => 'project',
            'last_surfaced_at' => date('c', time() - 90 * 86400),
        ];
        $durable = [
            'title' => 'Durable auth note',
            'content' => 'jwt auth details',
            'memory_class' => 'durable',
            'type' => 'project',
        ];

        $result = $this->selector->select([$staleWorking, $durable], 'jwt auth');

        $this->assertSame('Durable auth note', $result[0]['title']);
    }
}

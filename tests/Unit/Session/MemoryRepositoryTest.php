<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Session\Database;
use Kosmokrator\Session\MemoryRepository;
use PHPUnit\Framework\TestCase;

class MemoryRepositoryTest extends TestCase
{
    private MemoryRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new MemoryRepository(new Database(':memory:'));
    }

    public function test_add_and_find(): void
    {
        $id = $this->repo->add('project', 'Uses JWT auth', 'Auth middleware uses JWT tokens');

        $memory = $this->repo->find($id);
        $this->assertNotNull($memory);
        $this->assertSame('project', $memory['type']);
        $this->assertSame('durable', $memory['memory_class']);
        $this->assertSame('Uses JWT auth', $memory['title']);
        $this->assertSame('Auth middleware uses JWT tokens', $memory['content']);
    }

    public function test_for_project_includes_global(): void
    {
        $this->repo->add('project', 'Global fact', 'Something global', null);
        $this->repo->add('project', 'Project fact', 'Something project-specific', '/myproject');

        $memories = $this->repo->forProject('/myproject');
        $this->assertCount(2, $memories);

        $titles = array_column($memories, 'title');
        $this->assertContains('Global fact', $titles);
        $this->assertContains('Project fact', $titles);
    }

    public function test_for_project_excludes_other_projects(): void
    {
        $this->repo->add('project', 'Project A fact', 'content', '/projectA');
        $this->repo->add('project', 'Project B fact', 'content', '/projectB');

        $memories = $this->repo->forProject('/projectA');
        $this->assertCount(1, $memories);
        $this->assertSame('Project A fact', $memories[0]['title']);
    }

    public function test_delete(): void
    {
        $id = $this->repo->add('user', 'Pref', 'Prefers tabs');
        $this->repo->delete($id);

        $this->assertNull($this->repo->find($id));
    }

    public function test_update(): void
    {
        $id = $this->repo->add('decision', 'Auth choice', 'Use JWT');
        $this->repo->update($id, 'Use OAuth instead');

        $memory = $this->repo->find($id);
        $this->assertSame('Use OAuth instead', $memory['content']);
    }

    public function test_all_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repo->add('project', "Fact {$i}", "Content {$i}");
        }

        $all = $this->repo->all(null, 3);
        $this->assertCount(3, $all);
    }

    public function test_update_with_title(): void
    {
        $id = $this->repo->add('decision', 'Auth choice', 'Use JWT', '/proj');
        $this->repo->update($id, 'Use OAuth instead', 'Auth method');

        $memory = $this->repo->find($id);
        $this->assertSame('Use OAuth instead', $memory['content']);
        $this->assertSame('Auth method', $memory['title']);
    }

    public function test_search_by_type(): void
    {
        $this->repo->add('project', 'Fact A', 'Content A', '/proj');
        $this->repo->add('user', 'Pref B', 'Content B', '/proj');
        $this->repo->add('project', 'Fact C', 'Content C', '/proj');

        $results = $this->repo->search('/proj', 'project');
        $this->assertCount(2, $results);
        $this->assertSame('project', $results[0]['type']);
        $this->assertSame('project', $results[1]['type']);
    }

    public function test_search_by_query(): void
    {
        $this->repo->add('project', 'JWT Auth', 'Uses JWT tokens', '/proj');
        $this->repo->add('project', 'Database', 'Uses SQLite', '/proj');

        $results = $this->repo->search('/proj', null, 'JWT');
        $this->assertCount(1, $results);
        $this->assertSame('JWT Auth', $results[0]['title']);
    }

    public function test_search_prefers_exact_title_match_over_content_match(): void
    {
        $this->repo->add('project', 'Auth Notes', 'JWT Auth setup details', '/proj');
        $this->repo->add('project', 'JWT Auth', 'General auth summary', '/proj');

        $results = $this->repo->search('/proj', null, 'JWT Auth');

        $this->assertCount(2, $results);
        $this->assertSame('JWT Auth', $results[0]['title']);
    }

    public function test_search_prefers_priority_and_decision_when_query_ties(): void
    {
        $this->repo->add('project', 'Project JWT', 'JWT decision context', '/proj', null, 'durable');
        $this->repo->add('decision', 'Decision JWT', 'JWT decision context', '/proj', null, 'priority');

        $results = $this->repo->search('/proj', null, 'JWT');

        $this->assertCount(2, $results);
        $this->assertSame('Decision JWT', $results[0]['title']);
    }

    public function test_search_combined_type_and_query(): void
    {
        $this->repo->add('project', 'JWT Auth', 'Uses JWT tokens', '/proj');
        $this->repo->add('decision', 'JWT Decision', 'Chose JWT over OAuth', '/proj');

        $results = $this->repo->search('/proj', 'decision', 'JWT');
        $this->assertCount(1, $results);
        $this->assertSame('decision', $results[0]['type']);
    }

    public function test_search_no_results(): void
    {
        $this->repo->add('project', 'Fact', 'Content', '/proj');

        $results = $this->repo->search('/proj', null, 'nonexistent');
        $this->assertSame([], $results);
    }

    public function test_search_includes_global_memories(): void
    {
        $this->repo->add('user', 'Global pref', 'Prefers tabs', null);
        $this->repo->add('project', 'Project fact', 'Content', '/proj');

        $results = $this->repo->search('/proj');
        $this->assertCount(2, $results);
    }

    public function test_search_by_memory_class(): void
    {
        $this->repo->add('project', 'Priority fact', 'Important', '/proj', null, 'priority');
        $this->repo->add('project', 'Working fact', 'Temporary', '/proj', null, 'working');

        $results = $this->repo->search('/proj', null, null, 20, 'priority');

        $this->assertCount(1, $results);
        $this->assertSame('priority', $results[0]['memory_class']);
    }

    public function test_add_with_session_id(): void
    {
        // Create a session first for FK
        $db = new Database(':memory:');
        $repo = new MemoryRepository($db);

        // Insert a session manually for FK
        $db->connection()->exec("INSERT INTO sessions (id, project, created_at, updated_at) VALUES ('sess1', '/proj', '2026-01-01', '2026-01-01')");

        $id = $repo->add('compaction', 'Session summary', 'Did stuff', '/proj', 'sess1');
        $memory = $repo->find($id);

        $this->assertSame('sess1', $memory['session_id']);
        $this->assertSame('/proj', $memory['project']);
    }
}

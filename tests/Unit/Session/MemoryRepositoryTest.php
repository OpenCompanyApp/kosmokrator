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

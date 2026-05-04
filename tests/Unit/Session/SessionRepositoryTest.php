<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Session\Database;
use Kosmokrator\Session\SessionRepository;
use PHPUnit\Framework\TestCase;

class SessionRepositoryTest extends TestCase
{
    private Database $db;

    private SessionRepository $repo;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->repo = new SessionRepository($this->db);
    }

    public function test_create_returns_uuid(): void
    {
        $id = $this->repo->create('/path/to/project', 'gpt-4');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    public function test_find_returns_session(): void
    {
        $id = $this->repo->create('/project', 'model-1');
        $session = $this->repo->find($id);

        $this->assertNotNull($session);
        $this->assertSame($id, $session['id']);
        $this->assertSame('/project', $session['project']);
        $this->assertSame('model-1', $session['model']);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $this->assertNull($this->repo->find('nonexistent'));
    }

    public function test_update_title(): void
    {
        $id = $this->repo->create('/project', 'model-1');
        $this->repo->updateTitle($id, 'My Session');

        $session = $this->repo->find($id);
        $this->assertSame('My Session', $session['title']);
    }

    public function test_list_by_project_ordered_by_updated(): void
    {
        $id1 = $this->repo->create('/project', 'model-1');
        usleep(1000); // ensure different timestamps
        $id2 = $this->repo->create('/project', 'model-1');
        usleep(1000);
        $id3 = $this->repo->create('/other', 'model-1');

        $sessions = $this->repo->listByProject('/project');

        $this->assertCount(2, $sessions);
        $this->assertSame($id2, $sessions[0]['id']); // most recent first
        $this->assertSame($id1, $sessions[1]['id']);
    }

    public function test_latest_returns_most_recent(): void
    {
        $this->repo->create('/project', 'model-1');
        usleep(1000);
        $id2 = $this->repo->create('/project', 'model-1');

        $latest = $this->repo->latest('/project');
        $this->assertNotNull($latest);
        $this->assertSame($id2, $latest['id']);
    }

    public function test_latest_returns_null_for_empty_project(): void
    {
        $this->assertNull($this->repo->latest('/nonexistent'));
    }

    public function test_touch_updates_timestamp(): void
    {
        $id = $this->repo->create('/project', 'model-1');
        $before = $this->repo->find($id)['updated_at'];

        usleep(1000);
        $this->repo->touch($id);

        $after = $this->repo->find($id)['updated_at'];
        $this->assertNotSame($before, $after);
    }

    public function test_cleanup_deletes_old_unprotected_sessions_and_messages(): void
    {
        $oldDeleted = $this->repo->create('/project', 'model-1');
        $oldKept = $this->repo->create('/project', 'model-1');
        $recent = $this->repo->create('/project', 'model-1');

        $this->setUpdatedAt($oldDeleted, microtime(true) - (45 * 86400));
        $this->setUpdatedAt($oldKept, microtime(true) - (40 * 86400));
        $this->setUpdatedAt($recent, microtime(true));
        $this->addMessage($oldDeleted);

        $deleted = $this->repo->cleanup(30, 2);

        $this->assertSame(1, $deleted);
        $this->assertNull($this->repo->find($oldDeleted));
        $this->assertNotNull($this->repo->find($oldKept));
        $this->assertNotNull($this->repo->find($recent));
        $this->assertSame(0, $this->messageCount($oldDeleted));
    }

    public function test_cleanup_joins_existing_transaction(): void
    {
        $old = $this->repo->create('/project', 'model-1');
        $this->setUpdatedAt($old, microtime(true) - (45 * 86400));
        $this->addMessage($old);

        $pdo = $this->db->connection();
        $pdo->beginTransaction();

        $deleted = $this->repo->cleanup(30, 0);
        $this->assertSame(1, $deleted);
        $this->assertNull($this->repo->find($old));

        $pdo->rollBack();

        $this->assertNotNull($this->repo->find($old));
        $this->assertSame(1, $this->messageCount($old));
    }

    private function setUpdatedAt(string $id, float $timestamp): void
    {
        $stmt = $this->db->connection()->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'updated_at' => number_format($timestamp, 6, '.', ''),
        ]);
    }

    private function addMessage(string $sessionId): void
    {
        $stmt = $this->db->connection()->prepare(
            'INSERT INTO messages (session_id, role, content, created_at) VALUES (:session_id, :role, :content, :created_at)'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'hello',
            'created_at' => date('c'),
        ]);
    }

    private function messageCount(string $sessionId): int
    {
        $stmt = $this->db->connection()->prepare('SELECT COUNT(*) AS count FROM messages WHERE session_id = :session_id');
        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch();

        return (int) $row['count'];
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Session\Database;
use Kosmokrator\Session\SessionRepository;
use PHPUnit\Framework\TestCase;

class SessionRepositoryTest extends TestCase
{
    private SessionRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new SessionRepository(new Database(':memory:'));
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
}

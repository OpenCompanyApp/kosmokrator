<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Session\Database;
use Kosmokrator\Session\SettingsRepository;
use PHPUnit\Framework\TestCase;

class SettingsRepositoryTest extends TestCase
{
    private SettingsRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new SettingsRepository(new Database(':memory:'));
    }

    public function test_set_and_get(): void
    {
        $this->repo->set('global', 'temperature', '0.7');

        $this->assertSame('0.7', $this->repo->get('global', 'temperature'));
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $this->assertNull($this->repo->get('global', 'nonexistent'));
    }

    public function test_set_overwrites_existing(): void
    {
        $this->repo->set('global', 'temperature', '0.5');
        $this->repo->set('global', 'temperature', '0.9');

        $this->assertSame('0.9', $this->repo->get('global', 'temperature'));
    }

    public function test_all_returns_scope_settings(): void
    {
        $this->repo->set('project1', 'mode', 'plan');
        $this->repo->set('project1', 'temperature', '0.3');
        $this->repo->set('global', 'mode', 'edit');

        $all = $this->repo->all('project1');
        $this->assertSame(['mode' => 'plan', 'temperature' => '0.3'], $all);
    }

    public function test_delete(): void
    {
        $this->repo->set('global', 'temperature', '0.5');
        $this->repo->delete('global', 'temperature');

        $this->assertNull($this->repo->get('global', 'temperature'));
    }

    public function test_resolve_prefers_project_over_global(): void
    {
        $this->repo->set('global', 'temperature', '0.5');
        $this->repo->set('project1', 'temperature', '0.9');

        $this->assertSame('0.9', $this->repo->resolve('temperature', 'project1'));
    }

    public function test_resolve_falls_back_to_global(): void
    {
        $this->repo->set('global', 'temperature', '0.5');

        $this->assertSame('0.5', $this->repo->resolve('temperature', 'project1'));
    }

    public function test_resolve_returns_null_when_neither_exists(): void
    {
        $this->assertNull($this->repo->resolve('nonexistent', 'project1'));
    }

    public function test_project_scope_is_deterministic(): void
    {
        $scope1 = SettingsRepository::projectScope('/path/to/project');
        $scope2 = SettingsRepository::projectScope('/path/to/project');

        $this->assertSame($scope1, $scope2);
        $this->assertSame(64, strlen($scope1)); // sha256 hex
    }
}

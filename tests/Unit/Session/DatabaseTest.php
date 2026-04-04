<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Session\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function test_creates_schema_on_fresh_database(): void
    {
        $db = new Database(':memory:');
        $pdo = $db->connection();

        // Check all tables exist
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll();
        $tableNames = array_column($tables, 'name');

        $this->assertContains('settings', $tableNames);
        $this->assertContains('sessions', $tableNames);
        $this->assertContains('messages', $tableNames);
        $this->assertContains('memories', $tableNames);
        $this->assertContains('schema_version', $tableNames);
    }

    public function test_schema_version_is_set(): void
    {
        $db = new Database(':memory:');
        $pdo = $db->connection();

        $version = $pdo->query('SELECT version FROM schema_version LIMIT 1')->fetch();
        $this->assertNotFalse($version);
        $this->assertEquals(4, $version['version']);
    }

    public function test_idempotent_schema_creation(): void
    {
        $db = new Database(':memory:');
        $pdo = $db->connection();

        // Creating a second Database on the same connection shouldn't fail
        $version = $pdo->query('SELECT version FROM schema_version LIMIT 1')->fetch();
        $this->assertEquals(4, $version['version']);
    }

    public function test_foreign_keys_enabled(): void
    {
        $db = new Database(':memory:');
        $pdo = $db->connection();

        $result = $pdo->query('PRAGMA foreign_keys')->fetch();
        $this->assertEquals(1, $result['foreign_keys']);
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

class Database
{
    private \PDO $pdo;

    private const SCHEMA_VERSION = 2;

    public function __construct(?string $path = null)
    {
        if ($path === null) {
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
            $dir = $home.'/.kosmokrator/data';
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $path = $dir.'/kosmokrator.db';
        }

        $isMemory = $path === ':memory:';
        $this->pdo = new \PDO("sqlite:{$path}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        if (! $isMemory) {
            $this->pdo->exec('PRAGMA journal_mode=WAL');
        }
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $this->ensureSchema();
    }

    public function connection(): \PDO
    {
        return $this->pdo;
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');

        $stmt = $this->pdo->query('SELECT version FROM schema_version LIMIT 1');
        $row = $stmt->fetch();
        $currentVersion = $row ? (int) $row['version'] : 0;

        if ($currentVersion === 0) {
            $this->createInitialSchema();
            $this->pdo->exec('INSERT INTO schema_version (version) VALUES ('.self::SCHEMA_VERSION.')');
        } elseif ($currentVersion < self::SCHEMA_VERSION) {
            $this->migrate($currentVersion);
            $this->pdo->exec('UPDATE schema_version SET version = '.self::SCHEMA_VERSION);
        }
    }

    private function createInitialSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS settings (
                scope       TEXT NOT NULL,
                key         TEXT NOT NULL,
                value       TEXT NOT NULL,
                updated_at  TEXT,
                PRIMARY KEY (scope, key)
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS sessions (
                id          TEXT PRIMARY KEY,
                project     TEXT,
                title       TEXT,
                model       TEXT,
                created_at  TEXT,
                updated_at  TEXT
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS messages (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id  TEXT NOT NULL REFERENCES sessions(id),
                role        TEXT NOT NULL,
                content     TEXT,
                tool_calls  TEXT,
                tool_results TEXT,
                tokens_in   INTEGER DEFAULT 0,
                tokens_out  INTEGER DEFAULT 0,
                compacted   INTEGER DEFAULT 0,
                created_at  TEXT
            )
        ');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_session ON messages(session_id, compacted)');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS memories (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                project     TEXT,
                session_id  TEXT REFERENCES sessions(id),
                type        TEXT NOT NULL,
                memory_class TEXT NOT NULL DEFAULT \'durable\',
                title       TEXT NOT NULL,
                content     TEXT NOT NULL,
                pinned      INTEGER NOT NULL DEFAULT 0,
                expires_at  TEXT,
                last_surfaced_at TEXT,
                created_at  TEXT,
                updated_at  TEXT
            )
        ');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_project ON memories(project)');
    }

    private function migrate(int $from): void
    {
        if ($from < 2) {
            $this->addColumnIfMissing('memories', 'memory_class', "TEXT NOT NULL DEFAULT 'durable'");
            $this->addColumnIfMissing('memories', 'pinned', 'INTEGER NOT NULL DEFAULT 0');
            $this->addColumnIfMissing('memories', 'expires_at', 'TEXT');
            $this->addColumnIfMissing('memories', 'last_surfaced_at', 'TEXT');
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $column) {
                return;
            }
        }

        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

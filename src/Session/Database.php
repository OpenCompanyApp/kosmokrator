<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

/**
 * SQLite-backed persistence layer for sessions, messages, settings, and memories.
 * Manages schema creation and migration, providing a shared PDO connection for the Session subsystem.
 */
class Database
{
    private \PDO $pdo;

    private const SCHEMA_VERSION = 2;

    /**
     * @param string|null $path Absolute path to the SQLite database file, or ':memory:' for an ephemeral db.
     *                          Defaults to ~/.kosmokrator/data/kosmokrator.db.
     */
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
            $this->pdo->exec('PRAGMA journal_mode=WAL'); // Enable Write-Ahead Logging for concurrent reads
        }
        $this->pdo->exec('PRAGMA foreign_keys=ON'); // Enforce referential integrity

        $this->ensureSchema();
    }

    /** @return \PDO The raw PDO connection for direct queries. */
    public function connection(): \PDO
    {
        return $this->pdo;
    }

    /** Creates or migrates the schema to the current version. */
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

    /** Creates all tables and indexes for a brand-new database. */
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

        // Index for fetching a session's messages, optionally filtered by compaction status
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

    /** Runs incremental schema migrations starting from the given version. */
    private function migrate(int $from): void
    {
        if ($from < 2) {
            // v2: add memory classification and expiration columns
            $this->addColumnIfMissing('memories', 'memory_class', "TEXT NOT NULL DEFAULT 'durable'");
            $this->addColumnIfMissing('memories', 'pinned', 'INTEGER NOT NULL DEFAULT 0');
            $this->addColumnIfMissing('memories', 'expires_at', 'TEXT');
            $this->addColumnIfMissing('memories', 'last_surfaced_at', 'TEXT');
        }
    }

    /** Adds a column to a table only if it does not already exist. */
    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        // PRAGMA table_info returns one row per column; check for an existing match
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

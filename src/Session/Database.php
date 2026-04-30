<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Kosmokrator\Settings\ConfigCompatibility;
use Kosmokrator\Settings\SettingsPaths;

/**
 * SQLite-backed persistence layer for sessions, messages, settings, and memories.
 * Manages schema creation and migration, providing a shared PDO connection for the Session subsystem.
 */
class Database
{
    private ?\PDO $pdo = null;

    private bool $closed = false;

    private bool $isMemory = false;

    private const SCHEMA_VERSION = 11;

    /**
     * @param  string|null  $path  Absolute path to the SQLite database file, or ':memory:' for an ephemeral db.
     *                             Defaults to ~/.kosmo/data/kosmo.db.
     */
    public function __construct(?string $path = null)
    {
        $initLock = null;
        if ($path === null) {
            $dir = SettingsPaths::globalDirectory().'/data';
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Unable to create data directory: {$dir}");
            }
            $path = $dir.'/kosmo.db';
            $legacyPath = SettingsPaths::globalDirectory(ConfigCompatibility::LEGACY_ROOT).'/data/kosmokrator.db';
            if (! file_exists($path) && file_exists($legacyPath)) {
                @copy($legacyPath, $path);
                foreach (['-wal', '-shm'] as $suffix) {
                    if (file_exists($legacyPath.$suffix)) {
                        @copy($legacyPath.$suffix, $path.$suffix);
                    }
                }
            }
        }

        $this->isMemory = $path === ':memory:';
        if (! $this->isMemory) {
            $initLock = fopen($path.'.init.lock', 'c');
            if ($initLock === false || ! flock($initLock, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock database initialization: {$path}");
            }
        }

        try {
            $this->pdo = new \PDO("sqlite:{$path}");
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            if (! $this->isMemory) {
                $this->pdo->exec('PRAGMA busy_timeout=5000'); // Wait up to 5s for locked database
                $this->pdo->exec('PRAGMA journal_mode=WAL'); // Enable Write-Ahead Logging for concurrent reads
            }
            $this->pdo->exec('PRAGMA foreign_keys=ON'); // Enforce referential integrity

            $this->ensureSchema();
        } finally {
            if (is_resource($initLock)) {
                flock($initLock, LOCK_UN);
                fclose($initLock);
            }
        }
    }

    /** @return \PDO The raw PDO connection for direct queries. */
    public function connection(): \PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is closed.');
        }

        return $this->pdo;
    }

    /**
     * Checkpoint the WAL and optimize the database.
     * Call on session close to prevent unbounded WAL file growth.
     */
    public function checkpoint(): void
    {
        $this->connection()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    }

    /**
     * Checkpoint and close the database connection gracefully.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            if (! $this->isMemory && $this->pdo !== null) {
                $this->checkpoint();
            }
        } catch (\Throwable) {
            // Best-effort checkpoint — ignore errors during shutdown
        } finally {
            $this->pdo = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Creates or migrates the schema to the current version. */
    private function ensureSchema(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL, UNIQUE(version))');

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query('SELECT version FROM schema_version LIMIT 1');
            $row = $stmt->fetch();
            $stmt->closeCursor();
            $currentVersion = $row ? (int) $row['version'] : 0;

            if ($currentVersion === 0) {
                $this->createInitialSchema();
                $this->pdo->exec('INSERT OR REPLACE INTO schema_version (version) VALUES ('.self::SCHEMA_VERSION.')');
            } elseif ($currentVersion < self::SCHEMA_VERSION) {
                $this->migrate($currentVersion);
                $this->pdo->exec('UPDATE schema_version SET version = '.self::SCHEMA_VERSION);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
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
        $this->createMessagesFtsSchema();

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS memories (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                project     TEXT,
                session_id  TEXT REFERENCES sessions(id) ON DELETE CASCADE,
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

        $this->createMemoryIndexes();
        // Index for session listing by project
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_project_updated ON sessions(project, updated_at DESC)');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS gateway_sessions (
                platform     TEXT NOT NULL,
                route_key    TEXT NOT NULL,
                session_id   TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
                chat_id      TEXT NOT NULL,
                thread_id    TEXT,
                user_id      TEXT,
                metadata     TEXT,
                created_at   TEXT,
                updated_at   TEXT,
                PRIMARY KEY (platform, route_key)
            )
        ');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_gateway_sessions_session_id ON gateway_sessions(session_id)');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS gateway_messages (
                platform      TEXT NOT NULL,
                route_key     TEXT NOT NULL,
                message_kind  TEXT NOT NULL,
                chat_id       TEXT NOT NULL,
                message_id    INTEGER NOT NULL,
                thread_id     TEXT,
                updated_at    TEXT,
                PRIMARY KEY (platform, route_key, message_kind)
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS gateway_approvals (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                platform            TEXT NOT NULL,
                route_key           TEXT NOT NULL,
                session_id          TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
                tool_name           TEXT NOT NULL,
                arguments_json      TEXT NOT NULL,
                status              TEXT NOT NULL,
                chat_id             TEXT NOT NULL,
                thread_id           TEXT,
                request_message_id  INTEGER,
                requester_user_id   TEXT,
                requester_username  TEXT,
                created_at          TEXT,
                resolved_at         TEXT
            )
        ');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_gateway_approvals_route_status ON gateway_approvals(platform, route_key, status, created_at DESC)');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS gateway_checkpoints (
                platform    TEXT NOT NULL,
                checkpoint  TEXT NOT NULL,
                value       TEXT,
                updated_at  TEXT,
                PRIMARY KEY (platform, checkpoint)
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS gateway_pending_inputs (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                platform      TEXT NOT NULL,
                route_key     TEXT NOT NULL,
                payload_json  TEXT NOT NULL,
                created_at    TEXT
            )
        ');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_gateway_pending_inputs_route ON gateway_pending_inputs(platform, route_key, id)');

        $this->createSwarmMetadataSchema();
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

        if ($from < 3) {
            // v3: add composite indexes for common query patterns
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_project_expires ON memories(project, expires_at)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_project_updated ON sessions(project, updated_at DESC)');
        }

        if ($from < 4) {
            // v4: add indexes for expires_at, type, and memory_class
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_expires_at ON memories(expires_at)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_type ON memories(type)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_memory_class ON memories(memory_class)');
        }

        if ($from < 5) {
            // v5: add FTS5-backed session history search
            $this->createMessagesFtsSchema();
            $this->rebuildMessagesFtsIndex();
        }

        if ($from < 6) {
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS gateway_sessions (
                    platform     TEXT NOT NULL,
                    route_key    TEXT NOT NULL,
                    session_id   TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
                    chat_id      TEXT NOT NULL,
                    thread_id    TEXT,
                    user_id      TEXT,
                    metadata     TEXT,
                    created_at   TEXT,
                    updated_at   TEXT,
                    PRIMARY KEY (platform, route_key)
                )
            ');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_gateway_sessions_session_id ON gateway_sessions(session_id)');
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS gateway_messages (
                    platform      TEXT NOT NULL,
                    route_key     TEXT NOT NULL,
                    message_kind  TEXT NOT NULL,
                    chat_id       TEXT NOT NULL,
                    message_id    INTEGER NOT NULL,
                    thread_id     TEXT,
                    updated_at    TEXT,
                    PRIMARY KEY (platform, route_key, message_kind)
                )
            ');
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS gateway_approvals (
                    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                    platform            TEXT NOT NULL,
                    route_key           TEXT NOT NULL,
                    session_id          TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
                    tool_name           TEXT NOT NULL,
                    arguments_json      TEXT NOT NULL,
                    status              TEXT NOT NULL,
                    chat_id             TEXT NOT NULL,
                    thread_id           TEXT,
                    request_message_id  INTEGER,
                    requester_user_id   TEXT,
                    requester_username  TEXT,
                    created_at          TEXT,
                    resolved_at         TEXT
                )
            ');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_gateway_approvals_route_status ON gateway_approvals(platform, route_key, status, created_at DESC)');
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS gateway_checkpoints (
                    platform    TEXT NOT NULL,
                    checkpoint  TEXT NOT NULL,
                    value       TEXT,
                    updated_at  TEXT,
                    PRIMARY KEY (platform, checkpoint)
                )
            ');
        }

        if ($from < 7) {
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS gateway_pending_inputs (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    platform      TEXT NOT NULL,
                    route_key     TEXT NOT NULL,
                    payload_json  TEXT NOT NULL,
                    created_at    TEXT
                )
            ');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_gateway_pending_inputs_route ON gateway_pending_inputs(platform, route_key, id)');
        }

        if ($from < 8) {
            $this->createSwarmMetadataSchema();
        }

        if ($from < 9) {
            $this->addColumnIfMissing('swarm_agents', 'output_ref', 'TEXT');
            $this->addColumnIfMissing('swarm_agents', 'output_bytes', 'INTEGER NOT NULL DEFAULT 0');
            $this->addColumnIfMissing('swarm_agents', 'output_preview', 'TEXT');
        }

        if ($from < 10) {
            $this->addColumnIfMissing('gateway_approvals', 'requester_user_id', 'TEXT');
            $this->addColumnIfMissing('gateway_approvals', 'requester_username', 'TEXT');
        }

        if ($from < 11) {
            // v11: session-scoped memories must not block session deletion.
            $this->ensureMemoriesSessionCascade();
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

    private function createMessagesFtsSchema(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
                content,
                content = 'messages',
                content_rowid = 'id',
                tokenize = "unicode61 tokenchars '/._-'"
            )
            SQL
        );

        $this->pdo->exec(
            <<<'SQL'
            CREATE TRIGGER IF NOT EXISTS messages_fts_insert AFTER INSERT ON messages BEGIN
                INSERT INTO messages_fts(rowid, content) VALUES (new.id, COALESCE(new.content, ''));
            END
            SQL
        );

        $this->pdo->exec(
            <<<'SQL'
            CREATE TRIGGER IF NOT EXISTS messages_fts_delete AFTER DELETE ON messages BEGIN
                INSERT INTO messages_fts(messages_fts, rowid, content) VALUES ('delete', old.id, COALESCE(old.content, ''));
            END
            SQL
        );

        $this->pdo->exec(
            <<<'SQL'
            CREATE TRIGGER IF NOT EXISTS messages_fts_update AFTER UPDATE ON messages BEGIN
                INSERT INTO messages_fts(messages_fts, rowid, content) VALUES ('delete', old.id, COALESCE(old.content, ''));
                INSERT INTO messages_fts(rowid, content) VALUES (new.id, COALESCE(new.content, ''));
            END
            SQL
        );
    }

    private function createMemoryIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_project ON memories(project)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_project_expires ON memories(project, expires_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_expires_at ON memories(expires_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_type ON memories(type)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_memories_memory_class ON memories(memory_class)');
    }

    private function ensureMemoriesSessionCascade(): void
    {
        if ($this->memoriesSessionForeignKeyCascades()) {
            return;
        }

        $this->pdo->exec('ALTER TABLE memories RENAME TO memories_old');
        $this->pdo->exec('
            CREATE TABLE memories (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                project     TEXT,
                session_id  TEXT REFERENCES sessions(id) ON DELETE CASCADE,
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
        $this->pdo->exec('
            INSERT INTO memories (
                id, project, session_id, type, memory_class, title, content,
                pinned, expires_at, last_surfaced_at, created_at, updated_at
            )
            SELECT
                id, project, session_id, type,
                COALESCE(memory_class, \'durable\'),
                title, content,
                COALESCE(pinned, 0),
                expires_at, last_surfaced_at, created_at, updated_at
            FROM memories_old
            WHERE session_id IS NULL
               OR EXISTS (SELECT 1 FROM sessions WHERE sessions.id = memories_old.session_id)
        ');
        $this->pdo->exec('DROP TABLE memories_old');
        $this->createMemoryIndexes();
    }

    private function memoriesSessionForeignKeyCascades(): bool
    {
        $stmt = $this->pdo->query('PRAGMA foreign_key_list(memories)');
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        foreach ($rows as $row) {
            if (($row['from'] ?? null) === 'session_id'
                && ($row['table'] ?? null) === 'sessions'
                && strtoupper((string) ($row['on_delete'] ?? '')) === 'CASCADE') {
                return true;
            }
        }

        return false;
    }

    private function createSwarmMetadataSchema(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS swarm_agents (
                root_session_id       TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
                agent_id              TEXT NOT NULL,
                parent_id             TEXT,
                type                  TEXT,
                mode                  TEXT,
                status                TEXT NOT NULL,
                group_name            TEXT,
                depends_on_json       TEXT,
                task_preview          TEXT,
                tool_calls            INTEGER NOT NULL DEFAULT 0,
                tokens_in             INTEGER NOT NULL DEFAULT 0,
                tokens_out            INTEGER NOT NULL DEFAULT 0,
                retries               INTEGER NOT NULL DEFAULT 0,
                queue_reason          TEXT,
                last_tool             TEXT,
                last_message_preview  TEXT,
                next_retry_at         TEXT,
                output_ref            TEXT,
                output_bytes          INTEGER NOT NULL DEFAULT 0,
                output_preview        TEXT,
                error                 TEXT,
                created_at            TEXT,
                started_at            TEXT,
                last_activity_at      TEXT,
                ended_at              TEXT,
                updated_at            TEXT NOT NULL,
                PRIMARY KEY (root_session_id, agent_id)
            )
            SQL
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_swarm_agents_session_status ON swarm_agents(root_session_id, status, updated_at DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_swarm_agents_session_parent ON swarm_agents(root_session_id, parent_id)');
    }

    private function rebuildMessagesFtsIndex(): void
    {
        $this->pdo->exec("INSERT INTO messages_fts(messages_fts) VALUES ('rebuild')");
    }
}

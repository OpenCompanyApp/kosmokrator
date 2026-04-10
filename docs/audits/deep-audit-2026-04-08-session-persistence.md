# Session Persistence Deep Audit

**Date:** 2026-04-08  
**Scope:** `src/Session/`, `src/Task/`, `src/Settings/`, `src/Provider/DatabaseServiceProvider.php`, `src/Provider/SessionServiceProvider.php`  
**Auditor:** KosmoKrator Sub-Agent  

---

## Summary

The session persistence layer is built on SQLite with a single `Database` class managing the connection, schema creation, and migrations. Four repositories handle sessions, messages, settings, and memories. A `SessionManager` facade coordinates them. The `TaskStore` is purely in-memory. Settings also flow through a YAML-based `SettingsManager` layer.

**Overall assessment:** The persistence layer is well-structured with proper use of prepared statements, WAL mode, foreign keys, and transactions in critical paths. However, several medium-to-high issues exist around transactional consistency in multi-step operations, orphaned data during session deletion, timestamp inconsistencies, and data integrity gaps.

---

## Findings

### F-01: `deleteSession()` Does Not Remove Associated Memories — MEDIUM

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Session/SessionRepository.php:148-165` |
| **Also** | `src/Session/SessionManager.php:457-474` |

**Issue:** `SessionRepository::delete()` deletes messages and sessions in a transaction but **never deletes memories** referencing that session. The `memories` table has `session_id TEXT REFERENCES sessions(id)`, but:

1. The FK constraint on `memories.session_id` is defined without `ON DELETE CASCADE`
2. The explicit DELETE in `SessionRepository::delete()` only targets `messages` and `sessions` tables
3. There is no `DELETE FROM memories WHERE session_id = :id` anywhere

**Corruption/Loss Scenario:** Deleting a session leaves orphaned memory rows with `session_id` pointing to a non-existent session. While FK enforcement is enabled (`PRAGMA foreign_keys=ON`), this would actually cause the DELETE of the session to **fail** with a foreign key constraint violation if any memories reference it, making session deletion unreliable.

**Suggested Fix:**
```php
// In SessionRepository::delete(), add memories cleanup
$stmt = $pdo->prepare('DELETE FROM memories WHERE session_id = :id');
$stmt->execute(['id' => $id]);
```
Or add `ON DELETE CASCADE` to the FK definition in the schema.

---

### F-02: `saveMessage()` Performs 3 Non-Transactional DB Operations — MEDIUM

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Session/SessionManager.php:119-149` |

**Issue:** `SessionManager::saveMessage()` calls three separate repository methods sequentially:
1. `$this->messages->append(...)` — INSERT into messages
2. `$this->sessions->touch(...)` — UPDATE sessions.updated_at
3. `$this->sessions->find(...)` — SELECT to check title
4. `$this->sessions->updateTitle(...)` — UPDATE sessions.title (conditional)

None of these are wrapped in a transaction. A crash between steps 1 and 2 means the message is persisted but `updated_at` is stale. A crash between the INSERT and the title UPDATE means the session has no title.

**Corruption/Loss Scenario:** If the process crashes after `append()` but before `touch()`, the session appears older than it actually is, potentially causing it to be cleaned up prematurely by `cleanup()`. The message is saved but the session metadata is inconsistent.

**Suggested Fix:** Wrap the entire `saveMessage()` operation in a transaction, or at minimum wrap `append() + touch()` together.

---

### F-03: Timestamp Format Inconsistency Between Repositories — MEDIUM

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Session/SessionRepository.php:127-130` |
| **Also** | `src/Session/MessageRepository.php:62` |
| **Also** | `src/Session/MemoryRepository.php:39` |
| **Also** | `src/Session/MemoryRepository.php:301` |

**Issue:** Two different timestamp formats are used:

- `SessionRepository::now()` returns `number_format(microtime(true), 6, '.', '')` — a high-precision Unix float (e.g., `1744000000.123456`)
- `MessageRepository::append()` and `MemoryRepository::add()` use `date('c')` — ISO 8601 (e.g., `2026-04-08T12:00:00+00:00`)
- `MemoryRepository::all()` uses `gmdate('Y-m-d\TH:i:s\Z')` — UTC ISO 8601

This means `sessions.updated_at` and `sessions.created_at` are Unix floats, while `messages.created_at` is ISO 8601. The `cleanup()` method in `SessionRepository` compares `updated_at` against a Unix float cutoff (`microtime(true) - days*86400`), which works because sessions use floats. But if anyone tries to query messages by date using the same approach, it would fail.

**Corruption/Loss Scenario:** No immediate data loss, but comparing or joining across tables using timestamps is impossible. Future code that tries to correlate session and message timestamps will get incorrect results.

**Suggested Fix:** Standardize on a single format. ISO 8601 (`date('c')`) is recommended for all timestamps. Update `SessionRepository::now()` accordingly and migrate existing data.

---

### F-04: `compactWithSummary()` Calls `deleteCompacted()` Outside Transaction — HIGH

| Attribute | Value |
|-----------|-------|
| **Severity** | HIGH |
| **File** | `src/Session/MessageRepository.php:154-185` |

**Issue:** In `compactWithSummary()`:
```php
// Lines 154-185
public function compactWithSummary(...): void
{
    $pdo = $this->db->connection();
    $startedTransaction = ! $pdo->inTransaction();

    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $this->markCompactedIds($messageIds);
        $this->append(...);
        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // OUTSIDE TRANSACTION — danger!
    $this->deleteCompacted($sessionId);
}
```

`deleteCompacted()` is called **after** the transaction commits. If the process crashes between `commit()` and `deleteCompacted()`, the compacted messages are marked but never deleted, leading to unbounded database growth over time.

**Corruption/Loss Scenario:** Repeated crashes during compaction cause compacted messages to accumulate indefinitely. This doesn't lose data (the messages are already replaced by the summary), but it causes significant database bloat that is never cleaned up.

**Suggested Fix:** Either move `deleteCompacted()` inside the transaction, or make it a separate periodic maintenance operation that's called reliably.

---

### F-05: `addColumnIfMissing()` Vulnerable to SQL Injection via Table/Column Names — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Session/Database.php:197-209` |

**Issue:** The `addColumnIfMissing()` method interpolates `$table` and `$column` directly into SQL strings:
```php
$stmt = $this->pdo->query("PRAGMA table_info({$table})");
// ...
$this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
```

Currently all calls are hardcoded in `migrate()`, so this is not exploitable. But it's a latent risk if the method is ever called with user-supplied values.

**Suggested Fix:** Validate table and column names against a whitelist regex (`/^[a-z_][a-z0-9_]*$/i`) before interpolation, or document that the method must only be called with hardcoded values.

---

### F-06: No `ON DELETE CASCADE` on Foreign Key Constraints — MEDIUM

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Session/Database.php:123-136` |

**Issue:** The `messages` table has `session_id TEXT NOT NULL REFERENCES sessions(id)` and `memories` has `session_id TEXT REFERENCES sessions(id)`, but neither uses `ON DELETE CASCADE`. Instead, `SessionRepository::delete()` manually deletes messages before sessions.

However, as noted in F-01, it does **not** delete memories. With `PRAGMA foreign_keys=ON` enabled (line 41), attempting to delete a session that has associated memories will throw a PDOException (constraint violation), causing the transaction to roll back and the session to not be deleted at all.

**Corruption/Loss Scenario:** A session with memories cannot be deleted — the operation silently fails (the exception propagates but the session remains). This is the opposite of data loss but creates a data integrity issue where users cannot clean up their sessions.

**Suggested Fix:** Either add `ON DELETE CASCADE` to both FK definitions, or ensure all child records are deleted in the `delete()` method.

---

### F-07: `SessionRepository::cleanup()` Uses Query-Time Selection Outside Transaction — MEDIUM

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Session/SessionRepository.php:177-222` |

**Issue:** The `cleanup()` method:
1. SELECTs session IDs to delete (lines 182-196) — **no transaction**
2. Starts a transaction (line 205)
3. DELETEs messages and sessions (lines 208-213)

Between step 1 and step 2, another process could modify the sessions table (e.g., a `touch()` could update `updated_at`, preventing a session from qualifying for cleanup). The IDs selected in step 1 may be stale by the time the DELETE executes.

**Corruption/Loss Scenario:** Under concurrent access (e.g., two KosmoKrator instances running against the same DB), cleanup could delete a session that was just touched by another process. The 5-second `busy_timeout` mitigates lock contention but doesn't prevent this TOCTOU (time-of-check-time-of-use) race.

**Suggested Fix:** Wrap the SELECT + DELETE in a single transaction, or add a `WHERE updated_at < :cutoff` condition to the DELETE statements themselves.

---

### F-08: `TaskStore` Is Purely In-Memory With No Persistence — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Task/TaskStore.php:14-382` |

**Issue:** `TaskStore` maintains tasks entirely in a PHP array (`private array $tasks = []`). There is no database backing. When the process ends (or crashes), all task state is lost.

**Corruption/Loss Scenario:** If the agent crashes mid-task, all task tracking is lost. On resume, the task tree is empty and must be rebuilt from scratch (or not at all). This is by design for the current architecture but limits task continuity across sessions.

**Suggested Fix:** Document this as intentional. If task persistence is needed, add an optional SQLite-backed implementation.

---

### F-09: `MemoryRepository::all()` Uses Different Timestamp Format Than Other Methods — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Session/MemoryRepository.php:301` |

**Issue:** `MemoryRepository::all()` uses `gmdate('Y-m-d\TH:i:s\Z')` for UTC ISO timestamps, while `forProject()`, `search()`, `findDuplicate()`, and `pruneExpired()` all use `date('c')` (which includes timezone offset). If the system timezone is not UTC, `all()` and `forProject()` may produce different expiration comparisons for the same data.

**Corruption/Loss Scenario:** A memory that appears expired via `all()` might not appear expired via `forProject()` (or vice versa), leading to inconsistent memory visibility.

**Suggested Fix:** Standardize on `gmdate('Y-m-d\TH:i:s\Z')` everywhere or `date('c')` everywhere. The safest option is always UTC.

---

### F-10: `SettingsManager::setRaw()` Bypasses Schema Validation — MEDIUM

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Settings/SettingsManager.php:207-214` |

**Issue:** The `setRaw()` method writes arbitrary values to the YAML config without any schema validation or type normalization:
```php
public function setRaw(string $path, mixed $value, string $scope = 'project'): void
{
    // No validation of $path or $value
    $this->store->set($data, $path, $value);
    $this->store->save($targetPath, $data);
    $this->reloadRepository();
}
```

In contrast, `set()` validates against the schema and normalizes types. `setRaw()` is used for `saveCustomProvider()`, `setProviderLastModel()`, and other internal operations. A typo in the path could create orphaned config keys.

**Corruption/Loss Scenario:** An invalid value or path written via `setRaw()` persists in the YAML file and could cause runtime errors when the config is loaded on the next boot. The `reloadRepository()` call applies the change immediately, but if the config structure is malformed, the YAML parser might fail on next load.

**Suggested Fix:** At minimum, validate that the path follows the expected format. Consider logging raw writes for auditability.

---

### F-11: YAML Config Atomic Write May Leave Temp Files — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Settings/YamlConfigStore.php:74-77` |

**Issue:** The atomic write pattern:
```php
$tmpPath = $dir.'/'.basename($path).'.tmp.'.uniqid('', true);
file_put_contents($tmpPath, Yaml::dump(...));
rename($path, $path); // Actually: rename($tmpPath, $path)
```

If `file_put_contents()` fails (disk full, permissions), the temp file is left behind. There's no cleanup. On NFS or certain filesystems, `rename()` is not truly atomic.

**Corruption/Loss Scenario:** Accumulated `.tmp.*` files in `.kosmokrator/` directories. No data loss, but clutter.

**Suggested Fix:** Add a `try/catch` that cleans up the temp file on failure.

---

### F-12: `Schema_version` Table Uses `UNIQUE` Without Explicit Primary Key — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Session/Database.php:76` |

**Issue:**
```sql
CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL, UNIQUE(version))
```

The `UNIQUE` constraint acts as an implicit unique index but doesn't make `version` a primary key. The `INSERT OR REPLACE` on line 86 works because of the UNIQUE constraint, but the table has no explicit rowid alias, making queries slightly less idiomatic.

**Corruption/Loss Scenario:** No practical issue. The UNIQUE constraint prevents duplicate versions. This is purely a style concern.

**Suggested Fix:** Use `PRIMARY KEY (version)` instead of `UNIQUE(version)`.

---

### F-13: No Index on `messages.created_at` — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Session/Database.php:123-139` |

**Issue:** The only index on messages is `idx_messages_session ON messages(session_id, compacted)`. The `searchProjectHistory()` method orders by `s.updated_at DESC, m.id DESC` and filters by `m.content LIKE :query`, which requires a full scan of non-compacted messages for the project's sessions. For large histories, this will be slow.

**Corruption/Loss Scenario:** No data loss. Performance degradation over time as message history grows.

**Suggested Fix:** Consider a full-text search (FTS5) virtual table for message content, or at minimum an index on `messages(session_id, role, id)` for the subquery in `listByProject()`.

---

### F-14: `persistCompaction()` Reads All Messages Including Compacted — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Session/SessionManager.php:506` |

**Issue:**
```php
$raw = $this->messages->loadRaw($this->currentSessionId);
```

This calls `loadRaw()` with `$includeCompacted = false` (default), but compacted messages should still be excluded since they're already compacted and marked. Actually, looking more carefully, `loadRaw()` defaults to excluding compacted. However, `compactWithSummary()` calls `deleteCompacted()` which removes old compacted messages. So on subsequent compactions, `loadRaw()` will only see active messages — this is correct.

However, `persistCompactionPlan()` (line 552) also calls `loadRaw()` without including compacted, and then slices `$raw` by `$plan->compactedMessageCount`. If the plan's count exceeds the number of active messages, `array_slice` will return fewer rows than expected, and the compaction will be a no-op (no data loss but the compaction plan won't execute).

**Corruption/Loss Scenario:** Minor — a compaction plan that references more messages than exist will silently do nothing. The conversation continues to grow.

**Suggested Fix:** Add a guard or warning log when the plan's message count exceeds available messages.

---

### F-15: `findByPrefix()` LIKE Pattern Could Match Unexpected IDs — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Session/SessionRepository.php:57-66` |

**Issue:**
```php
$stmt = $this->db->connection()->prepare(
    'SELECT * FROM sessions WHERE id LIKE :prefix LIMIT 2'
);
$stmt->execute(['prefix' => $prefix.'%']);
```

Session IDs are UUIDs containing only hex characters and hyphens (`[0-9a-f-]`), so LIKE wildcards (`%`, `_`) in user input won't match. However, the `%` is appended without escaping. If a session ID ever contained `%` or `_`, this could produce incorrect matches.

**Corruption/Loss Scenario:** Extremely unlikely with UUID v4 format. No practical issue.

---

### F-16: `MemoryRepository::update()` Cannot Clear `expires_at` to Null — MEDIUM

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Session/MemoryRepository.php:118-151` |

**Issue:** The `update()` method uses null-coalescing to skip fields:
```php
if ($expiresAt !== null) {
    $fields[] = 'expires_at = :expires_at';
    $params['expires_at'] = $expiresAt;
}
```

Since `$expiresAt` defaults to `null` and is only set when non-null, there is **no way to clear an existing expiry**. Passing `null` means "keep the existing value." There is no sentinel value like an empty string that would set `expires_at` to NULL.

**Corruption/Loss Scenario:** A working memory with an expiry cannot be promoted to a durable (non-expiring) memory. The memory will eventually be pruned even if the user wanted to keep it permanently.

**Suggested Fix:** Add a `clearExpiry: bool = false` parameter, or use a sentinel value (e.g., empty string `''`) to represent "clear the expiry."

---

### F-17: `Database::checkpoint()` Called in `close()` But `close()` Is Never Explicitly Called — MEDIUM

| Attribute | Value |
|-----------|-------|
| **Severity** | MEDIUM |
| **File** | `src/Session/Database.php:56-71` |
| **Also** | `src/Provider/DatabaseServiceProvider.php:29` |

**Issue:** `Database` is registered as a singleton:
```php
$this->container->singleton(SessionDatabase::class, fn () => new SessionDatabase);
```

There is no shutdown hook, destructor, or dispose pattern that calls `Database::close()`. The WAL checkpoint only happens if something explicitly calls `close()`. PHP will close the PDO connection when the process ends, but this is not a graceful shutdown — the WAL file may not be checkpointed.

**Corruption/Loss Scenario:** Over time, the WAL file (`kosmokrator.db-wal`) grows unbounded. While SQLite handles this gracefully (the WAL is applied on next open), it wastes disk space and slows startup.

**Suggested Fix:** Register a shutdown function or use PHP's `register_shutdown_function()` to call `Database::close()` on process exit.

---

### F-18: `SettingsManager::reloadRepository()` Reads Config From Disk on Every Write — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Settings/SettingsManager.php:267-295` |

**Issue:** Every `set()`, `delete()`, `setRaw()`, or `unsetRaw()` call triggers `reloadRepository()`, which:
1. Creates a new `ConfigLoader` and re-reads all PHP config files
2. Re-reads the global YAML config
3. Re-reads the project YAML config

This is 3+ file reads per settings write. While acceptable for interactive use (writes are infrequent), it's inefficient for batch operations.

**Corruption/Loss Scenario:** No data loss. Performance concern only.

**Suggested Fix:** Consider debouncing or batching reloads, or building an in-memory overlay that doesn't require full reloads.

---

### F-19: `DatabaseServiceProvider::migrateYamlKeys()` Rewrites YAML Without Full Atomicity — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Provider/DatabaseServiceProvider.php:91-158` |

**Issue:** The one-time migration reads YAML, removes API keys, and rewrites it. If the process crashes between reading and writing:
1. The SQLite settings already have the keys (lines 120-121)
2. The YAML still has the keys (write didn't complete)
3. The migration flag hasn't been set yet

On next boot, the migration runs again, sees keys in SQLite already (`$settings->get(...) === null` check on line 119 fails), so it won't duplicate — but it will still try to rewrite the YAML. This is benign but the write uses `file_put_contents + rename` which is already atomic.

**Corruption/Loss Scenario:** No data loss. The migration is idempotent. Minor: duplicate YAML rewrite on crash recovery.

---

### F-20: No Maximum Size/Row Count Enforcement on Database — LOW

| Attribute | Value |
|-----------|-------|
| **Severity** | LOW |
| **File** | `src/Session/Database.php` (entire file) |

**Issue:** There is no maximum database size, row count, or automatic compaction trigger. `cleanup()` exists but must be called manually. `deleteCompacted()` is only called during compaction. If the user never triggers cleanup or compaction, the database grows indefinitely.

**Corruption/Loss Scenario:** Very large databases slow down queries, increase memory usage, and may eventually fill the disk. No data corruption, but operational degradation.

**Suggested Fix:** Add automatic cleanup triggers (e.g., on session creation, check if cleanup is overdue) or a `PRAGMA max_page_count` limit.

---

## Architectural Notes

### Positive Patterns Observed
1. **Prepared statements everywhere** — no SQL injection vectors in user-facing code
2. **WAL mode enabled** — enables concurrent reads during writes
3. **`busy_timeout=5000`** — reasonable lock wait timeout
4. **`PRAGMA foreign_keys=ON`** — enforces referential integrity
5. **Atomic YAML writes** — temp file + rename pattern
6. **Schema migration system** — versioned with backward-compatible ALTER TABLE
7. **LIKE wildcard escaping** — both `MessageRepository` and `MemoryRepository` properly escape `%`, `_`, and `\`

### Risk Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 1 |
| MEDIUM | 7 |
| LOW | 12 |

### Top Priority Fixes

1. **F-04** — Move `deleteCompacted()` inside the compaction transaction
2. **F-01/F-06** — Add memories cleanup to session deletion (or add `ON DELETE CASCADE`)
3. **F-02** — Wrap `saveMessage()` multi-step operations in a transaction
4. **F-03** — Standardize timestamp formats across all repositories
5. **F-17** — Register a shutdown hook for WAL checkpoint
6. **F-16** — Allow clearing memory expiry via `update()`

---

*End of audit.*

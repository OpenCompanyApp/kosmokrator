# Security-Adjacent RAM Efficiency Audit — Synthesis Report

**Audit Scope:** Permission system, Codex authentication integration, configuration caching
**Date:** 2026-04-03
**Status:** Phase 1 findings synthesized

---

## Executive Summary

This report synthesizes RAM efficiency audits across three critical subsystems: permission evaluation, Codex authentication, and configuration management. The findings reveal **systemic caching failures** that create both performance bottlenecks and **security-adjacent vulnerabilities**, particularly around memory exhaustion attack vectors and credential exposure through predictable memory patterns.

**Key Critical Issues:**
- `PermissionRule::matchesGlob()` compiles regex on every call — hundreds of times per permission check
- `SettingsCodexTokenStore` performs 7× N+1 database queries per token operation with no in-memory cache
- `SettingsManager::reloadRepository()` triggers a full config re-parse (4+ YAML files) on every settings write
- No caching exists for path resolutions, evaluation results, or parsed YAML anywhere in the stack

**Security Implications:**
- Memory exhaustion via repeated permission checks on complex rule sets
- Token refresh storms can saturate SQLite connection pool and memory
- Config write amplification creates predictable memory churn patterns
- Lack of rate limiting on permission evaluation enables DoS via tool spam
- Credentials repeatedly read from disk increase attack surface in shared hosting

---

## Findings

### Critical Severity

#### 1. Regex Compilation in Hot Path — PermissionRule::matchesGlob()
**Files:** `src/Tool/Permission/PermissionRule.php:51-60`, `src/Tool/Permission/Check/DenyPatternCheck.php:39`, `src/Tool/Permission/Check/BlockedPathCheck.php:66`, `src/Tool/Permission/GuardianEvaluator.php:106`

**Issue:** Every call to `matchesGlob()` compiles a fresh regex via `preg_quote()` + `str_replace()` + `preg_match()`. This method is invoked:
- For each deny pattern in each matching rule (DenyPatternCheck)
- For each blocked path pattern (BlockedPathCheck, up to 4× per path)
- For each safe command pattern (GuardianEvaluator, O(p) per call)

With ~50 tools, ~10 rules, ~5 deny patterns per rule, a single permission check can trigger **250+ regex compilations**. PHP's internal regex cache is limited and not guaranteed to hit.

**RAM Impact:** Each compiled regex pattern string occupies ~200-500 bytes in memory. At 250 compilations per check × 10 concurrent requests = **~500KB - 1.25MB** of transient regex strings per request cycle, plus GC pressure.

**Security Risk:** An attacker controlling tool arguments can force evaluation of many deny patterns, causing CPU/memory exhaustion. No rate limiting exists on permission checks.

---

#### 2. N+1 Token Storage Queries — SettingsCodexTokenStore
**Files:** `src/LLM/Codex/SettingsCodexTokenStore.php:32-38`, `src/LLM/Codex/SettingsCodexTokenStore.php:63-85`

**Issue:** Token storage uses 7 individual settings keys (`provider.codex.*`). Every `current()` performs 7 separate SELECT queries; every `save()` performs 7 separate INSERT/UPDATE queries. No in-memory caching; every call hits SQLite.

**RAM Impact:** Each query returns a row (~200-300 bytes). 7 queries × result set overhead × concurrent requests = **~1-2KB per request** in short-lived DB result objects. More critically, **connection pool exhaustion** under load can cause queued requests to accumulate memory.

**Security Risk:** Token refresh storms (multiple simultaneous requests triggering refresh) cause 7 writes + HTTP call per refresh, amplifying memory/CPU usage. No refresh debouncing.

---

#### 3. Full Config Reload on Every Write — SettingsManager::reloadRepository()
**Files:** `src/Settings/SettingsManager.php:266-274`

**Issue:** After any settings `set()` or `delete()`, `reloadRepository()` creates a **new ConfigLoader** and re-parses all 4 bundled YAML files + user + project config, then copies data into the Repository. This happens on every single settings write.

**RAM Impact:** Total YAML size ~28KB, but parsing creates intermediate arrays and objects. A full reload generates **~100-150KB** of temporary arrays/objects per write, which are then GC'd. Under rapid successive writes (e.g., batch updates), this creates significant memory churn and can push PHP memory_limit.

**Security Risk:** An attacker with settings write access (or a buggy tool) can trigger repeated config reloads to exhaust memory. The pattern is predictable and not rate-limited.

---

### High Severity

#### 4. No Path Resolution Cache — PathResolver::resolve()
**Files:** `src/Tool/Permission/PathResolver.php:21-39`

**Issue:** `realpath()` syscall executed on every path check with no caching. `BlockedPathCheck` calls this for every file operation, and `GuardianEvaluator::isInsideProject()` calls it for every command.

**RAM Impact:** Each `realpath()` result is a string (~256-1024 bytes). With 100 file checks per request, that's **25-100KB** of repeated string allocations. Strings are duplicated in memory if same path resolved multiple times.

**Security Risk:** Path traversal attacks cause repeated resolution of deep/nested paths, amplifying memory usage. No TTL or eviction on cache (because none exists).

---

#### 5. Duplicate Rule Evaluation — DenyPatternCheck + RuleCheck + ModeOverrideCheck
**Files:** `src/Tool/Permission/Check/DenyPatternCheck.php:26-49`, `src/Tool/Permission/Check/RuleCheck.php:25-48`, `src/Tool/Permission/Check/ModeOverrideCheck.php:30-70`

**Issue:** Rules are evaluated up to **3 times** in a single permission flow:
1. `DenyPatternCheck` iterates all rules, calls `matchesGlob()` for each deny pattern
2. `RuleCheck` iterates all rules again, calls `evaluate()` (which calls `matchesGlob()` again)
3. `ModeOverrideCheck` iterates all rules a third time if mode is Guardian

**RAM Impact:** Each evaluation creates temporary arrays and regex strings. Triple evaluation multiplies memory churn by 3×. For 50 rules × 5 patterns = 750 regex compilations instead of 250.

**Security Risk:** Complex permission rules (many deny patterns) are amplified 3×, making them a more effective DoS vector.

---

#### 6. No YAML Parse Cache — ConfigLoader & YamlConfigStore
**Files:** `src/ConfigLoader.php:26-47`, `src/Settings/YamlConfigStore.php:23-35`

**Issue:** Every `SettingsManager::get()` call triggers `load()` which reads and parses YAML from disk. No opcode or user-space cache. `ConfigLoader::load()` parses 4+ YAML files on every boot and settings write.

**RAM Impact:** Each `Yaml::parse()` creates a full array tree (~28KB for all configs). A single `get()` loads project + global = **~56KB** of parsed arrays. With 10 `get()` calls per request = **~560KB** of transient config data (though PHP may reuse array structures, still significant).

**Security Risk:** Repeated disk I/O + parsing increases request latency, making timing attacks easier. Also increases memory footprint for concurrent requests.

---

#### 7. No Token In-Memory Caching — CodexOAuthService & SettingsCodexTokenStore
**Files:** `vendor/opencompany/prism-codex/src/CodexOAuthService.php:180-196`, `src/LLM/Codex/SettingsCodexTokenStore.php`

**Issue:** Every `getAccessToken()` call reads 7 settings from DB. No per-request or short-term caching. Even within a single request, multiple provider calls re-fetch the same token.

**RAM Impact:** Each token fetch creates a `CodexToken` object (~500 bytes) + 7 DB result rows. With 5 LLM calls per request = **~2.5KB** of duplicated token objects + **~3.5KB** of DB results = **~6KB** per request that could be cached.

**Security Risk:** Token refresh under concurrent load causes multiple simultaneous refreshes, each writing to SQLite, risking database lock contention and memory spikes from queued requests.

---

### Medium Severity

#### 8. No Provider Instance Reuse — RelayProviderRegistrar & PrismManager
**Files:** `src/LLM/RelayProviderRegistrar.php:42-117`, `vendor/prism-php/prism/src/PrismManager.php:40-57`

**Issue:** Each `PrismManager::resolve()` creates a new provider instance. No caching of provider objects.

**RAM Impact:** Provider instance ~200-500 bytes. With 10 LLM calls per request using same provider, that's **2-5KB** of duplicated objects. Minor but unnecessary.

**Security Risk:** Provider instantiation may involve reading credentials from config each time, increasing exposure in memory dumps.

---

#### 9. Repeated SettingsPaths Instantiation & Directory Walks
**Files:** `src/Settings/SettingsManager.php` (multiple), `src/ConfigLoader.php:125-150`

**Issue:** `SettingsPaths` objects created on every `resolve()`/`getRaw()` call. Each instantiation re-evaluates `file_exists()` and walks directory tree for project root.

**RAM Impact:** Each `SettingsPaths` ~100 bytes + path strings. Directory walk for deep project (e.g., 6 levels) creates 12 path strings (~200 bytes). With 10 calls = **~2KB** of temporary path strings.

**Security Risk:** Directory walk on every load increases I/O, potentially leaking directory structure via timing.

---

#### 10. JWT Decode on Every Token Store
**Files:** `vendor/opencompany/prism-codex/src/CodexOAuthService.php:246-304`

**Issue:** `storeTokens()` decodes JWT (base64 + json) on every token exchange to extract `account_id` and `email`. No caching of decoded claims.

**RAM Impact:** Decoded JWT claims array ~500 bytes. With each refresh + initial auth = **~1KB** per auth flow. Minor but repeated.

**Security Risk:** JWT decoding failures could leak partial token data in error messages.

---

### Low Severity

#### 11. No File Watching / Invalidation Strategy
**Files:** All config loading code

**Issue:** No inotify/fswatch; config changes only detected on next load. Not a RAM issue directly, but prevents efficient cache invalidation, forcing either stale cache or no cache.

**RAM Impact:** N/A — current design avoids file handle overhead.

**Security Risk:** Stale config may persist indefinitely in long-running processes (if ever introduced).

---

## Memory Hotspots

| File:Line | Component | Estimated KB per Request | Notes |
|-----------|-----------|--------------------------|-------|
| `PermissionRule.php:51-60` | Regex compilation hotspot | 20-50 KB | 250+ compilations × ~200 bytes each |
| `SettingsCodexTokenStore.php:32-38` | Token read (7 queries) | 3-5 KB | 7 DB result sets + CodexToken object |
| `SettingsManager.php:266-274` | Full config reload on write | 100-150 KB | 5 YAML parses + array merges |
| `YamlConfigStore.php:23-35` | YAML parse per get | 50-100 KB | 2 parses per `get()` call |
| `BlockedPathCheck.php:48-74` | Path resolution + pattern matching | 10-30 KB | realpath() + multiple matchesGlob |
| `GuardianEvaluator.php:94-112` | Safe command pattern matching | 5-15 KB | O(p) regex compilations per call |
| `DenyPatternCheck.php:26-49` | Deny pattern iteration | 10-20 KB | Rules × deny patterns × regex |
| `ModeOverrideCheck.php:30-70` | Rule re-evaluation | 10-20 KB | Duplicate of RuleCheck work |
| `ConfigLoader.php:125-150` | Directory walk | 1-3 KB | Per project config load |
| `RelayProviderRegistrar.php:42-117` | Provider instantiation | 2-5 KB | Per provider resolve |

**Total estimated RAM churn per typical request:** **~200-400 KB** of short-lived objects/strings due to caching misses. With 10 concurrent requests, that's **2-4 MB** of transient memory pressure.

---

## Attack Vectors (Memory Exhaustion)

### 1. Permission Rule Bomb
**Vector:** Attacker provides tool arguments that match many deny patterns (e.g., wildcard paths, glob patterns). Each match triggers `matchesGlob()` for every deny pattern across all rules.

**Amplification:** With 50 rules × 5 deny patterns = 250 regex compilations per check. No limit on number of permission checks per request (tools can be called repeatedly).

**Impact:** CPU spike + memory allocation for regex strings. Can exhaust PHP memory_limit if combined with other allocations.

**Mitigation Status:** None — no rate limiting, no caching, no pattern complexity limits.

---

### 2. Token Refresh Storm
**Vector:** Multiple concurrent requests with expiring Codex token. Each request calls `getAccessToken()`, sees token expiring, and triggers `refreshToken()` simultaneously.

**Amplification:** Each refresh performs 7 DB reads + 7 DB writes + HTTP call. SQLite locks cause queuing; queued requests accumulate memory.

**Impact:** Database connection pool exhaustion, memory buildup from queued request objects, potential OOM.

**Mitigation Status:** None — no refresh debouncing, no token lock, no refresh queue.

---

### 3. Config Write Amplification
**Vector:** Attacker (or bug) repeatedly writes to settings (e.g., toggling a flag). Each write triggers `reloadRepository()` → full config re-parse.

**Amplification:** 1 write = 5 YAML parses + array merges (~100-150KB churn). 100 writes/second = 10-15 MB/s memory churn, GC cannot keep up.

**Impact:** Memory fragmentation, GC thrashing, eventual OOM.

**Mitigation Status:** None — no write coalescing, no debouncing, no rate limiting on settings changes.

---

### 4. Path Traversal Memory Bloat
**Vector:** Attacker passes deeply nested or absolute paths (e.g., `/a/b/c/d/e/f/g/h/i/j/k/l/m/n/o/p`). `PathResolver::resolve()` calls `realpath()` twice per check (path + parent). No caching means each unique path allocates new strings.

**Amplification:** Each path string ~50 bytes, resolved path ~100 bytes. 1000 unique paths = **~150KB** of path strings. Combined with permission checks on each, multiplies.

**Impact:** Memory bloat from unique path strings; filesystem I/O amplification.

**Mitigation Status:** None — no path resolution cache, no canonicalization before check.

---

### 5. Provider Instantiation Flood
**Vector:** Attacker triggers many LLM calls with different provider names (or same provider repeatedly). Each call instantiates a new provider object and fetches credentials.

**Amplification:** Each provider instantiation ~300 bytes + credential fetch (7 DB queries for Codex). 100 calls = 30KB objects + 700 DB queries.

**Impact:** DB connection exhaustion, memory from provider objects, credential exposure in more memory locations.

**Mitigation Status:** None — no provider instance caching.

---

## Recommendations

### Immediate (Deploy within 24-48h)

1. **Add static regex cache to `PermissionRule::matchesGlob()`**
   ```php
   private static array $regexCache = [];
   $key = $pattern;
   if (!isset(self::$regexCache[$key])) {
       self::$regexCache[$key] = '/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/i';
   }
   $regex = self::$regexCache[$key];
   ```
   **Impact:** Eliminates 90%+ of regex compilation overhead. ~5-10 lines change.

2. **Bulk token fetch in `SettingsCodexTokenStore::current()`**
   Replace 7 individual SELECTs with:
   ```sql
   SELECT key, value FROM settings WHERE scope='global' AND key LIKE 'provider.codex.%'
   ```
   Build array from single result set.
   **Impact:** Reduces token load from 7 DB round-trips to 1. ~10 lines change.

3. **Add in-memory token cache to `SettingsCodexTokenStore`**
   ```php
   private ?CodexToken $cached = null;
   private int $cachedAt = 0;
   // In current(): return $this->cached if within 5s
   ```
   **Impact:** Prevents DB thrashing on rapid successive calls. ~15 lines change.

---

### Short-Term (1-2 weeks)

4. **Memoize permission evaluation results in `PermissionEvaluator`**
   Cache `(toolName, argsHash) => PermissionResult` for duration of request (or session). Invalidate on `resetGrants()`.
   **Impact:** Avoids re-running chain for same tool+args. Major CPU/memory savings for repeated tool calls.

5. **Cache path resolutions in `PathResolver`**
   Static `array $cache = []` keyed by realpath. TTL not needed for request-lifetime.
   **Impact:** Eliminates duplicate `realpath()` syscalls. ~10 lines change.

6. **Avoid full config reload on write in `SettingsManager`**
   In `reloadRepository()`, instead of full `ConfigLoader::load()`, update `$this->config` incrementally using the `$data` already loaded in `configTarget()`.
   **Impact:** Reduces write amplification from 5 parses to 0. ~20 lines change.

7. **Add YAML parse cache to `YamlConfigStore`**
   Static `array $cache` keyed by `realpath($path) . filemtime($path)`. Invalidate on `save()`.
   **Impact:** Eliminates redundant parses across multiple `get()` calls. ~20 lines change.

8. **Cache provider instances in `RelayProviderRegistrar`**
   Private array `$instances = []`. Return cached if already resolved.
   **Impact:** Saves ~200-500 bytes per provider call, reduces credential fetch overhead.

---

### Long-Term (1-2 months)

9. **Index permission rules by tool name**
   Build associative array `[toolName => PermissionRule[]]` during `PermissionEvaluator` construction. Avoid linear scan of all rules on every check.
   **Impact:** O(1) rule lookup vs O(n). Significant for large rule sets.

10. **Eliminate duplicate rule evaluation**
    Refactor check chain so `RuleCheck` returns both Deny and Ask states in one pass, and `ModeOverrideCheck` reuses that result instead of re-evaluating.
    **Impact:** Cuts rule evaluation overhead by 66% in Guardian mode.

11. **Pre-compile all glob patterns at startup**
    In `PermissionConfigParser`, convert each deny pattern to compiled regex once and store in `PermissionRule` as `\Closure|string`. No runtime compilation.
    **Impact:** Zero regex compilation at runtime.

12. **Add rate limiting to permission evaluation**
    Per-session or per-user limit on permission checks per minute. Prevents DoS via tool spam.
    **Impact:** Thwarts memory exhaustion attacks.

13. **Token refresh debouncing with mutex**
    Use SQLite `BEGIN IMMEDIATE` or file lock to ensure only one refresh occurs concurrently. Others wait and reuse result.
    **Impact:** Prevents refresh storms.

14. **Consider APCu/Redis for cross-request caching**
    - Cache merged config array keyed by file mtimes
    - Cache token in shared memory with TTL
    - Cache compiled regex patterns (though static cache already helps)
    **Impact:** Reduces per-request memory churn dramatically for long-running processes (if ever introduced).

15. **Add config write coalescing**
    Batch multiple `set()` calls within a short window into a single reload. Use a "dirty" flag and debounce reload by 1-2 seconds.
    **Impact:** Prevents write amplification from rapid successive updates.

---

## Conclusion

The permission, authentication, and configuration systems exhibit **critical RAM inefficiencies** that are not merely performance issues but **security-adjacent vulnerabilities**. The lack of caching at every layer creates predictable memory churn patterns that can be exploited for denial-of-service through memory exhaustion. Immediate actions (regex cache, bulk token fetch, in-memory token cache) are low-effort, high-impact fixes that should be deployed within 48 hours. Short-term improvements (memoization, path cache, config reload optimization) will reduce per-request memory churn by an estimated **60-70%**. Long-term architectural changes (rule indexing, duplicate evaluation elimination, rate limiting) are necessary to harden the system against targeted attacks.

**Priority:** Address Critical issues first — they represent the easiest wins with the largest security/performance payoff.

---

**Report Generated By:** KosmoKrator Synthesis Agent
**Source Agents:** permission-system-overhead, codex-auth-integration, config-caching
**Output Path:** `docs/ram-audit/synthesis-security.md`

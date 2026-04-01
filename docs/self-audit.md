# KosmoKrator Self-Audit

> Status: Historical audit from 2026-03-30. Repository size, test counts, and implementation notes may no longer match the current tree.

**Date:** 2026-03-30
**Scope:** Full codebase — `src/`, `tests/`, `config/`
**Stats:** ~13,700 lines PHP 8.4 across 68 source files, 6,200 lines of tests (498 tests, 1060 assertions)

## Architecture Overview

```
bin/kosmokrator → Kernel → AgentCommand → AgentLoop (REPL)
                                           ├── LLM client (AsyncLlmClient or PrismService)
                                           ├── UIManager → TuiRenderer | AnsiRenderer
                                           ├── ToolRegistry → tools (bash, file_read, file_write, file_edit, grep, glob)
                                           └── PermissionEvaluator → approval flow
```

Subsystems: Agent, LLM, Tool (Coding + Permission + Session + Task), UI (TUI + ANSI), Session (SQLite persistence), Task (in-memory tracking).

## What's Done Well

1. **Clean separation of concerns** — Tools, Permissions, Session, LLM, UI are distinct subsystems with narrow interfaces.
2. **Permission system is thoughtful** — Three modes (Guardian/Argus/Prometheus), Guardian uses static heuristics, blocked paths/glob patterns, session grants.
3. **Context management** — Three-tier: Pruner (cheap, replaces old tool results), Compactor (LLM summary), TrimOldest (last resort). Pre-flight check before LLM calls.
4. **Good test coverage** — Unit tests for every subsystem, 498 tests passing.
5. **Instruction loading** — Priority-based: global → project → subdirectory. YAML + SQLite settings with migration path.

---

## Issues & Improvements

### Security Concerns

#### 1. `PermissionRule::matchesGlob()` — `*` matches across word boundaries

**File:** `src/Tool/Permission/PermissionRule.php:45-53`

The glob-to-regex conversion treats `*` as `.*`, which matches `/` and any character. This means Guardian safe-command patterns like `git *` would match `git log && rm -rf /`.

```php
public static function matchesGlob(string $value, string $pattern): bool
{
    $regex = '/^' . str_replace(
        ['\*', '\?'],
        ['.*', '.'],  // `.*` matches everything including spaces and `&&`
        preg_quote($pattern, '/'),
    ) . '$/i';

    return (bool) preg_match($regex, $value);
}
```

**Recommendation:** For command matching, `*` should match non-whitespace only (`[^\s]*`) or the matcher should be aware of shell metacharacters (`&&`, `|`, `;`, backticks, `$()`). Alternatively, parse the command into a first-token + rest and only match against the first token.

---

#### 2. `GrepTool` uses `exec()` instead of Symfony `Process`

**File:** `src/Tool/Coding/GrepTool.php:53`

```php
exec($fullCmd . ' 2>&1', $output, $returnCode);
```

Unlike `BashTool` which uses `Symfony\Component\Process\Process`, `GrepTool` uses raw `exec()`. This means:
- No process timeout
- Not cancellable
- Inconsistent with the rest of the codebase

The `hasRipgrep()` check (line 66) also uses `exec()`.

**Recommendation:** Migrate to Symfony `Process` for consistency and cancellability.

---

#### 3. `ConfigLoader` env var resolution treats `"0"` as empty

**File:** `src/ConfigLoader.php:57-59`

```php
$content = preg_replace_callback('/\$\{(\w+)\}/', function (array $matches) {
    return $_ENV[$matches[1]] ?? $_SERVER[$matches[1]] ?? getenv($matches[1]) ?: '';
}, $content);
```

The `?: ''` fallback coerces `"0"` to `''` because `"0"` is falsy in PHP. If an env var is set to the string `"0"`, it silently becomes empty.

**Recommendation:** Replace `?: ''` with proper false-check:
```php
$env = $_ENV[$matches[1]] ?? $_SERVER[$matches[1]] ?? getenv($matches[1]);
return $env !== false ? $env : '';
```

---

#### 4. `OutputTruncator` truncation file path with empty tool call ID

**File:** `src/Agent/OutputTruncator.php:82`

```php
$path = $this->storagePath . '/tool_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $toolCallId) . '.txt';
```

If `$toolCallId` is empty, the file becomes `tool_.txt`. Subsequent truncations with empty IDs would overwrite each other. Low risk but could lose data.

**Recommendation:** Generate a fallback ID (timestamp + random) when `$toolCallId` is empty.

---

### Bugs & Logic Issues

#### 5. Default provider `'z'` is confusing

**File:** `src/Kernel.php:147`, `src/Command/AgentCommand.php:62`

```php
$provider = $config->get('kosmokrator.agent.default_provider', 'z');
```

The hardcoded fallback to a single-letter provider name `'z'` is unclear. If a user hasn't configured a provider named `z`, the API key lookup returns empty and the agent fails with a generic error instead of a helpful message.

**Recommendation:** Use a well-known provider as default (`'anthropic'` or `'openai'`), or better — detect available providers from configured API keys and pick the first one.

---

#### 6. `PrismService` hardcodes `withMaxSteps(10)`

**File:** `src/LLM/PrismService.php:128`

```php
if (! empty($tools)) {
    $request->withTools($tools);
    $request->withMaxSteps(10);
}
```

The tool-call recursion limit of 10 is hardcoded. Complex refactoring tasks can legitimately need more rounds. When hit, the agent silently stops mid-task.

**Recommendation:** Make this configurable via `config/kosmokrator.yaml` (e.g., `agent.max_tool_rounds: 25`).

---

#### 7. `AgentLoop::executeToolCalls()` receives named args as associative array

**File:** `src/Tool/ToolRegistry.php:46-48`

```php
->using(function (...$args) use ($tool) {
    $result = $tool->execute($args);
    return $result->output;
});
```

Prism calls tool handlers with named arguments. PHP spreads these into an associative array. This works but the contract is implicit — if Prism changes its calling convention, tools break silently.

**Recommendation:** Add a defensive comment or normalize `$args` explicitly. Consider logging when `$args` structure is unexpected.

---

#### 8. `TaskStore::clearTerminal()` has duplicate docblock

**File:** `src/Task/TaskStore.php:240-248`

Two consecutive `/**` docblocks — one says "Remove all completed tasks", the next says "Remove all terminal tasks". The second is correct (the method also removes cancelled tasks).

**Recommendation:** Remove the stale first docblock.

---

### Architecture / Design

#### 9. `AgentCommand::repl()` is a 320-line method

**File:** `src/Command/AgentCommand.php:151-478`

The REPL handles 15+ slash commands (`/quit`, `/settings`, `/resume`, `/guardian`, etc.) with inline logic. Each command has direct access to `$agentLoop`, `$permissions`, `$sessionManager`, `$llm`, etc.

**Recommendation:** Extract into a `SlashCommand` registry pattern:

```php
interface SlashCommand {
    public function name(): string;
    public function handle(Context $ctx, string $args): void;
}
```

This would improve testability and make it easy to add new commands.

---

#### 10. `UIManager` is a pure delegate with leaky abstraction

**File:** `src/UI/UIManager.php`

Every `RendererInterface` method is delegated one-to-one. Additionally, several methods do `instanceof` checks:

```php
public function showWelcome(): void
{
    if ($this->renderer instanceof AnsiRenderer) {
        $this->renderer->showWelcome();
    } elseif ($this->renderer instanceof TuiRenderer) {
        $this->renderer->showWelcome();
    }
}
```

This pattern repeats for `playTheogony()`, `playPrometheus()`, `seedMockSession()`, `setTaskStore()`, `refreshTaskBar()`.

**Recommendation:** Add these methods to `RendererInterface` with default no-op implementations, eliminating the instanceof checks.

---

#### 11. `Kernel` uses Laravel's full Application container

**File:** `src/Kernel.php:61`

```php
$this->container = new LaravelApp($this->basePath);
```

The app bootstraps `Illuminate\Foundation\Application`, Facades, Events, Filesystem, and HTTP factory — all to serve Prism's Laravel integration. This is heavyweight for a CLI tool:

- `LaravelApp` triggers bootstrapping overhead
- Facades add global state
- HTTP factory registered only because Prism uses the `Http` facade

**Recommendation:** For now this works. If binary size or boot time becomes an issue, consider using `illuminate/container` standalone + a thin adapter for Prism.

---

#### 12. `ModelCatalog` uses order-dependent substring matching

**File:** `src/LLM/ModelCatalog.php:63-66`

```php
foreach ($this->models as $name => $spec) {
    if (str_contains($key, strtolower($name))) {
        return $spec;
    }
}
```

If the catalog has both `glm` and `glm-5`, the model `z/GLM-5` matches whichever comes first in the YAML. Order-dependent matching is fragile.

**Recommendation:** Use exact match first (already done), then longest-prefix match instead of first-substring match.

---

#### 13. No streaming for `AsyncLlmClient`

**File:** `src/LLM/AsyncLlmClient.php:40-71`

The async client buffers the entire response body before parsing. For long agent responses, the user sees nothing until the full response arrives. `AgentLoop::run()` calls `$this->ui->streamChunk($fullText)` with the complete text at once — not incremental.

**Recommendation:** Implement SSE streaming for the async client, feeding chunks to the UI as they arrive.

---

#### 14. No retry logic for transient API errors

**File:** `src/Agent/AgentLoop.php:138-161`

The error handling catches all `Throwable` but doesn't distinguish between retryable errors (429 rate limit, 503 service unavailable) and permanent errors (401, 400). A simple retry with exponential backoff for 429/503 would significantly improve reliability.

**Recommendation:** Add retry logic in `AsyncLlmClient::chat()` for HTTP 429 and 5xx responses, with configurable max retries and backoff.

---

#### 15. No concurrent tool execution

**File:** `src/Agent/AgentLoop.php:263-376`

Tool calls are executed sequentially in a `foreach`. Independent tool calls (e.g., reading two different files) could run concurrently, especially with the Amp async client.

**Recommendation:** Group independent tool calls and execute them in parallel using `Amp\Future\awaitAll()`.

---

### Tooling / DX

#### 16. Pint checks `vendor-src/` — should only check `src/` and `tests/`

The Pint `--test` run shows many style violations from `vendor-src/symfony/`. These are not part of the KosmoKrator codebase and should be excluded.

**Recommendation:** Add a `pint.json` configuration:

```json
{
    "paths": ["src", "tests"]
}
```

---

#### 17. `.gitignore` missing entries

Missing: `*.phar`, `composer.phar`, `.phpcs-cache`. The `box.json` output path should also be ignored if building PHARs.

---

## Priority Matrix

| Priority | # | Issue | Impact |
|----------|---|-------|--------|
| **High** | 1 | Glob `*` matches across word boundaries | Security: Guardian bypass |
| **High** | 6 | Hardcoded `maxSteps(10)` | Agent silently stops on complex tasks |
| **Medium** | 3 | Env var `"0"` evaluates to empty | Subtle config bug |
| **Medium** | 5 | Default provider `'z'` is confusing | Bad DX for new users |
| **Medium** | 9 | 320-line REPL method | Maintainability |
| **Medium** | 16 | Pint checks vendor-src | CI noise |
| **Low** | 2 | GrepTool uses `exec()` not `Process` | Consistency, cancellability |
| **Low** | 10 | UIManager instanceof checks | Abstraction leak |
| **Low** | 13 | No streaming for async client | UX improvement |
| **Low** | 14 | No retry for transient API errors | Reliability |
| **Low** | 15 | No concurrent tool execution | Performance |

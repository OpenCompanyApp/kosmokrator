# Website Documentation Audit — 2026-04-08

> 12 parallel explore agents audited every docs page against the actual codebase.
> Each page was read in full and cross-referenced with source code for discrepancies.

## Executive Summary

The docs are **significantly outdated** — large sections describe features that don't exist, many parameters and defaults are wrong, and entire subsystems (Lua integration, skill commands, toast notifications) are completely undocumented. The most severe issues are in `installation.php` (fictional CI/CD section), `tools.php` (4 missing tools, wrong parameter names), and `permissions.php` (wrong fail-open behavior described).

---

## Per-Page Results

### getting-started.php — 5 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **High** | `:commit` power command doesn't exist — should be `:release` (or `:ship`) |
| 2 | **High** | `:refactor` power command doesn't exist at all |
| 3 | Medium | `:debug` is only an alias for `:trace`, not a primary command |
| 4 | Medium | Setup wizard "enter API key" isn't universal — OAuth providers (Codex) use browser/device flow |
| 5 | Medium | Agent modes vs permission modes conflated — `/edit` doesn't mean unrestricted writes |

**Missing**: `/guardian`, `/argus`, `/prometheus` commands; CLI options (`--no-animation`, `--renderer`, `--resume`, `--session`); `config` and `auth` subcommands; project-level config path; `$` skill prefix.

---

### installation.php — 6 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **Critical** | `--headless` and `--prompt` CLI flags do not exist; entire CI/CD section and headless docs are non-functional |
| 2 | **Critical** | `--prometheus` CLI flag does not exist; "Autonomous CI with Prometheus Mode" section is fabricated |
| 3 | **Critical** | Docker section describes nonexistent infrastructure (no Dockerfile, no docker-compose.yml) |
| 4 | **Major** | Missing extensions: `pdo_sqlite`, `curl`, `openssl` not listed despite being runtime requirements |
| 5 | **Major** | "40+ providers" claim is wrong — catalog has ~21 providers |
| 6 | **Major** | PHAR output path is `builds/`, not project root as documented |

**Also**: Setup wizard step order wrong (provider → model → key, not provider → key → model); exit code 2 for permission denied not implemented; Box tool not listed as dev dependency; GitHub Actions examples use nonexistent flags.

---

### configuration.php — 14 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **Major** | Project config discovery described wrong — actually walks up to root, checks both `.kosmokrator/config.yaml` (priority) and `.kosmokrator.yaml` at each level |
| 2 | **Major** | `ui.show_reasoning` setting missing entirely |
| 3 | **Major** | `agent.reasoning_effort` default listed as `off`; actual default is `high` |
| 4 | Medium | Env var expansion: unset vars are removed (empty string), not "preserved as-is" |
| 5 | Medium | Claims `/settings` saves to SQLite — actually writes YAML files |
| 6 | Medium | Claims `/settings` has highest priority — it just writes to YAML (same priority chain) |
| 7 | Medium | Missing YAML keys: `codex.oauth_port`, `integrations.permissions_default`, `tools.denied_tools`, `tools.safe_tools`, `tools.allowed_paths`, `ui.show_reasoning` |
| 8 | Medium | `blocked_paths` lists 3 patterns (actual: 6); `approval_required` missing `execute_lua`; `guardian_safe_commands` shows 3 examples (actual: ~20) |
| 9 | Low | YAML structure ref shows `audio.completion_sound: true` but settings table says default `off` (schema default is `off`) |
| 10 | Low | Missing context settings: `max_output_lines`, `max_output_bytes`, `memory_warning_mb` |
| 11 | Low | `codex` section not documented |
| 12 | Low | `integrations` section not documented |
| 13 | Low | `session.auto_save` and `session.history_dir` only in YAML ref, not explained |
| 14 | Low | Provider/model defaults described as dynamic but hardcoded in schema |

---

### tools.php — 17 issues

| # | Severity | Tool | Issue |
|---|----------|------|-------|
| 1 | **Critical** | apply_patch | Docs say "unified diff format" — code uses `*** Begin Patch` custom format; example is completely wrong |
| 2 | **Critical** | task_create | Primary param `title` should be `subject`; 3 params missing (`active_form`, `parent_id`, `tasks`) |
| 3 | **High** | execute_lua | Entire tool missing from docs |
| 4 | **High** | lua_list_docs | Entire tool missing from docs |
| 5 | **High** | lua_search_docs | Entire tool missing from docs |
| 6 | **High** | lua_read_doc | Entire tool missing from docs |
| 7 | **High** | shell_write | Param `id` should be `session_id`; missing `submit` and `wait_ms` params |
| 8 | **High** | shell_read | Param `id` should be `session_id` |
| 9 | **High** | shell_kill | Param `id` should be `session_id` |
| 10 | **High** | memory_search | `query` is NOT required (all params optional); 3 params missing (`type`, `class`, `scope`) |
| 11 | Medium | memory_save | 4 params missing (`class`, `pinned`, `expires_days`, `id`) |
| 12 | Medium | task_update | `status` NOT required; missing `subject`, `description`, `active_form`, `add_blocked_by`, `add_blocks`; `pending` status undocumented |
| 13 | Medium | ask_choice | `choices` type is `string` (JSON), not `array`; `mockup` param doesn't exist; actual choice objects have `label`/`detail`/`recommended` |
| 14 | Medium | subagent | Missing `agents` batch param; `group` description wrong (sequential, not parallel) |
| 15 | Low | grep | "up to 100 matches" — actually max 50 per file, 100 output lines |
| 16 | Low | file_read | Cache message wording differs from docs |
| 17 | Low | file_edit | Returns separate +/- counts, not a single "line delta" |

---

### providers.php — 7 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **High** | `GOOGLE_API_KEY` should be `GEMINI_API_KEY` |
| 2 | **High** | MiniMax listed under AsyncLlmClient but actually uses PrismService (Anthropic driver) |
| 3 | **High** | Reasoning support significantly understated — 13+ providers have AlwaysOn reasoning, docs list 4 |
| 4 | Medium | Missing env vars: `KIMI_API_KEY`, `MIMO_API_KEY`, `MIMO_PAYG_API_KEY`, `MINIMAX_API_KEY`, `MINIMAX_CN_API_KEY`, `STEPFUN_API_KEY`, `ZAI_API_KEY` |
| 5 | Medium | Model IDs in examples are outdated (e.g., `claude-opus-4-5-20250415` → `claude-opus-4-5-20250929`) |
| 6 | Medium | Anthropic Claude has extended thinking but docs say "No reasoning support" |
| 7 | Low | `mimo-api`, `z-api`, `stepfun-plan` are AsyncLlmClient providers not listed |

---

### agents.php — 10 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **High** | Default subagent type is `explore`, not `general` |
| 2 | **High** | Subagent watchdog default is 900s, not 600s |
| 3 | **High** | No main-agent idle watchdog exists in code — docs claim one at 900s |
| 4 | Medium | Default max retries is 2, not 3 |
| 5 | Medium | Stuck escalation requires 2 consecutive diverse turns to reset, not just one pattern change |
| 6 | Medium | Capabilities table omits shell sessions, memory tools, Lua tools, subagent tool for Explore/Plan |
| 7 | Medium | Batch `agents` parameter not documented |
| 8 | Low | Setting names are `subagent_concurrency`/`subagent_max_depth`, not `max_concurrent`/`max_depth` |
| 9 | Low | Missing statuses: `queued_global`, `retrying`, `cancelled` |
| 10 | Low | Per-depth model overrides not documented |

---

### permissions.php — 16 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **Critical** | Default behavior is **Deny** (fail-closed), docs say **Allow** (fail-open) |
| 2 | **Critical** | `ProjectBoundaryCheck` (stage 4 of 6) completely missing from evaluation chain docs |
| 3 | **Major** | `execute_lua` in `approval_required` defaults but omitted from docs |
| 4 | **Major** | `denied_tools` config option completely undocumented |
| 5 | **Major** | Argus doesn't ask for reads — `file_read` is in `safe_tools`, not `approval_required` |
| 6 | Medium | `safe_tools` config option undocumented |
| 7 | Medium | `allowed_paths` config option undocumented |
| 8 | Medium | Guardian always-safe tools list incomplete (missing Lua tools + execute_lua) |
| 9 | Medium | Argus "no silent auto-approvals" claim is wrong for `safe_tools` |
| 10 | Medium | RuleCheck Ask delegation flow description is misleading |
| 11 | Low | Shell metacharacter behavior differs between safe-command and mutative-command checks |
| 12 | Low | `shell_start`/`shell_write` Guardian heuristics not documented |
| 13 | Low | `ProjectBoundaryCheck` applies to read tools too (file_read, glob, grep) |
| 14 | Low | Mutative detection's per-pipe-segment analysis and safe-redirection stripping undocumented |
| 15 | Info | Glob `*` exclusion of shell metacharacters is a security feature worth documenting |
| 16 | Info | Two different "safe" mechanisms (rules vs heuristics) not distinguished |

---

### context.php — 9 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **Major** | Token budget defaults ALL wrong: 16384→16000, 24576→24000, 12288→12000, 3072→3000 |
| 2 | Medium | Token estimator ratio is 3.2 chars/token, not 4 |
| 3 | Medium | Memory consolidation doesn't merge duplicates — only prunes expired and trims old compaction |
| 4 | Medium | "Pinned" is not a retention class — it's a separate boolean column |
| 5 | Medium | Frozen memory block is NOT rebuilt every turn — it's built once and reused for cache stability |
| 6 | Medium | Pipeline stage timing wrong: output truncation runs during tool execution, deduplication on session load — neither runs during pre-flight |
| 7 | Low | Protected context only contains runtime environment facts, not system prompt or mode instructions |
| 8 | Low | Oldest-turn trimming doesn't loop — runs exactly once per agent loop iteration |
| 9 | Low | Compaction setting key is `compact_threshold`, not `auto_compact_threshold` |

---

### commands.php — 23 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **High** | `/help` command completely missing from docs |
| 2 | **High** | `:legion` power command completely missing |
| 3 | **High** | `:wiki` power command completely missing |
| 4 | **High** | `$` skill command system completely undocumented |
| 5 | **High** | `/seed` description is completely wrong (mock session dev tool, not text injection) |
| 6 | **High** | `:babysit` description wrong (PR monitor, not step-by-step coding) |
| 7 | **High** | `:ralph` description wrong (persistent retry, not blunt code feedback) |
| 8 | **High** | `:learner` description wrong (pattern extraction, not teaching) |
| 9 | Medium | `/tasks clear` should be `/tasks-clear` (hyphen, not space) |
| 10 | Medium | `/new` claims session is "automatically saved" — no explicit save call exists |
| 11 | Medium | `/new` claims "system prompt is regenerated" — no such regeneration occurs |
| 12 | Medium | `/new` undocumented: resets permissions to Guardian |
| 13 | Medium | `/rename` claims interactive prompt if no name — actually shows usage message |
| 14 | Medium | `/feedback` described as direct action — actually injects prompt into LLM conversation |
| 15 | Medium | `/forget` example uses wrong ID format (alphanumeric vs numeric integer) |
| 16 | Medium | `Page Up`/`Page Down` documented as command history — actually scroll conversation |
| 17 | Low | Missing shortcuts: `Shift+Tab` (cycle mode), `Ctrl+L` (force refresh), `End` (jump to live) |
| 18 | Low | No slash command aliases documented (e.g., `/quit`→`/exit`/`/q`, `/agents`→`/swarm`) |
| 19 | Low | No power command aliases documented (e.g., `:review`→`:cr`, `:release`→`:ship`) |
| 20 | Low | Docs claim "two command systems" — actually three (slash, power, skill) |
| 21 | Low | TUI completion list omits `/tasks-clear`, `/help`, `:legion`, `:wiki` |
| 22 | Low | Docs claim "three command systems" scope but only cover two |
| 23 | Low | TUI completion list is inconsistent with registry |

---

### patterns.php — 7 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **Major** | `--headless` flag doesn't exist — entire CI/CD pattern is non-functional |
| 2 | **Major** | `--permission-mode=prometheus` flag doesn't exist |
| 3 | **Major** | Stdin pipe invocation not supported (`echo "..." \| kosmokrator` doesn't work) |
| 4 | **Major** | Config key `subagent_max_concurrency` doesn't exist — should be `subagent_concurrency` |
| 5 | **Major** | Config key `default_mode` doesn't exist — should be `mode` |
| 6 | Medium | `:team` behavior wrong (5-stage sequential pipeline, not parallel exploration) |
| 7 | Medium | `:deepinit` output NOT automatically saved to memory — it writes to `AGENTS.md` file |

---

### ui-guide.php — 10 issues

| # | Severity | Issue |
|---|----------|-------|
| 1 | **Major** | Auto-detection described as "probing terminal capabilities" — actually just checks `class_exists(Tui::class)` |
| 2 | **Major** | `--renderer=tui` doesn't exit with error — silently falls back to ANSI |
| 3 | **Major** | Context bar thresholds wrong: Green 0-50% (not 0-70%), Yellow 50-75% (not 70-90%), Red 75%+ (not 90%+) |
| 4 | **Major** | Tool icons completely wrong — docs show `♄` `♃` `☿` `♂` but code uses `☽` `☉` `♅` `⊛` `✧` `⚡︎` etc. |
| 5 | Medium | "Side-by-side diff" doesn't exist — only unified diff rendering |
| 6 | Medium | Status line does NOT show renderer name |
| 7 | Medium | ANSI startup banner does NOT include `[ansi mode]` |
| 8 | Medium | `--no-interaction` option doesn't exist |
| 9 | Low | Overlay dialogs don't "slide in" — they're just added/removed |
| 10 | Low | Context bar position description conflates statusBar and taskBar |

**Missing**: Keyboard shortcuts (Shift+Tab, Ctrl+L, Page Up/Down, End, Escape, Tab autocomplete); toast notifications; NullRenderer for subagents; `--no-animation` flag.

---

### architecture.php — 15+ omissions

Mostly incomplete rather than wrong. Key missing items:

- **Missing directories**: `src/Settings/`, `src/Provider/`, `src/Athanor/`, `src/Skill/`, `src/Lua/`, `src/Integration/`, `src/Audio/`, `src/Update/`, `src/UI/Diff/`, `src/UI/Highlight/`
- **UIManager** not mentioned as the facade between AgentSession and renderers
- **Service provider system** underplayed (10 providers with register/boot phases)
- **Event system** not mentioned (`src/Agent/Event/`, `src/Agent/Listener/`)
- **ConversationHistory** central data structure not named
- **ToolResultDeduplicator** not mentioned
- **ContextPipeline/ContextPipelineFactory** not named
- `.env` loading via Dotenv not mentioned
- Revolt event loop only mentioned in passing for TUI — fundamental to async architecture

---

## Cross-Cutting Issues

### 1. Lua Integration System — completely absent
4 tools (`execute_lua`, `lua_list_docs`, `lua_search_docs`, `lua_read_doc`) + the entire Lua scripting subsystem (`src/Lua/`) + native tool bridge (`app.tools.*` in Lua) are not mentioned in any docs page.

### 2. Skill Command System — completely absent
`$` prefix commands, auto-discovery from skill directories, and the `$list`/`$create`/`$show`/`$edit`/`$delete` commands are not documented anywhere.

### 3. CI/CD / Headless mode — entirely fictional
`--headless`, `--prompt`, `--permission-mode`, stdin piping — none of these exist. The entire CI/CD sections in installation.php and patterns.php describe non-functional workflows.

### 4. Docker support — entirely fictional
No Dockerfile, no docker-compose.yml, no container image exists. The Docker section in installation.php is aspirational.

### 5. Shell tool parameter names — all wrong
`shell_write`, `shell_read`, `shell_kill` all use `session_id` in code but docs say `id`.

### 6. Power command descriptions — significantly wrong
`:babysit`, `:ralph`, `:learner` have completely wrong descriptions. `:legion` and `:wiki` are missing entirely.

---

## Severity Distribution

| Severity | Count |
|----------|-------|
| Critical | 9 |
| High/Major | 30+ |
| Medium | 25+ |
| Low/Info | 20+ |

**Total issues found: ~85+**

## Recommended Priority for Fixes

1. **Remove fabricated sections** — CI/CD (`--headless`, `--prompt`), Docker, `--prometheus` flag, stdin piping
2. **Fix critical inaccuracies** — permissions fail-closed (not open), apply_patch format, task_create params
3. **Add missing tools** — 4 Lua tools, subagent batch mode
4. **Fix parameter names** — shell tools (`session_id`), task tools (`subject`)
5. **Fix defaults and values** — context budgets, reasoning support, provider env vars, watchdog timeouts
6. **Add missing subsystems** — Lua integration, skills, toast notifications, events
7. **Fix descriptions** — power commands, stuck detection, memory consolidation
8. **Add missing commands and shortcuts** — `/help`, `:legion`, `:wiki`, keyboard shortcuts, aliases

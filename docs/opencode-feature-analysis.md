# OpenCode Feature Analysis for KosmoKrator

Analysis of features from [OpenCode](https://github.com/opencode-ai/opencode) that could be implemented in KosmoKrator, ordered by impact and feasibility.

---

## High Impact — Should Implement

### 1. Permission System for Tool Execution

OpenCode has a rule-based permission system (`allow` / `deny` / `ask`) with glob pattern matching. Tools like `bash`, `file_write`, `file_edit` prompt the user before executing. KosmoKrator currently auto-executes everything.

**How OpenCode does it:**
- Rules defined as `{permission: string, pattern: string, action: "allow" | "deny" | "ask"}`
- Last-match-wins for overlapping patterns, wildcard/glob support
- Rulesets merge hierarchically: system defaults → agent defaults → user config
- Request/reply workflow: tool asks permission, UI shows prompt, user responds "once" / "always" / "reject"
- Edit tools require ask with diff metadata shown to user
- `.env` files always require explicit approval
- Tracks tool calls with messageID + callID for audit

**Scope for KosmoKrator:**
- New `Permission/` namespace with `Rule`, `Ruleset`, `PermissionEvaluator`
- Default rules: `bash` → ask, `file_write`/`file_edit` → ask, `file_read`/`glob`/`grep` → allow
- UI integration: renderer shows permission prompt, user approves/denies
- Config in `kosmokrator.yaml` for user overrides
- "Always allow" memory per session

**Why:** Safety is essential — one wrong `rm -rf` and you've lost work.

---

### 2. Session Persistence (SQLite)

OpenCode persists sessions to SQLite so you can resume conversations, review history, and export/import. KosmoKrator's `ConversationHistory` is in-memory only.

**How OpenCode does it:**
- SQLite via Drizzle ORM with migration system
- Tables: sessions (id, slug, title, directory, version), messages (id, sessionID, role), parts (id, messageID, type, content)
- Message parts are polymorphic: text, tool_call, tool_result, reasoning, snapshot, patch
- Session listing with fuzzy search in TUI dialog
- Archive/restore capability
- Auto-generated session titles via dedicated LLM agent

**Scope for KosmoKrator:**
- SQLite storage at `~/.kosmokrator/sessions.db`
- Schema: sessions table + messages table (JSON content column is simplest start)
- New commands: `/sessions` (list), `/resume <id>` (restore), `/export` (dump JSON)
- `ConversationHistory` backed by SQLite instead of in-memory array
- Session title auto-generation (use the LLM itself with a short prompt)

**Why:** Losing context on restart is a major UX gap.

---

### 3. Context Window Compaction

OpenCode has a dedicated compaction agent that summarizes old messages when approaching token limits, preserving critical context. KosmoKrator has basic `trimOldest()` which just drops messages.

**How OpenCode does it:**
- Dedicated hidden `compaction` agent with its own system prompt
- Triggered at configurable token/message thresholds
- Summarizes old messages into a compact system message
- Preserves critical context: file edits, error messages, tool results
- Maintains conversation continuity — the agent doesn't notice the compaction

**Scope for KosmoKrator:**
- Replace `trimOldest()` with a compaction strategy
- When token count approaches limit, send oldest N messages to LLM with "summarize this conversation segment" prompt
- Replace those messages with a single `SystemMessage` containing the summary
- Keep the most recent messages intact
- Log compaction events

**Why:** The current trim approach loses important context silently, leading to the agent forgetting what it was doing.

---

### 4. Multi-Agent / Subagent System

OpenCode has specialized agents: `build` (full access), `plan` (read-only), `explore` (fast search), `general` (subagent for complex tasks). Each with different tool access and system prompts.

**How OpenCode does it:**
- Agent definitions with: name, tools list, permission ruleset, system prompt, temperature, mode (primary/subagent)
- `build`: default agent, all tools, question/planning allowed
- `plan`: disables all edit tools, read-only exploration
- `explore`: restricted to search tools (glob, grep, read), fast model
- `general`: subagent spawned by build for parallel/complex tasks
- Agent switching via slash command or automatic delegation
- Each agent has its own step limit

**Scope for KosmoKrator:**
- `Agent/AgentDefinition` class with: name, allowed tools, system prompt, temperature, max rounds
- Built-in agents: `code` (full access), `plan` (read-only), `explore` (search only)
- `/plan` and `/code` commands to switch modes
- Agent config in `kosmokrator.yaml`
- ToolRegistry filtered by agent's allowed tools

**Why:** Plan mode and explore mode are very useful for different workflows. Prevents accidental edits during analysis.

---

### 5. Project Instructions (KOSMOKRATOR.md)

OpenCode reads `.opencode/settings.json` and project-level instruction files. KosmoKrator should read project-specific files from the working directory to inject into the system prompt.

**How OpenCode does it:**
- Reads `.opencode/settings.json` for project config
- Merges with user-level `~/.opencode/settings.json`
- Injects environment context: working directory, git status, platform, shell, date
- Custom system prompt additions from config

**Scope for KosmoKrator:**
- On startup, look for `KOSMOKRATOR.md` (or `.kosmokrator/instructions.md`) in CWD
- Read contents and prepend to the system prompt
- Also check `~/.kosmokrator/instructions.md` for global instructions
- Inject environment context: CWD, git branch, platform, PHP version, date

**Why:** Per-project customization is critical — the agent needs to know about coding standards, architecture decisions, and project-specific context.

---

## Medium Impact — Worth Implementing

### 6. Slash Commands & Skills System

OpenCode has a skill system that loads `SKILL.md` files as reusable prompt templates.

**How OpenCode does it:**
- Skills discovered from: `~/.claude/skills/**/SKILL.md`, `~/.agents/skills/**/SKILL.md`, `.opencode/skills/**/SKILL.md`
- Skill format: markdown with YAML frontmatter (name, description)
- Loaded into agent as available slash commands
- Permission-aware: skills can be denied per agent
- Shown in system prompt with descriptions

**Scope for KosmoKrator:**
- Scan `~/.kosmokrator/skills/` and `.kosmokrator/skills/` for `*.md` files
- Parse frontmatter for name/description
- Register as slash commands: `/commit`, `/review`, `/test`, etc.
- When invoked, inject skill content as user message or system prompt addition
- Ship a few built-in skills: `/commit` (generate commit message), `/explain` (explain selected code)

**Why:** Reusable prompt templates save time and ensure consistency.

---

### 7. Accurate Cost Tracking with Per-Model Pricing

OpenCode has detailed per-model pricing tables with cache-aware cost calculation.

**How OpenCode does it:**
- Pricing table per provider/model with input/output/cache rates
- Separate tracking: prompt tokens, completion tokens, reasoning tokens, cache read/write
- Special pricing tiers (200K+ token discounts for some models)
- Cumulative session cost displayed in status bar
- `stats` command for historical cost breakdown

**Scope for KosmoKrator:**
- Pricing config in `config/pricing.yaml` with per-model rates
- Replace hardcoded `estimateCost()` with config-driven calculation
- Track cumulative session cost
- Display per-turn and session-total cost in status bar
- `/cost` command for session cost breakdown

**Why:** Users need to know what they're spending, especially with expensive models.

---

### 8. LSP Integration Tool

OpenCode integrates language servers for go-to-definition, hover info, diagnostics.

**How OpenCode does it:**
- Multi-server support: TypeScript, Python, Go, Rust, C/C++
- LSP features: documentSymbol, hover, definition, references, diagnostics
- Cached diagnostics per file with real-time updates
- Exposed as a tool the agent can call
- Auto-detects which language server to use based on file type

**Scope for KosmoKrator:**
- New `LspTool` in `Tool/Coding/`
- Start language servers as background processes
- Operations: `hover` (type info), `definition` (go-to-def), `diagnostics` (errors/warnings), `references`
- Auto-detect server from file extension (phpstan for PHP, typescript-language-server for TS, etc.)
- Cache server instances per session

**Why:** Gives the agent precise code intelligence beyond grep — especially useful for understanding types and finding references.

---

### 9. Session Revert / Undo

OpenCode can revert to a previous point in conversation, undoing tool calls.

**How OpenCode does it:**
- Version tracking per session
- Snapshot conversation state at key points
- Revert removes messages after snapshot point
- Unrevert to restore if revert was accidental
- Works with persisted sessions (SQLite)

**Scope for KosmoKrator:**
- Snapshot `ConversationHistory` state before each `agentLoop->run()` call
- `/undo` command: pop the last turn (user message + all agent messages/tool calls)
- Store snapshots as stack (last N turns)
- If session persistence is implemented, revert in DB too

**Why:** Very useful when the agent goes down a wrong path — cheaper than `/reset` which loses everything.

---

### 10. Environment Context in System Prompt

OpenCode automatically injects runtime context into the system prompt.

**How OpenCode does it:**
```
Working directory: /path/to/project
Workspace root folder: /path/to/git/root
Is directory a git repo: yes
Platform: darwin
Shell: zsh
OS Version: Darwin 25.0.0
Today's date: 2026-03-29
```

**Scope for KosmoKrator:**
- Gather: CWD, git branch, git root, platform, PHP version, composer.json name/description, date
- Append as system prompt section before user's first message
- Update on each turn if CWD changes (bash `cd`)

**Why:** Small effort, big payoff. The agent makes better decisions when it knows the environment.

---

## Lower Priority — Nice to Have

### 11. MCP (Model Context Protocol) Support

Extend the agent's capabilities dynamically via external MCP servers.

**How OpenCode does it:**
- MCP client with stdio, SSE, and HTTP streaming transports
- Auto-discovers tools from connected MCP servers
- OAuth support for authenticated servers
- Tool list change notifications

**Scope:** New `Mcp/` namespace with client implementation, tool bridge to `ToolRegistry`.

---

### 12. WebFetch / WebSearch Tools

Let the agent browse documentation and search the web.

**Scope:** Two new tools — `WebFetchTool` (HTTP GET + HTML-to-text) and `WebSearchTool` (via SearXNG, Brave, or similar API).

---

### 13. Plugin / Hook System

Extensibility for third-party integrations.

**How OpenCode does it:**
- Hook-based: `chat.system.transform`, `chat.params`, `tool.definition`, `shell.env`, `event`
- Plugins loaded from npm packages or local paths
- Sequential hook execution for deterministic ordering

**Scope:** Event-based hook system using Laravel's `Dispatcher`, plugin discovery from `~/.kosmokrator/plugins/`.

---

### 14. Multi-Provider Support

Easy switching between Claude, OpenAI, Gemini, local models.

**How OpenCode does it:**
- 24+ bundled providers with unified interface
- Model discovery and fuzzy sorting
- Per-model capability detection

**Scope:** Already partially handled by Prism. Need: model selection UI, `/model` command, pricing awareness per provider.

---

### 15. Export / Import Sessions

Share conversations as files.

**Scope:** `/export` command dumps session to JSON/Markdown. `/import` restores from file. Requires session persistence (#2) first.

---

### 16. Task / Todo Management

Persistent task tracking across sessions.

**How OpenCode does it:**
- `TodoWrite` tool for the agent to create/update tasks
- Tasks persisted in session storage
- Displayed in TUI sidebar
- Survive across conversation turns

**Scope:** New `TodoTool`, tasks stored in `~/.kosmokrator/todos/` or session DB, `/todos` command to list.

---

## Implementation Priority

Suggested order based on dependencies and impact:

1. **Environment Context** (#10) — quick win, no dependencies
2. **Project Instructions** (#5) — quick win, no dependencies
3. **Permission System** (#1) — safety-critical, should come before more tools
4. **Session Persistence** (#2) — enables many other features
5. **Context Compaction** (#3) — depends on LLM client being stable
6. **Cost Tracking** (#7) — straightforward config change
7. **Multi-Agent** (#4) — builds on permission system
8. **Skills System** (#6) — builds on slash command infrastructure
9. **Session Revert** (#9) — builds on session persistence
10. **LSP Integration** (#8) — standalone but complex

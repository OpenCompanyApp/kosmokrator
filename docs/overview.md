# KosmoKrator Overview

KosmoKrator is a terminal coding agent built in PHP. The shipped product today is a CLI application with a dual renderer, a tool-driven agent loop, session persistence, context management, slash commands, and a subagent system.

This document is the current-state architecture summary. Proposal and roadmap material lives in other docs and is explicitly labeled there.

## Current Implementation

### Runtime

The runtime entry path is:

```text
bin/kosmokrator
  → Kernel
  → AgentCommand
  → AgentSessionBuilder
  → AgentLoop
```

Key responsibilities:

- `Kernel` boots the Illuminate container, YAML config, logging, Prism provider wiring, SQLite persistence, tools, and commands.
- `AgentSessionBuilder` assembles UI, LLM client, permission evaluator, tool registry, session manager, context management helpers, and subagent infrastructure for an interactive session.
- `AgentLoop` runs the prompt → LLM → tools → LLM loop and handles persistence, mode filtering, context health, and status reporting.

### UI

KosmoKrator ships with two renderers behind `RendererInterface`:

- `TuiRenderer` for the interactive Symfony TUI experience
- `AnsiRenderer` for ANSI/readline fallback

The shared UI layer also includes diff rendering, theming, terminal notifications, subagent tree formatting, and modal/dialog helpers for settings, approvals, and dashboards.

### Tools and Modes

Built-in tool families:

- Coding tools: `file_read`, `file_write`, `file_edit`, `glob`, `grep`, `bash`
- Coordination tools: `subagent`, `task_*`
- Interactive tools: `ask_user`, `ask_choice`

Interactive agent modes:

- `Edit`: full tool access
- `Plan`: read/search/bash/subagent/task/ask tools, but no file mutation tools
- `Ask`: read/search/bash/task/ask tools, but no file mutation tools and no subagents

Permission modes are separate from agent modes:

- `Guardian`: auto-approve safe reads and safe bash, ask for riskier calls
- `Argus`: ask for approval on governed tool calls
- `Prometheus`: auto-approve governed calls except absolute denies

Blocked paths and blocked command patterns are always enforced.

### Persistence and State

KosmoKrator persists state in SQLite under `~/.kosmokrator/data`:

- Sessions and message history
- Global and project-scoped settings
- Memories and compaction summaries
- Token accounting metadata used for status and resume flows

User-visible session flows include `/sessions`, `/resume`, `/new`, `/compact`, `/memories`, and `/forget`.

### Context Management

The current context pipeline is layered:

- output truncation for oversized tool results
- deduplication of superseded tool results
- pruning of older low-value tool outputs
- LLM-based compaction with optional memory extraction
- oldest-turn trimming as an overflow fallback

This is implemented today. Future context experiments live in `docs/context-management-strategies.md` and are not part of the shipped behavior unless stated otherwise.

### Subagents

KosmoKrator ships with a working subagent system:

- agent types: `general`, `explore`, `plan`
- dependency chains with `depends_on`
- sequential groups with `group`
- `await` and `background` execution modes
- retry handling for retryable failures
- concurrency limiting
- live tree/dashboard rendering via `/agents`

See `AGENTS.md` and `docs/subagent-architecture.md` for implementation details.

## What Is Not Implemented

These are still proposal or future-work areas, not shipped runtime features:

- Lua code mode
- MCP client support
- external integration loader / hosted integrations
- desktop app surface
- provider failover across multiple backends in the main runtime

Documents that discuss these topics are design docs, not current feature docs.

## Configuration

Config is loaded in layers, with later layers overriding earlier ones:

1. bundled defaults in `config/*.yaml`
2. user config in `~/.kosmokrator/config.yaml`
3. project config in `.kosmokrator.yaml`

Important config areas:

- `config/prism.yaml` for provider endpoints and API keys
- `config/models.yaml` for model metadata such as context windows and pricing
- `config/kosmokrator.yaml` for agent behavior, permission defaults, UI settings, and context thresholds

Environment variables in YAML are expanded using `${VAR_NAME}`.

## Documentation Map

Use these docs by intent:

- `README.md`: installation, usage, and high-level architecture
- `AGENTS.md`: subagent architecture and orchestration model
- `docs/permission-modes.md`: agent-mode and permission-mode behavior
- `docs/subagent-architecture.md`: current subagent behavior and configuration

The following docs are reference or proposal material:

- `docs/context-management-strategies.md`
- `docs/desktop-app.md`
- `docs/ecosystem-architecture.md`
- `docs/integration-refactor-plan.md`
- `docs/plans/*`
- audit reports under `docs/*audit*` and `DEEP_AUDIT_REPORT.md`

# KosmoKrator Documentation

## Architecture (Current-Truth)

These docs describe shipped behavior. They must be updated when the codebase changes.

| Document | Description |
|----------|-------------|
| [overview.md](architecture/overview.md) | Architecture overview: runtime, UI, tools, context pipeline, subagents, integrations, MCP, config |
| [permission-modes.md](architecture/permission-modes.md) | Agent modes (Edit/Plan/Ask), permission modes (Guardian/Argus/Prometheus), evaluation order |
| [subagent-architecture.md](architecture/subagent-architecture.md) | Subagent types, tool scoping, orchestration, dependency resolution, concurrency |

## Proposals

Forward-looking design docs. Not shipped — may reference classes or features that don't exist yet.

| Document | Description |
|----------|-------------|
| [streaming.md](proposals/streaming.md) | SSE streaming for LLM responses |
| [context-management-redesign.md](proposals/context-management-redesign.md) | 17 proposed context pipeline improvements |
| [context-management-strategies.md](proposals/context-management-strategies.md) | Semantic scoring, dedup tiers, progressive summarization |
| [context-compaction.md](proposals/context-compaction.md) | Historical plan for the first compaction implementation |
| [ecosystem-architecture.md](proposals/ecosystem-architecture.md) | Future ecosystem ideas beyond the shipped integrations CLI, including MCP and broader OpenCompany tool architecture |
| [integration-refactor-plan.md](proposals/integration-refactor-plan.md) | Refactoring tool packages to framework-agnostic contracts |
| [desktop-app.md](proposals/desktop-app.md) | NativePHP + Electron desktop surface proposal |
| [hermes-style-gateway.md](proposals/hermes-style-gateway.md) | Hermes-style Telegram-first gateway surface for KosmoKrator |
| [tui-ux-improvements.md](proposals/tui-ux-improvements.md) | 10 ranked UX improvements with mockups |
| [command-inspiration.md](proposals/command-inspiration.md) | Slash/power command ideas from competitive analysis |
| [laravel-ai-patterns.md](proposals/laravel-ai-patterns.md) | Patterns from Laravel AI SDK worth borrowing |

## Plans

Implementation plans for reviewed but not-yet-shipped work.

| Document | Description |
|----------|-------------|
| [swarm-ux-fix-plan.md](plans/swarm-ux-fix-plan.md) | Phased plan for smoother long-running subagent swarm UX, observability, and durability |

## Audits (Historical)

Write-once audit reports. Findings reference file:line numbers that may have shifted.

| Document | Date | Scope |
|----------|------|-------|
| [deep-audit-2026-04-02.md](audits/deep-audit-2026-04-02.md) | 2026-04-02 | Full codebase (8 domains, 162 files) |
| [self-audit-2026-03-30.md](audits/self-audit-2026-03-30.md) | 2026-03-30 | Initial self-audit (68 files) |
| [memory-leak-audit.md](audits/memory-leak-audit.md) | 2026-04-01 | Memory leak analysis (131 files) |
| [ram-audit/RAM-EFFICIENCY-AUDIT.md](audits/ram-audit/RAM-EFFICIENCY-AUDIT.md) | 2026-04-03 | RAM efficiency synthesis (10 agents) |
| [ram-audit/synthesis-architecture.md](audits/ram-audit/synthesis-architecture.md) | 2026-04-03 | Architecture RAM analysis |
| [ram-audit/synthesis-core-agent.md](audits/ram-audit/synthesis-core-agent.md) | 2026-04-03 | Core agent memory hotspots |
| [ram-audit/synthesis-io-performance.md](audits/ram-audit/synthesis-io-performance.md) | 2026-04-03 | I/O performance and buffering |
| [ram-audit/synthesis-security.md](audits/ram-audit/synthesis-security.md) | 2026-04-03 | Security-adjacent RAM concerns |

## Confidential (Not in Git)

Internal strategy and competitor analysis. Excluded from version control via `.gitignore`.

See `docs/confidential/` — business strategy, token architecture, Claude Code analysis, OpenCode analysis, Reven specs.

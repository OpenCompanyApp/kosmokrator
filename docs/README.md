# KosmoKrator Documentation

## Architecture (Current-Truth)

These docs describe shipped behavior. They must be updated when the codebase changes.

| Document | Description |
|----------|-------------|
| [overview.md](architecture/overview.md) | Architecture overview: runtime, UI, SDK, tools, context pipeline, subagents, ACP, integrations, MCP, config |
| [permission-modes.md](architecture/permission-modes.md) | Agent modes (Edit/Plan/Ask), permission modes (Guardian/Argus/Prometheus), evaluation order |
| [subagent-architecture.md](architecture/subagent-architecture.md) | Subagent types, tool scoping, orchestration, dependency resolution, concurrency |

## Website Documentation

The public documentation site lives in `website/src/content/docs/docs/` and is built with Astro Starlight. Update it whenever a user-facing feature, setting, command, SDK surface, ACP method, integration/MCP behavior, permission behavior, or install/release flow changes.

Key reference pages:

| Page | Purpose |
|------|---------|
| `cli-reference.mdx` | Complete shell command matrix and automation examples |
| `settings-reference.mdx` | Complete setting IDs, defaults, types, scopes, and effect timing |
| `web.mdx` | External web provider setup and tool behavior |
| `gateway-telegram.mdx` | Telegram gateway configuration, routing, and security |
| `sessions-memory.mdx` | Persistence, resume, compaction, and memories |
| `skills.mdx` | `$` skill discovery, creation, and invocation |
| `troubleshooting.mdx` | Diagnostics for setup, credentials, permissions, MCP, integrations, web, and UI |

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
| [desktop-app.md](proposals/desktop-app.md) | Tauri + ACP desktop app wrapper proposal |
| [hermes-style-gateway.md](proposals/hermes-style-gateway.md) | Hermes-style Telegram-first gateway surface for KosmoKrator |
| [tui-ux-improvements.md](proposals/tui-ux-improvements.md) | 10 ranked UX improvements with mockups |
| [command-inspiration.md](proposals/command-inspiration.md) | Slash/power command ideas from competitive analysis |
| [laravel-ai-patterns.md](proposals/laravel-ai-patterns.md) | Patterns from Laravel AI SDK worth borrowing |

## Plans

Implementation plans for reviewed but not-yet-shipped work.

| Document | Description |
|----------|-------------|
| [swarm-ux-fix-plan.md](plans/swarm-ux-fix-plan.md) | Phased plan for smoother long-running subagent swarm UX, observability, and durability |
| [user-telemetry-otel-plan.md](plans/user-telemetry-otel-plan.md) | Opt-in user telemetry and crash diagnostics plan using sanitized events, an OpenCompany ingestion endpoint, and optional OpenTelemetry routing |

## Audits (Historical)

Write-once audit reports. Findings reference file:line numbers that may have shifted.

| Document | Date | Scope |
|----------|------|-------|
| [php-file-audit-2026-04-08.md](audits/php-file-audit-2026-04-08.md) | 2026-04-08 | PHP file audit |
| [website-docs-audit-2026-04-08.md](audits/website-docs-audit-2026-04-08.md) | 2026-04-08 | Website documentation audit |
| [deep-audit-2026-04-08-error-handling.md](audits/deep-audit-2026-04-08-error-handling.md) | 2026-04-08 | Error handling audit |
| [deep-audit-2026-04-08-logic-bugs.md](audits/deep-audit-2026-04-08-logic-bugs.md) | 2026-04-08 | Logic bug audit |
| [deep-audit-2026-04-08-resource-management.md](audits/deep-audit-2026-04-08-resource-management.md) | 2026-04-08 | Resource management audit |
| [deep-audit-2026-04-08-session-persistence.md](audits/deep-audit-2026-04-08-session-persistence.md) | 2026-04-08 | Session persistence audit |
| [memory-leak-audit.md](audits/memory-leak-audit.md) | 2026-04-01 | Memory leak analysis (131 files) |
| [ram-audit/RAM-EFFICIENCY-AUDIT.md](audits/ram-audit/RAM-EFFICIENCY-AUDIT.md) | 2026-04-03 | RAM efficiency synthesis (10 agents) |
| [ram-audit/synthesis-architecture.md](audits/ram-audit/synthesis-architecture.md) | 2026-04-03 | Architecture RAM analysis |
| [ram-audit/synthesis-core-agent.md](audits/ram-audit/synthesis-core-agent.md) | 2026-04-03 | Core agent memory hotspots |
| [ram-audit/synthesis-io-performance.md](audits/ram-audit/synthesis-io-performance.md) | 2026-04-03 | I/O performance and buffering |
| [ram-audit/synthesis-security.md](audits/ram-audit/synthesis-security.md) | 2026-04-03 | Security-adjacent RAM concerns |

## Confidential (Not in Git)

Internal strategy and competitor analysis. Excluded from version control via `.gitignore`.

See `docs/confidential/` — business strategy, token architecture, Claude Code analysis, OpenCode analysis, Reven specs.

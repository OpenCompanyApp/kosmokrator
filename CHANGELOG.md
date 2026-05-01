# Changelog

All notable user-facing changes to KosmoKrator are documented in this file.

This changelog follows the shape of [Keep a Changelog](https://keepachangelog.com/en/1.1.0/): changes are grouped by release and by impact. The root `CHANGELOG.md` is the source of truth for GitHub Releases and the website changelog.

## [Unreleased]

### Added
- Canonical changelog workflow with release extraction, PR enforcement, and website publishing.
- KosmoKrator MCP gateway for exposing selected integrations and upstream MCP servers to Claude Code and other MCP clients.
- Live provider model discovery commands, cached model inventories, provider model diagnostics, and unlisted-model overrides for newly launched models.
- Website SEO matrices for integration CLI, MCP client/framework setup, category hubs, use cases, comparison pages, structured data, and robots/sitemap discovery.
- Website megafooter and complete site map so docs, use cases, MCP pages, comparisons, categories, CLI shortcuts, and generated integration matrix pages stay reachable within two clicks.

## [0.7.1] - 2026-04-29

### Added
- Headless ACP stdio server for editor, IDE, and desktop app clients.
- Headless Agent SDK for embedding KosmoKrator in PHP applications.
- Headless MCP configuration, runtime, discovery, Lua, and command surfaces.
- ACP headless integration extensions for runtime-only MCP servers and client-driven sessions.
- Optional external web providers for search, fetch, and crawl workflows, including Brave, Exa, Firecrawl, Jina, OpenAI native search, Anthropic native search, Parallel, Perplexity, SearxNG, and Tavily.
- Startup smoke command for validating source, PHAR, and binary installs.

### Changed
- Improved swarm and subagent UX with better status trees, stats, persisted output, and dashboard data.
- Expanded configuration, settings, providers, secrets, integrations, MCP, and web commands for headless automation.
- Improved docs, homepage highlighting, and SDK documentation coverage.
- Stabilized Lua execution through a compatibility runner on PHP 8.4.
- Improved integration runtime, catalog, documentation, and cache handling.

### Fixed
- Fixed PHAR release packaging for OpenCompany integration classes.
- Fixed release workflow PHAR smoke tests to run under PHP 8.4.
- Fixed Lua chunk execution and Lua test behavior in environments without the extension.
- Fixed environment-context test stability in CI.
- Fixed homepage hero tabs and terminal positioning.

## [0.7.0] - 2026-04-21

### Added
- Hermes-style Telegram gateway with configuration and status commands.
- Gateway UX, model switcher, and update command improvements.
- `web_search` and `web_fetch` tools backed by a provider system.
- Dual-mode session search and `session_read` tool.
- Lua documentation for the expanded runtime.

### Changed
- Unified setup flow with the settings workspace.
- Improved setup command behavior in PHAR prompt fallback paths.
- Updated tests for web tool integration.

### Fixed
- Made Lua integration checks optional when the real extension is unavailable in CI.

## [0.6.3] - 2026-04-11

### Fixed
- Autoloaded the Lua polyfill for static analysis.

## [0.6.2] - 2026-04-11

### Fixed
- Made Lua checks optional in environments without the Lua extension.

## [0.6.1] - 2026-04-11

### Fixed
- Made the integrations dependency source safe for CI installs.

## [0.6.0] - 2026-04-11

### Added
- Headless CLI mode for CI/CD, scripts, and non-interactive automation.
- SwiftUI-style reactive primitive layer for the TUI renderer.
- Subagent batch-mode documentation and Lua workflow documentation.
- Session search tool with enhanced memory and context support.
- Expanded integration and Lua documentation workflow.

### Changed
- Overhauled TUI state management with reactive builders, signal batching, and consolidated animation timers.
- Moved signal primitives through the Athanor namespace as part of production hardening.
- Migrated history/status UI to reactive widgets and removed older renderer effect plumbing.
- Improved logging, security layers, and runtime cleanup paths.
- Removed file-read caching, including the Lua cache bypass path, to reduce stale reads.

### Fixed
- Improved LLM retry logging and cleared stale discovery batch state.
- Fixed reactive bridge restart and shutdown edge cases.

## [0.5.2] - 2026-04-07

### Added
- Chain-based permission evaluator with project-boundary checks.
- Streaming support and major session-management refactors.
- Lua integration support and expanded permission coverage.
- `:wiki` power command.
- Subagent tool access from Lua.
- Scrollable session picker and expanded session list behavior.

### Changed
- Hid reasoning output by default.
- Overhauled settings handling and TUI internals.
- Hardened security checks around malformed JSON and patch application.

### Fixed
- Fixed stuck-detector recovery behavior.
- Removed stale settings reload throttling.
- Fixed CI failures across the updated runtime.

## [0.5.1] - 2026-04-05

### Fixed
- Updated `prism-relay` so reasoning strategy classes are available.
- Removed the commit pin from the `prism-relay` dependency.

## [0.5.0] - 2026-04-05

### Added
- Free-text model provider support.
- Guardian pipe analysis.
- Improved subagent error handling.

### Fixed
- Aligned prune tests with terminal-state semantics.
- Added `iconv` and session extensions to static binary builds.
- Fixed code style issues.

## [0.4.2] - 2026-04-05

### Fixed
- Hardened the self-update system against silent failures.
- Fixed self-updater test style.

## [0.4.1] - 2026-04-05

### Added
- Install script with OS and architecture auto-detection.
- Fail-safe curl behavior for installation.

## [0.4.0] - 2026-04-05

### Added
- Website with `/docs` section and Railway deployment.
- Install tabs and mobile-responsive docs tables.
- HTML/CSS visualizations replacing ASCII diagrams in architecture docs.
- New website docs pages for architecture, getting started, patterns, and the UI guide.
- User skill system with `$` completion support.
- Static binary builds for macOS and Linux alongside the PHAR.

### Changed
- Updated architecture overview docs with improved diagrams.
- Added Bootstrap 5 for website styling.
- Improved release binary builds with `static-php-cli`.
- Consolidated configuration paths.

### Fixed
- Disabled the JavaScript minifier after it broke syntax.
- Fixed white flash during navigation.
- Removed stale SRI hashes from Bootstrap CDN links.
- Fixed docs sidebar and mobile responsiveness.
- Stripped C0 control characters in bash command widgets to prevent render exceptions.
- Fixed install commands requiring `sudo` for `/usr/local/bin`.
- Removed dead bash streaming code.
- Fixed subagent background filtering in the TUI.

## [0.3.1] - 2026-04-04

### Fixed
- Fixed CI grep portability and PHPStan vendor-class suppressions.
- Excluded website output from Pint.
- Fixed release workflow YAML parsing.
- Removed unavailable macOS runners from the release workflow.
- Simplified the release workflow to PHAR-only while static binary builds were being repaired.

## [0.3.0] - 2026-04-04

### Added
- SkillLoader multi-directory support for `.kosmokrator/skills/`, `.agents/skills/`, and `~/.kosmokrator/skills/`.
- `SubagentOrchestrator::ignorePendingFutures()` for clean async shutdown.
- `SkillLoader::getDiscoveryDirs()` for listing all skill search paths.
- Skill completions in the TUI renderer with the `$` prefix.
- ANSI renderer support for skill completion calls.

### Fixed
- Resolved PHPStan issues across the codebase.
- Limited CI unit-test runs to avoid hanging feature tests.
- Fixed PHAR and static binary verification steps.
- Consolidated config paths under `~/.kosmokrator/`.
- Removed unused task-store injection from `ProtectedContextBuilder`.
- Fixed `TaskStore` handling for failed tasks.
- Wrapped async-sensitive tests correctly.

### Changed
- Simplified `SkillDispatcher` return types.
- Removed unused `AsyncLlmClient::mapMessages()` and `TaskStore::hasActiveChildren()`.

## [0.2.0] - 2026-04-04

### Added
- PowerCommand system with babysit, consensus, deep-dive, docs, release, research, and review modes.
- ANSI renderer enhancements for power command displays.
- Reasoning/thinking support with effort setting and UI display.
- `/unleash` command with cosmic swarm animation.

### Changed
- Improved AgentLoop and SubagentOrchestrator behavior.
- Updated TUI core renderer internals.
- Added documentation inspired by command audits.

### Fixed
- Background agents now start concurrently.
- `/unleash` and missing commands now appear in slash autocomplete.
- Fixed subagent deadlock paths.

## [0.1.1] - 2026-04-04

### Fixed
- Cast tool parameters to objects for correct JSON schema serialization.

## [0.1.0] - 2026-04-03

### Added
- Initial KosmoKrator terminal agent release.
- Mythology-themed CLI built with PHP 8.4, Symfony Console, Symfony TUI, and ANSI fallback rendering.
- Provider setup, settings persistence, file and shell tools, sessions, permission prompts, and subagent orchestration.

### Fixed
- README badges, settings save-on-quit behavior, and gitignore cleanup before the first tagged release.

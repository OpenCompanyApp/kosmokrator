# Changelog

All notable changes to this project will be documented in this file.

## [0.4.0] - 2026-04-04

### Added
- Website with /docs section and Railway deployment
- Install tabs and mobile-responsive docs tables
- HTML/CSS visualizations replacing ASCII diagrams in architecture docs
- New website docs pages: architecture, getting-started, patterns, ui-guide

### Fixed
- Disabled JS minifier (broke syntax), fixed white flash on navigation
- Removed stale SRI hashes from Bootstrap CDN links
- Docs sidebar and mobile responsiveness

### Changed
- Updated architecture overview docs with improved diagrams
- Added Bootstrap 5 for website styling

## [0.3.0] - 2026-04-04

### Added
- SkillLoader multi-directory support (`.kosmokrator/skills/`, `.agents/skills/`, `~/.kosmokrator/skills/`)
- `SubagentOrchestrator::ignorePendingFutures()` for clean async shutdown
- `SkillLoader::getDiscoveryDirs()` for listing all skill search paths
- Skill completions in TUI renderer (`$` prefix)
- `setSkillCompletions()` stub in ANSI renderer

### Fixed
- 251 PHPStan errors resolved (code fixes + config suppressions for UI animation noise)
- CI workflows: test runner now targets `tests/Unit` only (excludes hanging Feature test)
- Release workflow: PHAR and static binary verification steps fixed
- Config paths consolidated to `~/.kosmokrator/` (removed duplicate `~/.config/kosmokrator/`)
- `ProtectedContextBuilder` no longer receives unused `$taskStore` parameter
- `TaskStore` match expression now handles `TaskStatus::Failed`
- ShellSessionManager and SubagentTool tests properly wrapped in async context

### Changed
- `SkillDispatcher` methods `showSkill()` and `deleteSkill()` now return `void`
- Removed unused `AsyncLlmClient::mapMessages()`, `TaskStore::hasActiveChildren()`

## [0.2.0] - 2026-04-03

### Added
- PowerCommand system with babysit, consensus, deep-dive, docs, release, research, and review modes
- ANSI renderer enhancements for all power command displays
- Reasoning/thinking support with effort setting and UI display
- `/unleash` command with cosmic swarm animation

### Fixed
- Background agents now start concurrently
- `/unleash` and missing commands added to slash autocomplete list
- Subagent deadlock resolution

### Changed
- AgentLoop and SubagentOrchestrator improvements
- TUI core renderer updates

## [0.1.1] - 2026-04-01

## [0.1.0] - 2026-03-31

Initial release.

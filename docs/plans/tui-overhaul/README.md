# KosmoKrator TUI Overhaul — The Giga Plan

> Goal: Build a world-class, award-winning terminal UI that sets the standard for AI coding agents.

## Status: Research & Planning Phase

Each subdirectory contains a detailed plan researched by dedicated agents.
See `00-MASTER-PLAN.md` for the consolidated execution plan.

## Directory Structure

| # | Directory | Scope |
|---|-----------|-------|
| 00 | `MASTER-PLAN.md` | Consolidated execution plan with priorities and phases |
| 01 | `reactive-state/` | Signal/Computed system, TuiStateStore, TuiEffectRunner |
| 02 | `widget-library/` | New widgets: Scrollbar, Table, Tabs, Tree, Sparkline, Gauge, Image |
| 03 | `virtual-scrolling/` | VirtualMessageList, OffscreenFreeze, height caching |
| 04 | `theming/` | Semantic theming, auto-downsample, dark/light, accessibility |
| 05 | `mouse-support/` | SGR mouse tracking, click handling, scroll wheel, drag |
| 06 | `layout/` | Responsive layout, CSS Grid, compositor, Z-ordering |
| 07 | `existing-widgets/` | Refactor SettingsWorkspaceWidget, CollapsibleWidget, etc. |
| 08 | `animation/` | Spring physics, easing, animation system, phase transitions |
| 09 | `input-system/` | Keybinding system, command palette, vim modes |
| 10 | `testing/` | Snapshot testing, visual regression, widget testing |
| 11 | `ai-chat-patterns/` | Streaming optimization, stable/unstable lines, prefix caching |
| 12 | `terminal-features/` | Undercurl, images, braille art, notifications |
| 13 | `architecture/` | Overall architecture, dependency injection, cleanup |
| 14 | `subagent-display/` | Swarm dashboard, agent tree, live progress |

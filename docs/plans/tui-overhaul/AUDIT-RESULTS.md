# TUI Overhaul — Consolidated Audit Results

**Date**: 2026-04-07
**Scope**: 57 plan documents across 14 categories
**Codebase**: `src/UI/Tui/` — 77 source files, 35 test files

---

## 1. Executive Summary

| Metric | Value |
|--------|-------|
| Plan documents audited | 57 |
| Infrastructure classes built | 77 |
| Test files | 35 (606 tests, 1781 assertions) |
| Plans fully implemented | ~12 (21%) |
| Plans partially implemented | ~27 (47%) |
| Plans not started | ~18 (32%) |
| **Avg infrastructure completion** | **~62%** |
| **Avg wiring/integration completion** | **~25%** |

**Core finding**: A wide infrastructure-vs-wiring gap exists. Library code for most subsystems is built (Signal primitives, Animation, Z-ordering, Keybinding, Theming, Streaming, Layout) but the majority is **not wired into the render pipeline**. `TuiCoreRenderer` still uses 16+ direct `flushRender()` calls and does not import or use `ZCompositor`, `WidgetCompactor`, `RenderScheduler`, `StreamingThrottler`, or `AnimationDriver`.

---

## 2. Completed This Session

| # | Item | Evidence |
|---|------|----------|
| 1 | PhaseStateMachine wired into TuiAnimationManager | `TuiAnimationManager.php:11` imports PhaseStateMachine, line 35 stores it, line 125 instantiates |
| 2 | 21 stylesheet entries for all new widgets | `KosmokratorStyleSheet.php` — entries for ScrollbarWidget, TableWidget, TreeWidget, SparklineWidget, GaugeWidget + sub-selectors |
| 3 | ToastManager wired to error display | `TuiCoreRenderer.php:535` calls `ToastManager::error()`, `TuiInputHandler.php:379` calls `ToastManager::info()` |
| 4 | HelpOverlayWidget uses KeybindingRegistry dynamically | `HelpOverlayWidget.php:9` imports, line 53 stores `?KeybindingRegistry`, line 108 renders from registry |
| 5 | Ctrl+A bug fixed (proper keybinding, not `\x01`) | `TuiInputHandler.php` — no `\x01` references remain |
| 6 | Three new themes registered | `ThemeManager.php:430-432` — `minimal`, `high-contrast`, `daltonized` with full token maps |
| 7 | 606 TUI tests passing | PHPUnit: `Tests: 606, Assertions: 1781` — all pass |
| 8 | SettingsSchema test fix | Resolved from prior session |

---

## 3. Infrastructure Status Matrix

| System | Infra % | Wiring % | Key Classes | Status |
|--------|---------|----------|-------------|--------|
| **Signal / Reactive** | 90% | 10% | `Signal`, `Computed`, `Effect`, `EffectScope`, `Subscriber`, `BatchScope` | Primitives complete; no `TuiStateStore` or `TuiEffectRunner` to connect them |
| **Phase State Machine** | 100% | 80% | `Phase`, `PhaseStateMachine`, `InvalidTransitionException` | Wired into `TuiAnimationManager`; missing `BreathingAnimationController` |
| **Animation** | 85% | 15% | `Animation`, `AnimationDriver`, `EasingFunction`, `Spring`, `AnimationController`, `AnimationPreferences`, `AnimationState`, `FillMode`, `PlaybackDirection` | Library complete; `AnimationDriver` not used by renderer |
| **Widget Library** | 85% | 70% | 20+ widget classes | Most widgets built and functional; `CommandPalette` and `ImageWidget` missing |
| **Theming** | 90% | 30% | `ThemeManager`, `ThemeTokens`, `ColorConverter`, `ColorDownsampler`, `ColorProfile`, `TerminalColorDetector` | Manager + 4 themes registered; `KosmokratorStyleSheet` not migrated to token-based theming |
| **Streaming** | 60% | 10% | `StreamingThrottler`, `StreamingMarkdownBuffer`, `ChunkedStringBuilder` | Throttler + buffer built; not wired into streaming flow; missing `PlainTextWidget` |
| **Layout** | 80% | 40% | `Breakpoint`, `TerminalDimension`, `DimensionProvider`, `ZLayer`, `ZCompositor` | Responsive breakpoints active in stylesheet; `ZCompositor` not wired into renderer |
| **Input / Keybinding** | 75% | 40% | `KeybindingRegistry`, `KeybindingContext`, `Conflict`, `HelpGenerator`, `InputHistory`, `HistoryEntry` | Registry built and used by `HelpOverlayWidget` + `TuiInputHandler`; raw key comparisons still exist |
| **Mouse** | 50% | 0% | `MouseEvent`, `MouseAction`, `MouseButton`, `MouseParser` | Parser built; no `MouseCoordinator`, no widget routing |
| **Performance** | 65% | 5% | `RenderScheduler`, `WidgetCompactor`, `AnsiStringPool`, `MemoryProfiler` | All classes built; none wired into renderer pipeline |
| **Terminal Features** | 40% | 10% | `TerminalCapabilities`, `AdvancedTextDecoration` | Capabilities + decoration classes built; no Symfony TUI vendor patches |
| **Virtual Scrolling** | 0% | 0% | — | Nothing exists |
| **DI / Architecture** | 5% | 0% | — | Profiler + timer done; no DI container, no render benchmarking |

---

## 4. Plan-by-Plan Audit Summary

### 01 — Reactive State

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-signal-primitives.md` | ✅ 90% | ⚠️ 20% | Missing `Signal::of()` / `Computed::of()` factories; no unit tests |
| `02-tui-state-store.md` | ❌ 0% | ❌ 0% | `TuiStateStore` does not exist |
| `03-effect-runner.md` | ❌ 0% | ❌ 0% | `TuiEffectRunner` / `RenderPriority` enum do not exist |
| `04-phase-state-machine.md` | ✅ 100% | ✅ 80% | Missing `BreathingAnimationController` |
| `05-migration-plan.md` | ⚠️ 6% | ⚠️ 5% | 12 `flushRender()` calls remain; Steps 0.2–5.3 not started |

### 02 — Widget Library

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-scrollbar-widget.md` | ✅ 95% | ✅ 95% | Minor symbol set differences |
| `02-table-widget.md` | ✅ 100% | ✅ 100% | — |
| `03-tabs-widget.md` | ✅ 100% | ✅ 100% | — |
| `04-tree-widget.md` | ✅ 100% | ✅ 100% | — |
| `05-sparkline-gauge.md` | ✅ 100% | ✅ 100% | — |
| `06-image-widget.md` | ❌ 0% | ❌ 0% | Deferred |
| `07-modal-dialog-system.md` | ✅ 95% | ✅ 90% | Missing signal-based entrance animation |
| `08-toast-notifications.md` | ✅ 98% | ✅ 95% | Minor namespace difference |
| `09-status-bar-widget.md` | ✅ 100% | ✅ 100% | — |
| `10-command-palette.md` | ❌ 0% | ❌ 0% | Not implemented |

### 03 — Virtual Scrolling

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-virtual-message-list.md` | ❌ 0% | ❌ 0% | No `VirtualScroll` directory |
| `02-offscreen-freeze.md` | ❌ 0% | ❌ 0% | Not started |

### 04 — Theming

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-semantic-theming.md` | ✅ 85% | ⚠️ 40% | `KosmokratorStyleSheet` not migrated to token-based theming |
| `02-color-downsampling.md` | ✅ 90% | ⚠️ 50% | Missing CLI `--color=` flags |
| `03-dark-light-detection.md` | ⚠️ 40% | ⚠️ 30% | Missing Linux gsettings, Windows registry, OSC 11 queries |

### 05 — Mouse Support

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-mouse-tracking.md` | ⚠️ 35% | ❌ 0% | No `MouseCoordinator`, no widget mouse routing |

### 06 — Layout

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-responsive-layout.md` | ⚠️ 65% | ⚠️ 50% | Partially integrated into renderer |
| `02-compositor-z-ordering.md` | ⚠️ 50% | ❌ 0% | `ZCompositor` not wired into renderer pipeline |

### 07 — UX Improvements (18 plans)

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `ux-01` through `ux-18` | Varies | Varies | Most UX plans are satisfied by existing widget implementations; specific gaps tracked below |

### 08 — Animation

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-animation-system.md` | ⚠️ 55% | ❌ 10% | `AnimationDriver` built but `TuiAnimationManager` doesn't use it |

### 09 — Input System

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-keybinding-refactor.md` | ⚠️ 50% | ⚠️ 40% | `TuiInputHandler` still has raw key comparisons alongside registry |

### 10 — Testing

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-snapshot-testing.md` | ❌ 0% | ❌ 0% | No snapshot testing framework |
| `02-widget-unit-testing.md` | ⚠️ 60% | ⚠️ 60% | `WidgetTestCase` and `SnapshotTestCase` exist; not all widgets covered |

### 11 — AI Chat / Streaming

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-streaming-optimization.md` | ⚠️ 50% | ❌ 10% | Throttler/buffer built; not wired into streaming flow |

### 12 — Terminal Features

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-undercurl-underline.md` | ⚠️ 40% | ❌ 10% | No Symfony TUI vendor patches |

### 13 — Architecture

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-memory-profiling.md` | ✅ 95% | ⚠️ 60% | Profiler built; needs integration hooks |
| `02-widget-compaction.md` | ⚠️ 60% | ❌ 0% | `WidgetCompactor` not wired into renderer |
| `03-string-interning.md` | ⚠️ 50% | ❌ 5% | Missing `StringBuilder`, `RenderBuffer`, `ContentCache` |
| `04-streaming-memory.md` | ⚠️ 60% | ❌ 10% | Missing `StreamingRenderMode`, `StreamingMemoryBudget` |
| `05-timer-efficiency.md` | ✅ 80% | ❌ 0% | `RenderScheduler` not wired into `TuiAnimationManager` |
| `06-dependency-injection.md` | ❌ 0% | ❌ 0% | No DI container |
| `07-render-benchmarking.md` | ❌ 0% | ❌ 0% | Not started |
| `08-startup-optimization.md` | ⚠️ 10% | ⚠️ 10% | Only `sleep(1)` removed from intro path |

### 14 — Subagent Display

| Plan Doc | Infra | Wiring | Top Gap |
|----------|-------|--------|---------|
| `01-swarm-dashboard-v2.md` | ⚠️ 50% | ⚠️ 40% | `SwarmDashboardWidget` exists; needs live data wiring |

---

## 5. Critical Wiring Gaps (Top 10)

Built but **not connected** — ranked by user-facing impact:

| # | Component | Built Class | Should Wire To | Impact |
|---|-----------|-------------|----------------|--------|
| 1 | RenderScheduler | `RenderScheduler` | `TuiAnimationManager` | Replaces 4 independent timers, reduces CPU waste |
| 2 | Z-order compositor | `ZCompositor`, `ZLayer` | `TuiCoreRenderer` render path | Enables overlapping overlays, modals, toasts |
| 3 | Widget compaction | `WidgetCompactor` | `TuiCoreRenderer` | Memory management for long sessions |
| 4 | Streaming throttle | `StreamingThrottler` + `StreamingMarkdownBuffer` | Streaming response flow | Prevents flickering, reduces redundant renders |
| 5 | Animation driver | `AnimationDriver`, `EasingFunction`, `Spring` | `TuiAnimationManager` | Enables smooth transitions instead of janky timer-based system |
| 6 | Semantic theming | `ThemeManager` + `ThemeTokens` | `KosmokratorStyleSheet` | Theme switching requires token-based stylesheet |
| 7 | String interning | `AnsiStringPool` | Render buffer path | Reduces memory for repeated ANSI sequences |
| 8 | Keybinding full migration | `KeybindingRegistry` | `TuiInputHandler` raw comparisons | Eliminates duplicate key handling paths |
| 9 | Mouse routing | `MouseParser` → widgets | Input event dispatch | Mouse support dead-ends at parser |
| 10 | Terminal capabilities | `TerminalCapabilities`, `AdvancedTextDecoration` | Renderer output | Styled underlines/undercurl require vendor patches |

---

## 6. Missing Implementations (Not Built At All)

| # | Component | Plan Doc | Description |
|---|-----------|----------|-------------|
| 1 | `TuiStateStore` | `01-reactive-state/02` | Centralized reactive state — renderer still uses 16+ plain properties |
| 2 | `TuiEffectRunner` | `01-reactive-state/03` | Signal→render pipeline with priority scheduling |
| 3 | `CommandPaletteWidget` | `02-widget-library/10` | Fuzzy-search command palette — 0% |
| 4 | `VirtualMessageList` | `03-virtual-scrolling/01` | Virtual scrolling for long chat histories |
| 5 | `OffscreenFreeze` | `03-virtual-scrolling/02` | Freeze off-screen widgets — 0% |
| 6 | `ImageWidget` | `02-widget-library/06` | Inline image rendering — deferred |
| 7 | `MouseCoordinator` | `05-mouse-support/01` | Widget-level mouse event routing |
| 8 | DI Container | `13-architecture/06` | Dependency injection refactoring — 0% |
| 9 | Render Benchmarking | `13-architecture/07` | Frame timing / render profiling — 0% |
| 10 | Snapshot Testing | `10-testing/01` | Visual regression testing framework — 0% |
| 11 | `PlainTextWidget` | `11-ai-chat/01` | Lightweight streaming text widget |
| 12 | `StreamingMemoryBudget` | `13-architecture/04` | Memory budget enforcement for streaming |
| 13 | `StringBuilder` / `RenderBuffer` / `ContentCache` | `13-architecture/03` | Render pipeline buffer management |
| 14 | Linux gsettings / Windows reg / OSC 11 | `04-theming/03` | Cross-platform dark mode detection |
| 15 | Symfony TUI vendor patches | `12-terminal-features/01` | Styled underline / undercurl support |

---

## 7. Test Coverage Status

| Category | Test Files | Tests | Assertions | Coverage |
|----------|-----------|-------|------------|----------|
| **Signal primitives** | 5 | ~30 | ~90 | `Signal`, `Computed`, `Effect`, `EffectScope`, `BatchScope` |
| **Phase state machine** | 1 | 28 | ~80 | `PhaseStateMachine` transitions |
| **Toast system** | 4 | ~25 | ~60 | `ToastManager`, `ToastItem`, `ToastOverlayWidget`, `ToastPhase`, `ToastType` |
| **Widgets** | 18 | ~350 | ~1000 | Scrollbar, Table, Tabs, Tree, Sparkline, Gauge, StatusBar, plus app widgets |
| **Shared infra** | 7 | ~170 | ~550 | Various widget tests |
| **Total TUI** | 35 | 606 | 1781 | — |

### Not tested

- `RenderScheduler` — no test file
- `WidgetCompactor` — no test file
- `ZCompositor` / `ZLayer` — no test file
- `AnimationDriver` / `Spring` / `EasingFunction` — no test file
- `KeybindingRegistry` — no test file
- `StreamingThrottler` / `StreamingMarkdownBuffer` — no test file
- `ThemeManager` — no test file (beyond manual theme registration)
- `MouseParser` — no test file
- `TerminalCapabilities` / `AdvancedTextDecoration` — no test file
- `AnsiStringPool` — no test file
- `ChunkedStringBuilder` — no test file
- `ColorConverter` / `ColorDownsampler` — no test file

### Key test infrastructure

- `tests/UI/Tui/Widget/WidgetTestCase.php` — base case with terminal mocking
- `tests/UI/Tui/Widget/SnapshotTestCase.php` — exists but snapshot testing not yet operational

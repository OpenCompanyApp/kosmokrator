# KosmoKrator TUI Overhaul — Master Execution Plan

> **49 detailed plan documents** | **1.6 MB of research** | **~60 subagents deployed**

This master plan consolidates all research into a phased execution roadmap for building a world-class TUI.

---

## Architecture Decision: Stay on Symfony TUI

**Confirmed**: We build on top of `symfony/tui` (our OpenCompanyApp fork). No framework switch.

| Reason | Detail |
|--------|--------|
| Only viable PHP option | php-tui stalled since Sep 2024, no async, no focus, no CSS cascade |
| Deep integration | 8,700 lines of TUI code, Revolt event loop, fiber suspensions |
| PR is active | fabpot's PR #63778 targeting Symfony 8.1, we're pinned to HEAD |
| Right gaps | The missing pieces (mouse, scrollbar, table, tabs) we build ourselves |

---

## The 5 Pillars

### Pillar 1: Reactive State Foundation
**Documents**: `01-reactive-state/01–05` (5 docs)
**Effort**: ~2 weeks
**Impact**: Unlocks everything else

| Component | Purpose | Replaces |
|-----------|---------|----------|
| `Signal<T>` | Reactive value holder with subscribers | 30+ private fields across 5 classes |
| `Computed<T>` | Cached derived values | Manual recomputation in refreshStatusBar() |
| `Effect` | Auto-run on dependency change | 56 manual flushRender() calls |
| `TuiStateStore` | Centralized signal registry | Scattered state in 4 managers |
| `TuiEffectRunner` | Batches renders via EventLoop::defer() | 4 independent 30fps timers |
| `PhaseStateMachine` | Formal phase transition guard | Implicit phase logic in AnimationManager |

**Migration**: 5-phase incremental, each step leaves TUI working. See `01-reactive-state/05-migration-plan.md`.

### Pillar 2: Widget Library Expansion
**Documents**: `02-widget-library/01–10` (10 docs)

| Widget | Priority | Effort | Key Feature |
|--------|----------|--------|-------------|
| ScrollbarWidget | P0 | 2d | Visual scroll position, Unicode blocks |
| StatusBarWidget | P0 | 2d | Replaces ProgressBarWidget hack, 3-segment layout |
| TabsWidget | P1 | 1d | Numbered shortcuts, single-line bar |
| TreeWidget | P1 | 3d | Hierarchical nodes, expand/collapse, lazy loading |
| ToastWidget | P1 | 2d | Auto-dismiss, slide animation, stacked |
| TableWidget | P1 | 3d | Scrollable rows, column widths, sorting |
| GaugeWidget | P2 | 1d | Gradient fill, inline label |
| SparklineWidget | P2 | 1d | Braille/block sparkline for status bar |
| CommandPaletteWidget | P2 | 3d | Fuzzy search, Ctrl+P, categorized |
| ModalDialogSystem | P1 | 3d | Backdrop dimming, centering, focus trap, stack |
| ImageWidget | P3 | 3d | Kitty/iTerm2/Sixel with fallback chain |

### Pillar 3: Performance & Efficiency
**Documents**: `13-architecture/01–08` (8 docs)

| Optimization | Target | Savings |
|-------------|--------|---------|
| Widget compaction (Active→Settled→Compacted→Evicted) | < 50MB RAM | 76–81% for long sessions |
| Streaming ChunkedStringBuilder | Streaming memory | 75% fewer allocations |
| String interning (AnsiStringPool + Theme cache) | 30fps alloc rate | 36 KB/s, 1440 strings/s eliminated |
| Timer consolidation (4→1) | CPU usage | 90→30 renders/sec |
| Offscreen freeze | Render CPU | 80% reduction (~560→108ms/s) |
| Virtual scrolling | Render time for 200+ msgs | 42× improvement |
| Streaming prefix caching | Markdown parse time | 80% parse overhead eliminated |
| Startup optimization | Time to interactive | Target < 500ms |
| Render profiling | CI regression detection | Automated perf budget |

### Pillar 4: Visual Polish
**Documents**: `04-theming/01–03`, `06-layout/01–02`, `08-animation/01`, `12-terminal-features/01–02`

| Feature | Description |
|---------|-------------|
| Semantic theming | 50+ color tokens, 4 built-in themes (Cosmic, Minimal, High Contrast, Daltonized) |
| Color downsampling | TrueColor→256→16→ASCII auto-adapt |
| Dark/light detection | OSC 11 query + COLORFGBG, auto-switch |
| Responsive layout | Remove 11 hardcoded widths, breakpoints at 60/80/120 cols |
| Compositor Z-ordering | Modal (Z=100), toast (Z=90), pill (Z=50), dropdown (Z=40) |
| Animation system | 13 easing functions + spring physics + reduced motion |
| Undercurl/underline | 5 semantic decorations (error, diff, search, interactive, divider) |
| Braille visualization | Sparklines in status bar, agent progress |
| Mouse support | SGR tracking, click-to-focus, scroll wheel, drag |

### Pillar 5: UX Excellence
**Documents**: `07-existing-widgets/ux-01–18` (18 UX audits)

Critical findings across all audits:

| Area | Grade | Top Issue |
|------|-------|-----------|
| Onboarding | D | 8-second animation blocks first interaction |
| Conversation flow | C+ | Thinking→streaming flash, two-widget tool pattern |
| Tool display | B- | Visual weight too heavy, need inline badges |
| Status feedback | C+ | No stall detection, no contextual verbs |
| Input experience | D+ | No history recall, 2-line cap kills multi-line |
| Error handling | C- | Errors scroll away, no classification |
| Navigation | D | 11 keybindings with zero persistent hints |
| Subagent visibility | B | Dashboard excellent but hidden (Ctrl+A) |
| Permission prompts | B- | 5 options conflate 3 decision axes |
| Settings | C+ | `q` saves (dangerous), no search, empty categories |
| Visual hierarchy | C | Tool calls brighter than responses (inverted) |
| Accessibility | D | No color-blind support, no reduced motion, no screen reader |
| Session management | C+ | Picker has no search, no confirmation on delete |
| Diff display | B | Word-level nearly invisible, no file headers |
| Scrolling | D | No scrollbar, no mouse wheel, no position feedback |
| Prompt editing | D | Rich engine hidden behind 2-line cap |
| Mental model | C | Mode confusion, information density issues |

---

## Execution Phases

### Phase 1: Foundation (Weeks 1–3)
*Unblocks all other work. No visible UI changes yet.*

- [ ] Implement Signal/Computed/Effect primitives (`01-reactive-state/01`)
- [ ] Build TuiStateStore (`01-reactive-state/02`)
- [ ] Build TuiEffectRunner (`01-reactive-state/03`)
- [ ] Build PhaseStateMachine (`01-reactive-state/04`)
- [ ] Migrate TuiAnimationManager to signals (`01-reactive-state/05`)
- [ ] Implement snapshot testing framework (`10-testing/01`)
- [ ] Consolidate timers: 4→1 (`13-architecture/05`)

### Phase 2: Core Infrastructure (Weeks 4–6)
*Visible improvements start landing.*

- [ ] Virtual scrolling: VirtualMessageList + OffscreenFreeze (`03-virtual-scrolling/01–02`)
- [ ] Widget compaction system (`13-architecture/02`)
- [ ] Streaming optimization: ChunkedStringBuilder + prefix caching (`11-ai-chat-patterns/01`)
- [ ] Responsive layout: remove hardcoded widths (`06-layout/01`)
- [ ] Semantic theming + color downsampling (`04-theming/01–02`)
- [ ] Animation driver with easing + springs (`08-animation/01`)
- [ ] String interning + Theme caching (`13-architecture/03`)

### Phase 3: Widget Library (Weeks 7–10)
*New widgets ship incrementally.*

- [ ] ScrollbarWidget (`02-widget-library/01`)
- [ ] StatusBarWidget — replace ProgressBarWidget (`02-widget-library/09`)
- [ ] ModalDialogSystem — backdrop, centering, focus trap (`02-widget-library/07`)
- [ ] ToastWidget (`02-widget-library/08`)
- [ ] TabsWidget (`02-widget-library/03`)
- [ ] TreeWidget (`02-widget-library/04`)
- [ ] TableWidget (`02-widget-library/02`)
- [ ] GaugeWidget + SparklineWidget (`02-widget-library/05`)

### Phase 4: Interaction (Weeks 11–13)
*Mouse, input, keybindings.*

- [ ] Mouse support: SGR tracking, click, scroll, drag (`05-mouse-support/01`)
- [ ] Keybinding registry with YAML config (`09-input-system/01`)
- [ ] Command palette with fuzzy search (`02-widget-library/10`)
- [ ] Input history with Ctrl+R reverse search (from UX-05)
- [ ] Remove EditorWidget 2-line cap (from UX-16)
- [ ] `?` help overlay (from UX-07)

### Phase 5: Polish & Delight (Weeks 14–16)
*The final 10% that makes it award-winning.*

- [ ] Compositor with Z-ordering (`06-layout/02`)
- [ ] Undercurl/underline decorations (`12-terminal-features/01`)
- [ ] Braille sparklines in status bar (`12-terminal-features/02`)
- [ ] Dark/light auto-detection (`04-theming/03`)
- [ ] Dalitonized theme (`04-theming/01`)
- [ ] Swarm dashboard V2 (`14-subagent-display/01`)
- [ ] ImageWidget (`02-widget-library/06`)
- [ ] Onboarding redesign (from UX-01)
- [ ] Render profiling + CI regression (`13-architecture/07`)
- [ ] Startup optimization (`13-architecture/08`)

---

## Document Index

### 01 — Reactive State (5 docs)
| # | File | Lines | Covers |
|---|------|-------|--------|
| 01 | `signal-primitives.md` | 1091 | Signal, Computed, Effect, BatchScope, EffectScope + 30 state inventory |
| 02 | `tui-state-store.md` | 923 | 30 signals, 6 computed values, full migration map |
| 03 | `effect-runner.md` | ~800 | 3 priority levels, replaces 56 render calls, 6-phase migration |
| 04 | `phase-state-machine.md` | 962 | Phase enum, transition table, BreathingAnimationController |
| 05 | `migration-plan.md` | ~700 | 20 closures catalogued, 5-phase incremental migration |

### 02 — Widget Library (10 docs)
| # | File | Widget | Key Feature |
|---|------|--------|-------------|
| 01 | `scrollbar-widget.md` | ScrollbarWidget | Proportional thumb, 3 symbol sets |
| 02 | `table-widget.md` | TableWidget | Column widths, scrolling, sorting |
| 03 | `tabs-widget.md` | TabsWidget | Numbered shortcuts, single-line bar |
| 04 | `tree-widget.md` | TreeWidget | Flatten approach, lazy loading, Unicode connectors |
| 05 | `sparkline-gauge.md` | Sparkline + Gauge | Block chars, gradient fill, indeterminate mode |
| 06 | `image-widget.md` | ImageWidget | Kitty/iTerm2/Sixel/chafa fallback chain |
| 07 | `modal-dialog-system.md` | ModalOverlay + Dialog + Button | Backdrop dim, centering, focus trap |
| 08 | `toast-notifications.md` | ToastManager + ToastOverlay | Slide animation, stacked, auto-dismiss |
| 09 | `status-bar-widget.md` | StatusBarWidget | 3-segment, responsive, mode-aware bg |
| 10 | `command-palette.md` | CommandPaletteWidget | Fuzzy matching, categorized, 46+ commands |

### 03 — Virtual Scrolling (2 docs)
| # | File | Covers |
|---|------|--------|
| 01 | `virtual-message-list.md` | 7 classes, height cache, reconcile(), 42× perf improvement |
| 02 | `offscreen-freeze.md` | OffscreenFreezeCoordinator, 80% CPU reduction |

### 04 — Theming (3 docs)
| # | File | Covers |
|---|------|--------|
| 01 | `semantic-theming.md` | 50+ tokens, 4 themes, ThemeManager, YAML user themes |
| 02 | `color-downsampling.md` | ColorProfile detection, TrueColor→256→16 conversion |
| 03 | `dark-light-detection.md` | OSC 11 probe, luminance calc, dual theme variants |

### 05 — Mouse Support (1 doc)
| # | File | Covers |
|---|------|--------|
| 01 | `mouse-tracking.md` | MouseParser, MouseCoordinator, hit-testing, 4-phase plan |

### 06 — Layout (2 docs)
| # | File | Covers |
|---|------|--------|
| 01 | `responsive-layout.md` | 11 hardcoded widths removed, breakpoint system |
| 02 | `compositor-z-ordering.md` | Layer system, Z-index, CellBuffer compositing |

### 07 — UX Audits (18 docs)
18 comprehensive UX research reports covering every aspect of the TUI experience. See individual files.

### 08 — Animation (1 doc)
| # | File | Covers |
|---|------|--------|
| 01 | `animation-system.md` | 13 easing functions, spring physics, AnimationDriver, reduced motion |

### 09 — Input System (1 doc)
| # | File | Covers |
|---|------|--------|
| 01 | `keybinding-refactor.md` | KeybindingRegistry, YAML config, multi-key sequences, conflict detection |

### 10 — Testing (2 docs)
| # | File | Covers |
|---|------|--------|
| 01 | `snapshot-testing.md` | SnapshotRenderer, SnapshotTestCase, 44 snapshot files |
| 02 | `widget-unit-testing.md` | WidgetTestCase, renderWidget(), assertRenderEquals() |

### 11 — AI Chat Patterns (1 doc)
| # | File | Covers |
|---|------|--------|
| 01 | `streaming-optimization.md` | Rate-adaptive throttle, plain text fast-path, stable/unstable split |

### 12 — Terminal Features (2 docs)
| # | File | Covers |
|---|------|--------|
| 01 | `undercurl-underline.md` | 5 semantic decorations, capability detection, fallback chain |
| 02 | `braille-visualization.md` | BrailleRenderer, 2×4 sub-pixel grid, sparklines |

### 13 — Architecture (8 docs)
| # | File | Covers |
|---|------|--------|
| 01 | `memory-profiling.md` | Hotspot analysis, SIGUSR1 handler, /mem command, targets |
| 02 | `widget-compaction.md` | 4-stage lifecycle, 76–81% RAM savings |
| 03 | `string-interning.md` | AnsiStringPool, Theme cache, RenderBuffer reuse |
| 04 | `streaming-memory.md` | ChunkedStringBuilder, streaming window, lazy parse |
| 05 | `timer-efficiency.md` | RenderScheduler, 4→1 timers, adaptive frame rate |
| 06 | `dependency-injection.md` | TuiContainer, interface extraction, 32→0 closures |
| 07 | `render-benchmarking.md` | RenderProfiler, perf overlay, CI regression |
| 08 | `startup-optimization.md` | Lazy init, parallel startup, < 500ms target |

### 14 — Subagent Display (1 doc)
| # | File | Covers |
|---|------|--------|
| 01 | `swarm-dashboard-v2.md` | 7-panel dashboard, interactive tree, resource meter |

---

## Key Metrics (Targets)

| Metric | Current | Target |
|--------|---------|--------|
| Manual flushRender() calls | 56 | ~5 |
| Closure dependencies | 32 | 0 |
| Independent timers | 4 | 1 |
| RAM (30-min session) | ~100+ MB | < 50 MB |
| RAM (60-min session) | ~200+ MB | < 30 MB |
| Render time (50 msgs) | Unknown | < 16ms |
| Render time (200 msgs) | Unknown | < 16ms |
| Time to interactive | ~8s | < 500ms (no animation) |
| CPU idle | Unknown | < 1% |
| CPU streaming | Unknown | < 15% |
| Widget count (new) | 0 | 11 |
| UX audit score (avg) | C | A |
| Accessibility score | 2/10 | 7/10 |
| Snapshot tests | 0 | 44+ |

---

## What Makes This Award-Winning

1. **Reactive state** — no manual render calls, automatic batching, signal-based everything
2. **Constant-time rendering** — virtual scrolling means 50 messages or 5000, same render time
3. **Bounded RAM** — widget compaction ensures memory never grows unbounded
4. **Spring physics animations** — natural, responsive transitions that feel alive
5. **Semantic theming** — 4 built-in themes + user customization + auto dark/light
6. **Full mouse support** — click, scroll, drag in a terminal app
7. **World-class widget library** — scrollbar, table, tabs, tree, sparkline, toast, command palette
8. **Accessibility first-class** — daltonized theme, reduced motion, accessible mode, high contrast
9. **Snapshot-tested** — every visual state has a golden file, regressions caught in CI
10. **Award-winning UX** — 18 UX audits identifying and fixing every rough edge

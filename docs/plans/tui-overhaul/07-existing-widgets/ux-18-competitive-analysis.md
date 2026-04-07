# UX Competitive Analysis: KosmoKrator TUI vs. AI Coding Agents

> **Research Question**: How does KosmoKrator's TUI compare feature-by-feature with competing AI coding agents?
>
> **Date**: 2026-04-07
> **Analyst**: UX Research Agent
> **Competitors evaluated**: Claude Code, Aider, Cursor, Codex CLI, OpenCode

---

## Executive Summary

KosmoKrator occupies a **unique middle ground** in the AI coding agent space: it is the only PHP-based full-screen TUI agent, and the only one with a mythological/celestial design language, discovery batching, and a live subagent tree. Its visual polish and information density exceed all terminal competitors except Claude Code. However, it trails Claude Code in streaming quality, Cursor in overall visual polish (owing to GUI advantages), and all competitors in accessibility. KosmoKrator's biggest competitive gaps are in **progressive onboarding**, **keyboard discoverability**, and **screen reader support**. Its biggest advantages are **discovery batching**, **subagent visualization**, and **thematic cohesion**.

**Bottom line**: KosmoKrator has the most ambitious terminal UI of any coding agent, but ambition without polish creates friction. The top 5 features to steal from competitors would close critical UX gaps without diluting KosmoKrator's unique identity.

---

## 1. Competitor Profiles

### 1.1 Claude Code (Anthropic)

Terminal-based agentic coding tool. Renders full-screen conversation with tool-use blocks. Built on Ink (React for CLI) under the hood, giving it a component-based architecture. Supports streaming with Markdown rendering, tool call approval prompts, and a minimal status line.

- **Platform**: Terminal (Node.js)
- **Rendering**: Ink (React-based CLI rendering)
- **Key UI features**: Streaming markdown, collapsible tool results, diff preview, `--dangerously-skip-permissions` mode, slash commands, multi-turn conversation with context summaries, compact mode
- **Design language**: Clean, minimal, developer-oriented. Gray + blue palette. No thematic branding.

### 1.2 Aider (Paul Gauthier)

Python-based AI pair programming tool. Operates in a chat-style terminal interface — not full-screen TUI, but a scrollable terminal session with syntax-highlighted diffs, repo map display, and command-based interaction.

- **Platform**: Terminal (Python, `rich` + `prompt_toolkit`)
- **Rendering**: Rich library for formatting, prompt_toolkit for input
- **Key UI features**: Syntax-highlighted diffs inline, repo map visualization, `/commands`, lint/test integration output, voice mode, model switching, architect/editor split mode
- **Design language**: Functional, information-dense. Uses `rich` formatting but no full-screen layout.

### 1.3 Cursor (Cursor Inc.)

Desktop IDE (fork of VS Code) with deeply integrated AI. Not a terminal tool, but sets the benchmark for AI coding UX. Features inline diffs, multi-file editing preview, codebase-aware autocomplete, agent mode with tool-use, and chat sidebar.

- **Platform**: Desktop GUI (Electron, VS Code fork)
- **Rendering**: Electron/Chromium
- **Key UI features**: Inline diff visualization, tab-autocomplete, agent chat panel, multi-file preview, keyboard-driven command palette, syntax highlighting via TextMate grammars, image/URL support in chat
- **Design language**: VS Code aesthetic. Dark theme default, professional, dense but navigable.

### 1.4 Codex CLI (OpenAI)

Terminal-based coding agent released as open source. Minimalist — runs in the terminal with a chat-like interface. Supports tool execution, file editing, and autonomous mode. Lightweight compared to Claude Code.

- **Platform**: Terminal (Node.js)
- **Rendering**: Simple terminal output (no full-screen TUI framework)
- **Key UI features**: Autonomous execution mode, approval prompts, diff display, sandboxed execution, quiet mode
- **Design language**: Extremely minimal. Plain terminal output, minimal color. Almost no UI chrome.

### 1.5 OpenCode (OpenCode)

Go-based terminal AI coding agent. Full-screen TUI built with Bubble Tea (Charm). Features a split-pane layout with conversation and file preview, syntax highlighting, and tool-use display.

- **Platform**: Terminal (Go, Bubble Tea)
- **Rendering**: Bubble Tea framework (Lip Gloss for styling)
- **Key UI features**: Split-pane layout (chat + file preview), syntax-highlighted code blocks, session management, MCP tool support, model selection, diff view, file tree browser
- **Design language**: Clean, modern TUI. Lip Gloss styling with rounded borders, consistent palette. Closest to KosmoKrator in TUI ambition.

---

## 2. Feature Comparison Matrix

### 2.1 Scoring Methodology

Each dimension is scored 1–10 based on:
- **Available evidence**: Documentation, GitHub repos, user reports, screenshots, direct analysis
- **Relative to the field**: 5 = average, 7 = good, 9 = excellent, 10 = best-in-class
- **KosmoKrator as baseline**: Scores are absolute, not relative to KosmoKrator

| Dimension | KosmoKrator | Claude Code | Aider | Cursor | Codex CLI | OpenCode |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| **Visual Polish** | 7 | 7 | 5 | 9 | 3 | 7 |
| **Responsiveness/Smoothness** | 7 | 8 | 7 | 9 | 7 | 7 |
| **Information Density** | 8 | 7 | 8 | 8 | 4 | 6 |
| **Discoverability** | 5 | 6 | 5 | 9 | 3 | 5 |
| **Error Handling** | 6 | 7 | 6 | 8 | 4 | 5 |
| **Input Experience** | 7 | 7 | 7 | 9 | 5 | 6 |
| **Tool Call Display** | 9 | 7 | 6 | 8 | 3 | 6 |
| **Streaming Quality** | 6 | 9 | 7 | 9 | 6 | 7 |
| **Memory/CPU Efficiency** | 5 | 6 | 7 | 3 | 8 | 7 |
| **Accessibility** | 2 | 4 | 3 | 7 | 2 | 3 |
| **TOTAL** | **62** | **68** | **59** | **79** | **45** | **56** |

### 2.2 Score Justifications

#### Visual Polish (7/7/5/9/3/7)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 7 | Rich celestial theme with custom tool icons (☿♀♁), breathing animations, splash screen orrery. Impressive but inconsistent — some widgets are polished (permission prompts, diffs) while others feel raw (plain text error messages, no scrollbar). The mythological branding is unique but occasionally borders on excessive. |
| **Claude Code** | 7 | Clean, professional, understated. Markdown rendering is solid. Minimal chrome — no animations, no branding flourish. Consistent but never exciting. |
| **Aider** | 5 | Uses `rich` for formatting but no full-screen layout. Scrollback-heavy, no structured panels. Functional but visually utilitarian. |
| **Cursor** | 9 | Full GUI with VS Code's rendering engine. Inline diffs with word-level highlighting, smooth animations, proper modals, syntax themes. The gold standard for visual polish in AI coding tools. |
| **Codex CLI** | 3 | Bare terminal output. No full-screen layout, minimal color. Intentionally minimal to the point of feeling unfinished. |
| **OpenCode** | 7 | Bubble Tea + Lip Gloss produces a polished TUI. Rounded borders, consistent color palette, clean split-pane layout. Lacks KosmoKrator's thematic depth but is more consistently executed. |

#### Responsiveness/Smoothness (7/8/7/9/7/7)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 7 | Symfony TUI uses differential rendering (only changed cells written). Breathing animations at 30fps. Occasional render jank during heavy streaming — the `flushRender()` synchronous call can cause micro-stutters. Subagent tree refreshes at 2Hz. |
| **Claude Code** | 8 | Ink's React-like reconciliation produces smooth updates. Streaming is very fluid. Occasional flicker on large tool outputs. |
| **Aider** | 7 | No full-screen TUI means no render overhead. Output appears as fast as the terminal can scroll. Input via `prompt_toolkit` is snappy. |
| **Cursor** | 9 | Chromium rendering with hardware acceleration. Smooth animations, instant diff updates. Occasionally sluggish on large workspaces. |
| **Codex CLI** | 7 | Minimal UI = minimal render cost. Simple streaming output. No animations to jank. |
| **OpenCode** | 7 | Bubble Tea's Elm architecture is efficient. Smooth for typical workloads. Can lag with very large conversation histories. |

#### Information Density (8/7/8/8/4/6)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 8 | Status bar packs mode + permissions + tokens + context into one line. Task bar shows nested task tree. Discovery batching compresses 10+ file reads into one collapsible group. Tool calls show icon + path + line range in one line. High density, though sometimes overwhelming. |
| **Claude Code** | 7 | Good use of collapsible sections. Context window usage shown. Compact mode available. Tool results can be expanded/contracted. |
| **Aider** | 8 | Extremely dense — repo map, diff hunks, lint output, cost tracking all visible in scrollback. No structured layout means information is dense but disorganized. |
| **Cursor** | 8 | Sidebar chat + inline diffs + tabs + file tree = high density. Well-organized through spatial layout. |
| **Codex CLI** | 4 | Minimal output by design. Tool calls shown but not structured. Little metadata visible. |
| **OpenCode** | 6 | Split-pane shows chat + file, but limited metadata in the conversation. No token tracking, no task visualization. |

#### Discoverability (5/6/5/9/3/5)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 5 | Slash commands (`/`) and power commands (`:`) are discoverable via autocomplete dropdown. But no persistent keybinding hints, no first-run tutorial, no `?` help overlay. Status bar shows current mode but not what keys do. The mythological naming (omens, oracles) is charming but opaque to new users. |
| **Claude Code** | 6 | Slash commands visible via `/help`. Permission modes explained on first use. `--help` is comprehensive. Still lacks inline keybinding hints. |
| **Aider** | 5 | `/help` lists commands. `/map` shows repo structure. No interactive discovery — everything is command-based and requires reading docs. |
| **Cursor** | 9 | VS Code's command palette (Ctrl+Shift+P), inline tooltips, keyboard shortcut hints, first-run walkthrough, settings UI. The gold standard for discoverability. |
| **Codex CLI** | 3 | Almost no discoverability. No command palette, minimal help output. Users must read the README. |
| **OpenCode** | 5 | Keyboard shortcuts shown in a help overlay (`?`). Model selection and session management accessible via keybindings. Still requires memorization. |

#### Error Handling (6/7/6/8/4/5)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 6 | Errors shown in red (`#ff5040`). Bash failures auto-expand. But error messages are plain text with no structured formatting, no suggested fixes, no error categorization. The `showError()` method (`TuiCoreRenderer.php:497-499`) is a simple styled text append — no severity levels, no actionable recovery. |
| **Claude Code** | 7 | Structured error blocks with context. API errors show rate limits and retry information. Permission errors explain what's needed. |
| **Aider** | 6 | Errors appear inline with `rich` formatting. Lint/test failures shown with file/line context. But errors can scroll away quickly. |
| **Cursor** | 8 | Inline error diagnostics, squiggly underlines, hover-to-see-details, error panel. Proper IDE-level error handling. |
| **Codex CLI** | 4 | Errors are plain text. Minimal context. No structured error display. |
| **OpenCode** | 5 | Errors visible in conversation but not formatted differently from regular output. No error categorization or recovery suggestions. |

#### Input Experience (7/7/7/9/5/6)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 7 | `EditorWidget` with multiline (Shift+Enter), slash/power/dollar command autocomplete with `SelectListWidget` overlay, tab completion. Mode cycling via Shift+Tab. Message queuing during execution. No input history (up arrow), no multi-line visual indicator, no syntax highlighting in input. |
| **Claude Code** | 7 | Multiline input, shift+enter for newlines, paste support, `/commands` with tab completion. No input history browsing. |
| **Aider** | 7 | `prompt_toolkit` provides excellent input: history (up/down), tab completion, multiline mode, syntax highlighting. Best terminal input of the pure-CLI tools. |
| **Cursor** | 9 | Full text editor input with autocomplete, markdown preview, image/URL embedding, @-mentions for files/symbols, code block insertion. GUI input is inherently superior. |
| **Codex CLI** | 5 | Basic readline-style input. No autocomplete, no multiline, no command suggestions. |
| **OpenCode** | 6 | Bubble Tea text input. Supports multiline and basic completion. No history browsing, no rich autocomplete. |

#### Tool Call Display (9/7/6/8/3/6)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 9 | Best-in-class among terminal tools. Celestial tool icons (☽☉♅⚡⊛✧), collapsible results with 3-line preview, discovery batching (`DiscoveryBatchWidget`), Lua code with syntax highlighting, `BashCommandWidget` with auto-expand on failure, diff rendering with word-level highlighting, subagent tree visualization, tool execution spinners with elapsed time. This is KosmoKrator's strongest dimension. |
| **Claude Code** | 7 | Clean collapsible tool blocks. Diff preview. File read results with syntax highlighting. No tool icons, no batching, no execution time display. |
| **Aider** | 6 | Diffs shown inline with `rich` formatting. SEARCH/REPLACE blocks visible. No tool call categorization or collapsing. |
| **Cursor** | 8 | Inline diff visualization with accept/reject. File edits shown as proper diffs. Multi-file changes grouped. Agent tool calls visible in chat. |
| **Codex CLI** | 3 | Tool calls shown as plain text. No categorization, no collapsing, no syntax highlighting. |
| **OpenCode** | 6 | Tool calls visible in conversation with basic formatting. File edits shown. No batching, no execution timers, no categorization. |

#### Streaming Quality (6/9/7/9/6/7)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 6 | Streaming works via `streamChunk()` appending to `MarkdownWidget` or `AnsiArtWidget`. Issues: each chunk calls `setText()` on the full accumulated string (O(n²) string concatenation), synchronous `flushRender()` during streaming can cause stalls, no token-by-token streaming (chunks arrive in blocks), markdown re-renders on every chunk. The streaming is functional but not smooth — visible "chunky" updates rather than character-by-character flow. |
| **Claude Code** | 9 | Excellent streaming. Character-by-character output, smooth markdown rendering as it arrives, minimal reflow. Ink's reconciliation handles incremental updates well. The streaming "feels" like talking to an AI — fast, fluid, alive. |
| **Aider** | 7 | Streaming via `rich` live display. Smooth for typical outputs. Can stutter on very long responses. |
| **Cursor** | 9 | Chromium renders streaming text instantly. Markdown renders progressively. Typing animation for responses. Very fluid. |
| **Codex CLI** | 6 | Basic streaming output. Functional, not polished. No progressive markdown rendering. |
| **OpenCode** | 7 | Bubble Tea handles streaming well. Text appears progressively. Markdown renders on completion. Adequate streaming feel. |

#### Memory/CPU Efficiency (5/6/7/3/8/7)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 5 | PHP runtime + Symfony TUI framework + Revolt event loop. Full widget tree re-rendered on each frame (differential output helps, but widget computation is still O(widgets)). Markdown rendering on every chunk is expensive. The `activeResponse->setText()` pattern accumulates growing strings. PHP's memory model is less efficient than Go or compiled languages. No virtual scrolling (all messages in memory). |
| **Claude Code** | 6 | Node.js runtime with Ink. Reasonable memory usage for a terminal app. Occasional memory leaks reported on long sessions. Better than KosmoKrator due to V8's JIT and Ink's reconciliation. |
| **Aider** | 7 | Python with `rich` — no full-screen TUI overhead. Lower memory footprint. Scrollback handled by the terminal emulator. |
| **Cursor** | 3 | Electron = Chromium = 500MB+ baseline. Heavy memory and CPU usage. The cost of visual polish. |
| **Codex CLI** | 8 | Minimal UI = minimal overhead. Node.js but barely any rendering. Lightweight. |
| **OpenCode** | 7 | Go binary + Bubble Tea. Compiled, low memory footprint. Efficient rendering model. |

#### Accessibility (2/4/3/7/2/3)

| Tool | Score | Rationale |
|---|---|---|
| **KosmoKrator** | 2 | No screen reader support, no high-contrast mode, no semantic annotations, no keyboard-only navigation aids. The celestial Unicode icons (☿♀♁♄♆) are completely inaccessible to screen readers. Color-only differentiation for tool status. No alternative text for visual elements. |
| **Claude Code** | 4 | Terminal-based, so inherits some terminal accessibility. Outputs plain text that screen readers can access. But no explicit ARIA/semantic markup, no accessibility mode toggle. |
| **Aider** | 3 | Standard terminal output is partially screen-reader accessible. No explicit accessibility features. |
| **Cursor** | 7 | VS Code has extensive accessibility: screen reader support, high contrast themes, keyboard navigation, ARIA labels, accessible terminal. Inherits all of VS Code's accessibility infrastructure. |
| **Codex CLI** | 2 | No accessibility features. Plain terminal output. |
| **OpenCode** | 3 | Bubble Tea has basic screen reader support in some configurations. No explicit accessibility features in OpenCode itself. |

---

## 3. Competitive Analysis

### 3.1 Top 3 Things Each Competitor Does Better Than KosmoKrator

#### Claude Code
1. **Streaming fluidity** — Character-by-character rendering with progressive markdown. Feels alive. KosmoKrator's chunk-by-chunk approach feels sluggish by comparison.
2. **Simplicity and focus** — Minimal UI chrome means less visual noise, faster comprehension, and fewer distractions. KosmoKrator's rich theming sometimes overwhelms.
3. **Compact mode** — Can reduce output density on demand, letting users choose between verbose and minimal. KosmoKrator has no density toggle.

#### Aider
1. **Input history** — Up/down arrow browses previous prompts via `prompt_toolkit`. KosmoKrator has no input history at all.
2. **Architecture/editor split** — Separate modes for planning vs. executing, with distinct model usage. KosmoKrator's modes exist but don't visually differentiate the experience as clearly.
3. **Lightweight footprint** — No full-screen TUI framework overhead. Starts instantly, low memory. KosmoKrator's Symfony TUI adds significant startup time and memory cost.

#### Cursor
1. **Inline diff visualization** — Accept/reject changes directly in the editor with word-level highlighting. KosmoKrator's diff display is conversation-bound, not file-bound.
2. **Discoverability** — Command palette, tooltips, first-run walkthrough, persistent keyboard hints. KosmoKrator requires memorization or autocomplete discovery.
3. **Multi-file context** — Tabs, split editors, file tree all visible simultaneously. KosmoKrator shows one context at a time in a linear conversation.

#### Codex CLI
1. **Simplicity** — Zero learning curve. Type a prompt, get a result. KosmoKrator's rich UI paradoxically creates more cognitive load for simple tasks.
2. **Lightweight startup** — Near-instant launch. No splash screen, no animation. KosmoKrator's 5–8 second intro animation delays productivity.
3. **Sandboxed execution** — Clear security model with explicit sandboxing. KosmoKrator's permission system is more complex but not more clearly communicated.

#### OpenCode
1. **Split-pane file preview** — Shows the file being edited alongside the conversation. KosmoKrator shows diffs inline but has no persistent file preview panel.
2. **Consistent TUI execution** — Lip Gloss styling is applied uniformly. KosmoKrator has polish inconsistencies between widgets (e.g., permission prompts are polished, error messages are raw).
3. **Go binary performance** — Single compiled binary, fast startup, low memory. KosmoKrator's PHP + Composer stack is heavier.

### 3.2 Top 3 Things KosmoKrator Does Better

1. **Tool call visualization** — No competitor comes close. Celestial icons, discovery batching, collapsible results with preview lines, execution timers, Lua syntax highlighting, diff rendering with word-level changes, auto-expand on failure, subagent tree. This is KosmoKrator's crown jewel.

2. **Subagent/swarm visualization** — The `SwarmDashboardWidget` and live subagent tree with status icons, elapsed times, and box-drawing connectors is unique. No other terminal agent visualizes parallel agent execution. Claude Code shows subagent output linearly; others don't support subagents at all.

3. **Thematic cohesion and personality** — The celestial/mythological theme is consistent across tool icons, thinking phrases ("Consulting the Oracle at Delphi..."), splash screen, and even spinner sets ('cosmos', 'planets', 'eclipse'). This creates a memorable brand identity that no competitor has. Every other tool is visually generic.

### 3.3 Top 5 Features to Steal

#### 1. Claude Code's Streaming Architecture
**What**: Character-by-character streaming with incremental markdown rendering.
**How to steal**: Replace `setText()` accumulation with an append-only buffer. Render markdown incrementally — parse only new content, merge with previous parse tree. Consider a dedicated streaming markdown widget that handles partial input without full re-renders.
**Impact**: Would transform KosmoKrator's biggest UX weakness (chunky streaming) into a strength.

#### 2. Aider's Input History
**What**: Up/down arrow browses previously submitted prompts.
**How to steal**: Maintain a ring buffer of last N prompts in `TuiInputHandler`. Intercept Up/Down keys when input is empty to cycle through history. Store in session state, optionally persist across sessions.
**Impact**: High-frequency users re-send similar prompts constantly. History is a basic expectation.

#### 3. OpenCode's Split-Pane File Preview
**What**: Persistent file preview panel alongside conversation.
**How to steal**: Add a toggleable right panel (Ctrl+P or similar) that renders the current file context with syntax highlighting. Update when file_edit/file_write operations occur. Could reuse the existing `MarkdownWidget`/syntax highlighting infrastructure.
**Impact**: File edits become spatially comprehensible rather than linearly buried in conversation.

#### 4. Cursor's Command Palette
**What**: Fuzzy-searchable command palette triggered by a keyboard shortcut.
**How to steal**: Build on the existing `SelectListWidget` autocomplete overlay. Add Ctrl+K (or similar) trigger that searches across all slash commands, power commands, settings, and recent actions. Show keyboard hints next to each result.
**Impact**: Eliminates discoverability gap. Users find features without memorizing commands.

#### 5. Claude Code's Compact/Verbose Toggle
**What**: User-controllable output density (compact vs. verbose mode).
**How to steal**: Add a `Ctrl+V` toggle or `/compact` slash command that switches between current display and a minimal mode (hide tool call results, show only summaries, suppress discovery batches). Persist preference.
**Impact**: Different tasks need different density levels. One-size-fits-all is suboptimal.

### 3.4 Unique Advantages to Maintain

| Advantage | Why It Matters | Risk of Loss |
|---|---|---|
| **Discovery batching** | Automatically grouping read-only tool calls (file_read, glob, grep) into a single collapsible summary is unique. It dramatically reduces conversation noise during exploration phases. No competitor does this. | Low — architecturally unique to KosmoKrator. |
| **Celestial theme system** | The astronomical icon set (☿♀♁♂♃♄♆), mythological thinking phrases, and cosmic spinner sets create a brand identity. Users remember KosmoKrator. Generic is forgettable. | Medium — could be diluted if theming is deprioritized for "cleanliness". |
| **Permission preview builder** | Structured tool approval with scope inference, diff previews for file_edit, and file lists for patches. More informative than any competitor's permission prompt. | Low — deeply integrated into the tool execution pipeline. |
| **Settings workspace** | Full-screen two-column settings editor within the TUI with category navigation, value pickers, model browser, and auth status. No terminal competitor has an in-TUI settings experience this rich. | Low — significant investment already made. |
| **Phase-based color animation** | Blue for thinking, amber for tool execution, red for compaction. Color communicates agent state at a glance without reading text. | Medium — could be removed in a "simplify the UI" push. |
| **Dual renderer architecture** | TUI + ANSI fallback means KosmoKrator works in any terminal, from full kitty to basic SSH sessions. Competitors that require full TUI support break in restricted environments. | Low — architectural decision, unlikely to change. |
| **Task bar with breathing colors** | Persistent task tree visualization with status-aware color animation. Provides ambient awareness of agent progress without requiring user attention. | Medium — could be simplified or removed. |

---

## 4. Strategic Recommendations

### 4.1 Immediate Priorities (Close the Gap)

1. **Fix streaming** — This is the single highest-impact UX improvement. KosmoKrator's tool display is best-in-class, but the streaming feel is noticeably worse than Claude Code. Append-only buffer + incremental markdown parsing.

2. **Add input history** — Basic feature, easy to implement, high user expectation. Ring buffer + Up/Down navigation.

3. **Add a `?` help overlay** — One-keypress overlay showing all keybindings. OpenCode does this. Costs one weekend to build, massively improves discoverability.

### 4.2 Medium-Term Investments (Build Advantage)

4. **File preview panel** — OpenCode's split-pane is the right idea, but KosmoKrator can do it better with its existing syntax highlighting and diff rendering infrastructure.

5. **Compact/verbose toggle** — Let users control density. Discovery batching is great for verbose mode; compact mode should show only summaries.

### 4.3 Long-Term Differentiators (Protect)

6. **Invest in accessibility** — Currently the worst score (2/10). Even basic improvements (screen reader announcements, high-contrast mode, semantic labels) would move the needle significantly and demonstrate maturity.

7. **Maintain thematic identity** — As the UI evolves, resist the urge to become generic. The celestial theme is a competitive advantage, not a liability.

---

## 5. Appendix: Rating Distribution

```
                KosmoKrator  Claude Code  Aider  Cursor  Codex CLI  OpenCode
Visual Polish       ███████     ███████   █████  █████████   ███       ███████
Responsive          ███████     ████████  ███████ █████████   ███████   ███████
Info Density        ████████    ███████   ███████ █████████   ████      ██████
Discoverability     █████       ██████    █████   █████████   ███       █████
Error Handling      ██████      ███████   ██████  ████████    ████      █████
Input Experience    ███████     ███████   ███████ █████████   █████     ██████
Tool Call Display   █████████   ███████   ██████  ████████    ███       ██████
Streaming           ██████      █████████ ███████ █████████   ██████    ███████
Efficiency          █████       ██████    ███████ ███         ████████  ███████
Accessibility       ██          ████      ███     ████████    ██        ███
```

---

## 6. Methodology Notes

- **KosmoKrator** scores are based on direct code analysis of the TUI implementation (see: `TuiCoreRenderer.php`, `TuiToolRenderer.php`, `TuiAnimationManager.php`, `KosmokratorStyleSheet.php`, `Theme.php`, and all widgets in `src/UI/Tui/Widget/`).
- **Claude Code** scores are based on public documentation, GitHub repository, user reports, and observed behavior.
- **Aider** scores are based on public GitHub repository analysis and community documentation.
- **Cursor** scores are based on public documentation, user reports, and product analysis.
- **Codex CLI** scores are based on public GitHub repository and documentation.
- **OpenCode** scores are based on public GitHub repository analysis and community documentation.
- All scores represent the analyst's assessment as of April 2026. Competitors are actively developed; scores will shift.
- Cursor is included as a benchmark despite being a GUI application (not a direct competitor in the terminal space). Its scores should be interpreted as "the ceiling for what's possible" rather than a fair head-to-head comparison.

# UX Audit: First-Run & Onboarding Experience

> **Research Question**: What is the first-run experience of KosmoKrator's TUI, and how can it be improved to match world-class TUIs?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `AnsiIntro.php`, `TuiCoreRenderer.php`, `TuiInputHandler.php`, `AgentSessionBuilder.php`, `AgentCommand.php`, `HistoryStatusWidget.php`, `KosmokratorStyleSheet.php`

---

## Executive Summary

KosmoKrator's first-run experience is **visually spectacular but functionally hollow**. The animated intro sequence (`AnsiIntro::animate()`) is a multi-phase cosmic spectacle — starfield, logo reveal, orrery, zodiac ring — lasting 5–8 seconds. After the animation clears, the user lands in the TUI with an ASCII orrery and a slash-command cheat sheet, then an empty prompt and a status bar reading `Edit · Guardian ◈ · Ready`.

There is **no first-run detection**, **no guided setup**, **no keyboard shortcut hints**, and **no contextual help**. The experience is identical for a brand-new user and a veteran on their hundredth session. Compared to lazygit, Helix, and Claude Code, KosmoKrator's onboarding is the weakest — all three competitors provide progressive disclosure, keybinding visibility, and task-oriented first steps.

**Severity**: High. First impressions directly affect adoption. A user who can't figure out how to use the tool in 30 seconds will not become a user.

---

## 1. Current First-Run Flow

### 1.1 Sequence of Events

What the user sees, in order, when they run `kosmokrator` for the first time:

```
Phase 1: ANSI Animation (5–8 seconds, skippable via keypress)
┌──────────────────────────────────────────────────────────────────────────────┐
│  ⟡ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ ⟡  │
│  ┃                                                                            ┃  │
│  ┃  ██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗  ┃  │
│  ┃  ██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗ ┃  │
│  ┃  █████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║ ┃  │
│  ┃  ██╔═██╗ ██║   ██║╚════██║██║╚██╔╝██║██║   ██║██╔═██╗ ██╔══██╗██╔══██║ ┃  │
│  ┃  ██║  ██╗╚██████╔╝███████║██║ ╚═╝ ██║╚██████╔╝██║  ██╗██║  ██║██║  ██║ ┃  │
│  ┃  ╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝ ┃  │
│  ┃                                                                            ┃  │
│  └──────────────────────────────────────────────────────────────────────────────┘
│             ⚡ Κοσμοκράτωρ — Ruler of the Cosmos ⚡
│               ☿  ♀  ♁  ♂  ♃  ♄  ♅  ♆  ✦  ☽  ☉  ★  ✧  ⊛  ◈
│                   Your AI coding agent by OpenCompany
│            ♈  ♉  ♊  ♋  ♌  ♍  ♎  ♏  ♐  ♑  ♒  ♓
│                       (animated orrery + zodiac ring)
└──────────────────────────────────────────────────────────────────────────────────
↓ Screen clears. TUI starts.
↓ RenderIntro adds conversation widgets:
```

```
Phase 2: TUI Welcome Screen (static, inside TUI)
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  ⚡ KosmoKrator — Ruler of the Cosmos ⚡                                     │
│                                                                              │
│              ·  ·  ·  ♅  ·  ·  ·                                            │
│          ·        · ♁ ·        ·                                             │
│       ·     ·    ·☿·    ·     ·                                              │
│     ♄   ·         ☉         ·   ♃                                            │
│       ·     ·    ·♀·    ·     ·                                              │
│          ·        · ♂ ·        ·                                             │
│              ·  ·  ·  ♆  ·  ·  ·                                            │
│                                                                              │
│  Quick Reference                                                             │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ │
│  /edit  /plan  /ask               Agent mode (write / read-only / Q&A)       │
│  /guardian  /argus  /prometheus    Permission mode (smart / strict / auto)   │
│  /compact  /new  /resume  /tasks clear  Context and session management       │
│  /settings  /memories  /sessions  /agents  Configuration and monitoring      │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ │
│                                                                              │
│  ─────────────────────────────────────────────────────────────────────────── │
│                                                                              │
│  ▏  ← cursor here, blinking                                                  │
│  ─────────────────────────────────────────────────────────────────────────── │
│  Edit · Guardian ◈ · Ready                                                   │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Key Code Paths

| Step | File | Method | What happens |
|------|------|--------|-------------|
| 1 | `AgentCommand.php:56` | `execute()` | Reads `--no-animation` flag; decides `animated=true/false` |
| 2 | `AgentSessionBuilder.php:52` | `build()` | Calls `$ui->renderIntro($animated)` |
| 3 | `TuiCoreRenderer.php:271` | `renderIntro()` | Creates `AnsiIntro`, runs animated or static, clears screen |
| 4 | `TuiCoreRenderer.php:337–349` | `renderIntro()` | Adds TextWidgets: header, orrery, tutorial (slash commands) |
| 5 | `TuiCoreRenderer.php:600` | `showWelcome()` | **No-op** — comment says "Already handled in renderIntro" |
| 6 | `AgentSessionBuilder.php:53` | `build()` | Calls `$ui->showWelcome()` — which does nothing |
| 7 | `AgentCommand.php:90` | `execute()` | Creates new session, calls `repl()` |
| 8 | `TuiCoreRenderer.php:352` | `prompt()` | Focuses input, suspends event loop, waits for user input |

### 1.3 What's Missing Entirely

- **No first-run detection**: No check for `~/.kosmokrator/config.yaml`, no "is this your first time?" flag
- **No guided setup wizard**: Provider and API key setup is left to the separate `kosmokrator setup` CLI command, which is only mentioned in an error message if the build fails
- **No keyboard shortcut cheatsheet**: Only slash commands are shown; keyboard shortcuts (Shift+Tab, PgUp/PgDn, Ctrl+L, Escape, Ctrl+A) are undocumented
- **No progressive disclosure**: The same dense command reference is shown to every user, every session
- **No interactive tutorial**: No way to practice using the tool
- **No contextual hints**: The status bar shows mode and permission labels, but doesn't explain what they mean

---

## 2. Competitive Analysis

### 2.1 Lazygit — Keybinding-First Design

**First-run experience**:
- Opens directly to the status panel (files changed)
- Bottom bar shows contextual keybindings: `↑↓ navigate`, `space stage`, `c commit`, `P push`
- Keybindings change based on context (different in diff view, stash view, etc.)
- Press `?` anywhere to see full keybinding list
- Zero animation, zero splash screen

**What it does well**:
- **Immediate utility**: User sees their git state instantly
- **Discoverable**: Every action has a visible keybinding
- **Progressive**: Most common actions visible, `?` for the rest

### 2.2 Helix — Tutorial Mode

**First-run experience**:
- Opens a welcome screen with three options:
  - `Open a file` (recent files listed)
  - `:tutor` — interactive tutorial teaching movement, selection, multi-cursor
  - `:theme <name>` — change theme
- The tutorial is a real document that you edit and navigate through
- Keybinding hints shown at bottom: `hjkl move`, `: commands`, `space leader`

**What it does well**:
- **Onboarding path**: Explicit "learn the tool" option
- **Hands-on**: Tutorial is interactive, not just text
- **Multiple entry points**: Users can skip the tutorial and open a file

### 2.3 Claude Code — Clean & Action-Oriented

**First-run experience**:
- Minimal branding: just `claude` in dim text
- Immediately shows the prompt with a one-line hint: "Describe what you want to do"
- Status line shows model name and cost tracking
- Slash commands available via `/` autocompletion
- No animation, no logo, no splash

**What it does well**:
- **Zero friction**: Type and go
- **Minimalist**: No visual noise
- **Action-oriented**: The tool gets out of the way so the user can work

### 2.4 Comparison Matrix

| Feature | KosmoKrator | Lazygit | Helix | Claude Code |
|---------|-------------|---------|-------|-------------|
| Splash animation | ✦✦✦✦✦ (8s) | None | None | None |
| Keybinding hints | ✗ | ✦✦✦✦✦ | ✦✦✦✦ | ✦✦ |
| First-run detection | ✗ | N/A | ✦✦✦ | ✗ |
| Interactive tutorial | ✗ | ✗ | ✦✦✦✦✦ | ✗ |
| Contextual help | ✗ | ✦✦✦✦✦ | ✦✦✦ | ✦✦ |
| Slash command discovery | ✦✦✦ | N/A | ✦✦ | ✦✦✦ |
| Guided setup | ✗ | N/A | N/A | ✦✦✦ |
| Time to first action | ~8s | <1s | <2s | <1s |

---

## 3. Pain Points & Issues

### P1: Animation is a wall, not a bridge

**Severity**: Critical  
**Evidence**: `AnsiIntro::animate()` runs 7 sequential phases with cumulative waits of 5–8 seconds. The starfield alone renders 40–150 stars at 4ms intervals.

The animation is visually impressive but:
- **Blocks interaction**: User can't do anything until it finishes or presses a key
- **No value transfer**: The animation teaches nothing about how to use the tool
- **Repeated every session**: There's no "skip for future sessions" option
- **Disorienting transition**: After the animation, the screen clears completely. The cosmic visuals are destroyed and replaced with a text widget that looks nothing like what came before.

The `KOSMOKRATOR_NO_ANIM` env var exists but is undocumented. The `--no-animation` flag is not discoverable.

### P2: Slash command reference is information overload

**Severity**: High  
**Evidence**: `TuiCoreRenderer.php:326–335` — the Quick Reference shows 16 slash commands in 4 groups, all at once.

For a first-time user, this is overwhelming. They don't yet know what "edit mode" vs "plan mode" means, or what "Guardian" vs "Argus" vs "Prometheus" refers to. The command list assumes prior knowledge of the permission model and agent architecture.

### P3: No keyboard shortcut visibility

**Severity**: High  
**Evidence**: `TuiInputHandler.php` binds multiple keybindings (`Shift+Tab`, `Ctrl+L`, `Ctrl+A`, `PgUp/PgDn`, `Escape`) but none are visible to the user.

The only keyboard hint is `PgUp/PgDn scroll  End latest` in `HistoryStatusWidget.php:63`, which only appears after the user has already scrolled into history. Key bindings for mode cycling (`Shift+Tab`), force refresh (`Ctrl+L`), and the agents dashboard (`Ctrl+A`) are completely hidden.

### P4: No contextual help or `?` keybinding

**Severity**: High  
**Evidence**: There is no `?` handler in `TuiInputHandler.php`. No help overlay exists.

World-class TUIs universally bind `?` to contextual help. Lazygit, Helix, lazydocker, htop — all do this. KosmoKrator has no equivalent. The user's only recourse is to type `/` and browse the autocomplete, or consult external documentation.

### P5: Status bar is cryptic

**Severity**: Medium  
**Evidence**: `TuiCoreRenderer.php:774–778` — status bar shows `Edit · Guardian ◈ · Ready`.

For a first-time user:
- What does "Edit" mean? Is it an editor? Can I edit files?
- What is "Guardian ◈"? What does the diamond mean? Why not just "smart"?
- "Ready" for what?

The status bar uses internal terminology without explanation.

### P6: showWelcome() is a no-op

**Severity**: Low (design smell)  
**Evidence**: `TuiCoreRenderer.php:600–602` — `showWelcome()` contains only a comment: "Already handled in renderIntro".

This is a missed architectural hook. `showWelcome()` was designed to be the place for contextual welcome messages (different for first-run vs returning user), but it's unused because `renderIntro()` absorbed all welcome content.

---

## 4. Recommendations

### R1: First-Run Detection & Adaptive Welcome

**Priority**: P0  
**Effort**: Small

Detect whether this is the user's first session by checking if `~/.kosmokrator/config.yaml` exists and has a provider configured.

```php
// In TuiCoreRenderer::renderIntro() or showWelcome()
private function isFirstRun(): bool
{
    $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
    return ! file_exists($home . '/.kosmokrator/config.yaml');
}
```

**On first run**: Show a simplified welcome with a guided first step.  
**On returning run**: Show the orrery + quick reference (current behavior).  
**On nth run (5+)**: Skip the intro entirely, go straight to prompt.

### R2: Replace Animation with Fast, Meaningful Intro

**Priority**: P0  
**Effort**: Medium

The current animation should become opt-in (`--animation` flag), not opt-out. The default first-run should be:

1. Static logo + tagline (1 second max)
2. Contextual content (see R3)
3. Prompt ready

Mockup — **proposed first-run screen**:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│    ██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗    │
│    ██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗   │
│    █████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║   │
│    ╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝  │
│                                                                              │
│         ⚡ Your AI coding agent. Describe what you want to build. ⚡         │
│                                                                              │
│    ┌─ Getting Started ──────────────────────────────────────────────────┐    │
│    │                                                                    │    │
│    │  Welcome! Here's how to get going:                                 │    │
│    │                                                                    │    │
│    │  1. Describe a task      Just type what you want done              │    │
│    │  2. Review & approve     Agent asks before risky actions           │    │
│    │  3. Use / to see commands  /edit  /plan  /ask  /settings          │    │
│    │                                                                    │    │
│    │  Keys:  Shift+Tab cycle mode   Ctrl+L refresh   ? help            │    │
│    │                                                                    │    │
│    └────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
│  ▏  ← type your task here                                                   │
│  ─────────────────────────────────────────────────────────────────────────── │
│  Edit · Guardian ◈ · Ready                                                  │
└──────────────────────────────────────────────────────────────────────────────┘
```

### R3: Progressive Slash Command Hints

**Priority**: P1  
**Effort**: Small

Instead of showing all 16+ slash commands at once, show only the 3 most important on first run, and make the rest discoverable via `/` autocompletion (which already works well — see `TuiInputHandler.php:301–319`).

**First-run**: Show 3 commands: `/edit  /plan  /ask  — type / for more`  
**Returning**: Show the full Quick Reference (current behavior)

### R4: Add `?` Contextual Help Overlay

**Priority**: P1  
**Effort**: Medium

Bind `?` (when input is empty) to a modal help overlay showing:

```
┌─ Keyboard Shortcuts ─────────────────────────────────────────────────────┐
│                                                                          │
│  Enter          Send message                                            │
│  Shift+Enter    New line (multiline input)                              │
│  Shift+Tab      Cycle mode (Edit → Plan → Ask)                          │
│  Escape         Cancel / Quit                                           │
│  Ctrl+L         Force screen refresh                                    │
│  Ctrl+A         Agent dashboard                                        │
│  PgUp / PgDn    Scroll conversation history                            │
│  End            Jump to latest output                                   │
│  Tab            Accept autocomplete suggestion                          │
│                                                                          │
│  Slash Commands (type / to browse):                                     │
│  /edit /plan /ask              Agent mode                               │
│  /guardian /argus /prometheus  Permission mode                          │
│  /settings                     Configuration                            │
│  /compact /new                 Session management                       │
│                                                                          │
│           Press any key to close                                        │
└──────────────────────────────────────────────────────────────────────────┘
```

Implementation: Add a new keybinding match in `TuiInputHandler::handleInput()` that calls a `TuiModalManager::showHelpOverlay()` method. The overlay can reuse the existing modal infrastructure.

### R5: Contextual Status Bar Tooltips

**Priority**: P2  
**Effort**: Small

When the user hovers (or the status bar is first shown), add a one-line explanation:

```
Edit · Guardian ◈ · Ready
  ↑ mode      ↑ permission    ↑ state
  write files  smart approve   awaiting input
```

This could be a dim line below the status bar that appears for the first 3 sessions, then auto-hides.

### R6: Interactive `/tutor` Command

**Priority**: P2  
**Effort**: Large

Following Helix's example, add a `/tutor` slash command that starts a guided walkthrough:

1. **Basic**: Type a task → see the agent work → approve a tool call
2. **Modes**: Try `/plan` mode → see read-only behavior → switch back
3. **Context**: Use `/compact` → see context management
4. **Advanced**: Spawn a subagent → use `/agents` dashboard

This would use the existing slash command infrastructure (`SlashCommandContext`) and could be implemented as a series of pre-scripted agent interactions.

### R7: Make Animation Opt-In for Returning Users

**Priority**: P1  
**Effort**: Small

After the first animated intro, write a flag to `~/.kosmokrator/.intro_seen`. On subsequent launches, skip the animation by default (show the static intro for 0.5s, then TUI). Users who love the animation can set `kosmokrator.ui.intro_animated: true` in their config.

```php
// In AgentCommand::execute()
$introSeen = file_exists($home . '/.kosmokrator/.intro_seen');
$animated = ! $introSeen && ! $input->getOption('no-animation') 
    && $config->get('kosmokrator.ui.intro_animated', true);
```

### R8: Status Bar Redesign for Clarity

**Priority**: P2  
**Effort**: Small

Replace internal jargon with user-facing language:

| Current | Proposed |
|---------|----------|
| `Edit · Guardian ◈ · Ready` | `✏️ Editing · Auto-approve safe · Ready` |
| `Plan · Argus ◈ · Thinking...` | `👁️ Read-only · Ask every time · Thinking...` |
| `Ask · Prometheus ◈ · Ready` | `💬 Q&A · Auto-approve all · Ready` |

The Unicode icons add quick visual differentiation. The text uses plain language. The diamond `◈` symbol is meaningful internally but opaque to users.

---

## 5. Proposed First-Run Flow (Full Sequence)

### 5.1 Brand-New User (no config)

```
Step 1: Static logo (0.5s)
Step 2: Setup check → no config → inline prompt:
        "No provider configured. Run /setup or type 'setup' to get started."
Step 3: User types task or /setup
Step 4: If /setup → guided provider configuration (reuse SettingsWorkspaceWidget)
Step 5: After setup → welcome message + 3 key commands + prompt
```

Mockup — **first-run with no config**:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│         ⚡ KosmoKrator — Your AI Coding Agent                               │
│                                                                              │
│    ┌────────────────────────────────────────────────────────────────────┐    │
│    │  Welcome to KosmoKrator!                                          │    │
│    │                                                                    │    │
│    │  To get started, you need to configure an AI provider.            │    │
│    │                                                                    │    │
│    │  Type /settings and press Enter, or just describe what you'd      │    │
│    │  like to build and KosmoKrator will guide you through setup.      │    │
│    │                                                                    │    │
│    └────────────────────────────────────────────────────────────────────┘    │
│                                                                              │
│  ▏                                                                            │
│  ─────────────────────────────────────────────────────────────────────────── │
│  Edit · Not configured · Type /settings to begin                             │
└──────────────────────────────────────────────────────────────────────────────┘
```

### 5.2 First Session (config exists, first session)

```
Step 1: Static logo + orrery (0.5s, no animation)
Step 2: "Getting Started" box with 3-step explanation
Step 3: Keybinding footer line
Step 4: Prompt ready (total time: <2s)
```

### 5.3 Returning User (5+ sessions)

```
Step 1: No intro — go straight to TUI
Step 2: Minimal header: "⚡ KosmoKrator" in conversation top
Step 3: Prompt ready (total time: <0.5s)
```

---

## 6. Implementation Priority

| # | Recommendation | Priority | Effort | Impact |
|---|---------------|----------|--------|--------|
| R1 | First-run detection | P0 | S | High — enables all other adaptive features |
| R2 | Fast intro by default | P0 | M | High — removes 8-second barrier |
| R3 | Progressive command hints | P1 | S | Medium — reduces information overload |
| R4 | `?` Help overlay | P1 | M | High — addresses P4, makes all shortcuts discoverable |
| R7 | Animation opt-in after first run | P1 | S | Medium — removes repeated friction |
| R5 | Status bar tooltips | P2 | S | Low — nice-to-have |
| R8 | Status bar plain language | P2 | S | Medium — improves comprehension |
| R6 | Interactive `/tutor` | P2 | L | High — but expensive to build |

---

## 7. Architectural Notes

### 7.1 Where to add first-run logic

The `showWelcome()` method (`TuiCoreRenderer.php:600`) is currently a no-op. It was designed for exactly this purpose. It's called after `renderIntro()` in `AgentSessionBuilder.php:53`, which is the perfect place to inject adaptive content.

**Proposed change**: Move the orrery + Quick Reference from `renderIntro()` into `showWelcome()`, and make `showWelcome()` behavior conditional on first-run state.

### 7.2 Help overlay infrastructure

The `TuiModalManager` (`src/UI/Tui/TuiModalManager.php`) already manages overlays for permission prompts, questions, and plan approvals. A help overlay can reuse this infrastructure — it's just another overlay with a dismiss-on-any-key handler.

### 7.3 Slash command system

The slash command completion system in `TuiInputHandler.php:301–319` is well-designed. Adding `/tutor` or `/help` commands would follow the existing pattern in `SlashCommandContext`.

---

## 8. Summary of Findings

| Finding | Severity | Root Cause |
|---------|----------|-----------|
| 8-second animation blocks first interaction | Critical | `AnsiIntro::animate()` phases are sequential with cumulative delays |
| No first-run vs returning-user distinction | Critical | No config/session count check exists |
| All slash commands shown at once (16+ commands) | High | `renderIntro()` dumps the full Quick Reference unconditionally |
| Zero keyboard shortcut visibility | High | `TuiInputHandler` binds shortcuts but never displays them |
| No `?` help keybinding | High | No handler for `?` in input system |
| Status bar uses internal jargon | Medium | `Guardian ◈`, `Edit`, `Ready` are meaningful to devs, not users |
| `showWelcome()` is a dead hook | Low | Absorbed by `renderIntro()` |
| `KOSMOKRATOR_NO_ANIM` is undocumented | Low | Env var exists but no `--help` text or docs |

The core insight is that KosmoKrator's onboarding was designed to **impress**, not to **teach**. The cosmic theme and animation create a strong brand impression, but they don't help a new user accomplish their first task. World-class TUIs prioritize **time to first action** above all else.

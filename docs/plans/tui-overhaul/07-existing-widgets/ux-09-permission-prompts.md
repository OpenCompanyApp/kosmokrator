# UX Audit: Permission Prompts

**Date:** 2026-04-07  
**Auditor:** UX Research  
**Research question:** *How good is the permission prompt UX in KosmoKrator's TUI?*  
**Files reviewed:**
- `src/UI/Tui/Widget/PermissionPromptWidget.php` — interactive permission dialog widget
- `src/UI/Tui/TuiModalManager.php` — `askToolPermission()` modal lifecycle
- `src/UI/Tui/PermissionPreviewBuilder.php` — preview content builder (title, summary, sections)
- `src/Tool/Permission/PermissionMode.php` — Guardian / Argus / Prometheus mode enum
- `src/Tool/Permission/PermissionAction.php` — Allow / Ask / Deny tri-state
- `src/Tool/Permission/PermissionEvaluator.php` — evaluation chain (blocked → deny → grants → boundary → rules → mode override)
- `src/Tool/Permission/GuardianEvaluator.php` — static heuristic auto-approve engine
- `src/Tool/Permission/SessionGrants.php` — per-tool session approval tracking
- `src/Tool/Permission/Check/ModeOverrideCheck.php` — mode-based override for Ask results
- `src/Tool/Permission/PermissionConfigParser.php` — config → rules pipeline

---

## Executive Summary

KosmoKrator's permission prompt is **functionally complete but cognitively overloading**. The system presents a rich preview (command, file path, diff, scope) and five approval options — but the options conflate *two distinct decision axes* (one-time vs. session-wide approval, and mode switching) into a single flat list. New users will struggle to distinguish "Always allow" from "Guardian ◈" and "Prometheus ⚡". The preview layer is well-built; the decision layer needs restructuring.

**Overall grade: B−** — solid preview infrastructure undermined by an option architecture that asks too much, too fast, without progressive disclosure.

---

## 2. What Action Is Being Requested?

### Current behavior

The `PermissionPreviewBuilder` constructs a structured preview for each tool type:

| Tool | Title | Sections |
|------|-------|----------|
| `bash` | "Invocation Request" | Command, Scope, Expected result |
| `file_write` | "Edit Approval" | File, Scope, Preview (content) |
| `file_edit` | "Edit Approval" | File, Scope, Preview (diff: red −/green +) |
| `apply_patch` | "Edit Approval" | Files (up to 4), Preview (diff) |
| `shell_start` | "Invocation Request" | Command, Scope |
| `file_read` | "Invocation Request" | File, Scope |

The title distinguishes between "Invocation Request" (reads/commands) and "Edit Approval" (writes). The tool icon from `Theme::toolIcon()` adds visual identity.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **No risk level indicator** | 🔴 High | A `rm -rf` command and `cat README.md` get identical "Invocation Request" titles. Users must *read* the Command section to judge danger. macOS shows 🔴/🟡/🟢 badges. |
| **Binary title taxonomy** | 🟡 Medium | Only two titles: "Invocation Request" and "Edit Approval". A destructive command (`git push --force`) and a read-only query (`git log`) share the same title. |
| **No agent context** | 🟡 Medium | The prompt doesn't explain *why* the agent wants to run this tool. Claude Code shows the agent's reasoning ("I need to run tests to verify my changes"). iOS requires a usage description string before prompting. |
| **"Expected result" is guesswork** | 🟡 Medium | `expectedResultForBash()` uses regex heuristics. For unknown commands it falls back to "runs the requested shell command and prints the result" — which is a tautology. |
| **No path context for bash** | 🟢 Low | The Scope section says "shell access in the current workspace" but doesn't show which directory the command runs in or whether it's inside the project boundary. |

### Comparison: macOS permission prompts

macOS permission dialogs follow a strict formula:
1. **Who** — the app icon + name
2. **What** — a single, clear verb ("wants to access your Camera")
3. **Why** — (optional) usage description set by the developer
4. **Action** — exactly 2 buttons (Don't Allow / OK)

KosmoKrator is missing **Who** (which subagent?) and **Why** (reasoning), and offers **5** actions instead of 2.

### Comparison: iOS permission patterns

iOS enforces `Info.plist` usage strings. Apps *must* explain why before the OS shows the prompt. iOS also shows prompts **at the moment of use**, not in bulk. KosmoKrator already does the right thing here (prompt at call time), but misses the explanation.

---

## 3. Are the 5 Options Understandable?

### Current options

```
OPTIONS = [
    ['value' => 'allow',     'label' => 'Allow once',      'description' => 'Execute this tool call'],
    ['value' => 'always',    'label' => 'Always allow',     'description' => 'Allow this tool for the current session'],
    ['value' => 'guardian',  'label' => 'Guardian ◈',       'description' => 'Switch to smart auto-approve'],
    ['value' => 'prometheus','label' => 'Prometheus ⚡',     'description' => 'Switch to auto-approve all'],
    ['value' => 'deny',      'label' => 'Deny',             'description' => 'Block this tool call'],
]
```

### Analysis

The five options conflate **three different decision types**:

1. **Scope of approval** — one-time (`allow`) vs. session-wide (`always`)
2. **Mode change** — switch to Guardian or Prometheus mode
3. **Rejection** — deny this call

These are **orthogonal concerns** crammed into one list. A user thinking "yes, allow this one" must also notice that option 3 ("Guardian ◈") and option 4 ("Prometheus ⚡") are *mode switches* — not approval variants.

| Issue | Severity | Detail |
|-------|----------|--------|
| **"Always allow" is ambiguous** | 🔴 High | The description says "for the current session", but the label says "Always allow". "Always" implies permanence. Session grants (`SessionGrants`) reset on session end, but nothing in the UI communicates this. |
| **Mode options are contextually jarring** | 🔴 High | "Guardian ◈" and "Prometheus ⚡" switch the *entire session's permission mode*, not just this tool. This is a global state change buried inside a per-call prompt. |
| **No undo guidance** | 🟡 Medium | If you pick "Prometheus ⚡" by mistake, how do you go back? No hint, no "you can change this in /settings". |
| **Greek mythology names** | 🟡 Medium | "Guardian" is intuitive, but "Prometheus" requires knowing the mythos (brought fire = auto-approve). "Argus" (the third mode, missing from options) means "100-eyed watchman" — not obvious. The ◈ and ⚡ symbols help but don't fully compensate. |
| **"Allow once" is default** | 🟢 Low | Good — the safest option is pre-selected. |
| **Deny is last** | 🟢 Low | Safe position, but requires 4 arrow presses. Could benefit from `Esc` (already mapped to deny via dismiss) being more prominently documented. |

### Comparison: Claude Code's trust/allow dialogs

Claude Code offers a simpler set:
- **Allow** (this one)
- **Allow always** (session-wide for this tool)
- **Deny**

Mode switching (if available) is handled through a separate `/mode` command, not embedded in the permission prompt. This separation of concerns means the prompt stays focused on the *immediate decision*.

### Comparison: Git credential helper prompts

Git's SSH key prompt is binary: accept fingerprint (yes/no). The credential helper prompt shows the hostname and asks for username/password. No mode switches, no session grants — just the decision at hand.

---

## 4. Is the File/Command Preview Helpful?

### Current preview rendering

The `PermissionPromptWidget::render()` method produces a bordered box:

```
┌─ ⌨ Edit Approval ─────────────────────────────────────────┐
│ File Write                                                 │
│ Write file contents in the workspace                       │
│                                                            │
│ File                                                       │
│   src/UI/Theme.php                                         │
│ Scope                                                      │
│   writes workspace files                                   │
│ Preview                                                    │
│   + public static function newMethod(): string             │
│   + {                                                      │
│   +     return 'hello';                                    │
│   … (12 more lines)                                        │
│                                                            │
│ Approval                                                   │
│ › Allow once                                               │
│   Execute this tool call                                   │
│   Always allow                                             │
│   Allow this tool for the current session                  │
│   Guardian ◈                                               │
│   Switch to smart auto-approve                             │
│   Prometheus ⚡                                             │
│   Switch to auto-approve all                               │
│   Deny                                                     │
│   Block this tool call                                     │
│                                                            │
│ Enter confirm  Esc deny                                    │
└────────────────────────────────────────────────────────────┘
```

### Strengths

1. **Diff preview for edits** — `previewEdit()` shows red `−` removed and green `+` added lines, capped at 6 lines. This is genuinely useful.
2. **Patch file list** — `patchFiles()` extracts up to 4 filenames from `apply_patch`, so the user can see which files are affected before approving.
3. **Scope classification** — `bashScope()` detects filesystem writes vs. read-only operations.
4. **Word wrapping** — `wrapBlock()` handles long commands/paths.
5. **Content preview** — `previewText()` shows up to 8 non-empty lines with a "… (N more lines)" truncation indicator.

### Problems

| Issue | Severity | Detail |
|-------|----------|--------|
| **Preview height is unbounded** | 🟡 Medium | With all sections present (Command + Scope + Expected result + Approval with 5 options), the widget can exceed 24 lines. `render()` doesn't cap total height or make sections scrollable. On small terminals, the bottom options may be cut off. |
| **No syntax highlighting** | 🟡 Medium | File paths, code, and commands are rendered in the same monochrome style. The `Theme::error()` and `Theme::success()` colors are only used for diff +/− markers. |
| **"Scope" section is low-signal** | 🟡 Medium | "shell access with output returned to the session" is filler text for most bash commands. The user already knows bash produces output. |
| **No project-relative path normalization** | 🟢 Low | Paths are shown as-is. A long absolute path wastes screen space. The `Theme::relativePath()` call exists in `pathLabel()` but may not always produce a short path. |

---

## 5. Can Users Make Informed Decisions Quickly?

### Decision time analysis

For a first-time user encountering the permission prompt:

1. **Read the title** — "Invocation Request" → 1s
2. **Read the summary** — "Execute a shell command…" → 1s
3. **Read the command/file section** — the actual payload → 2–5s
4. **Read the scope** — "shell access…" → 1s (low value)
5. **Read all 5 options + descriptions** → 5–8s
6. **Decide between "Allow once" and "Always allow"** → 2–3s
7. **Ponder what "Guardian ◈" does vs. current mode** → 5–10s (uncertainty)
8. **Worry about "Prometheus ⚡" being dangerous** → 2–5s (anxiety)

**Estimated total: 19–35 seconds per prompt** for a new user. Claude Code's simpler 3-option prompt typically resolves in 5–10 seconds.

### Cognitive load breakdown

The prompt presents:
- 2 titles (tool label, dialog title)
- 1 summary line
- 2–4 section headers
- 2–10 lines of content
- 5 option labels + 5 descriptions
- 1 keyboard hint line

That's **17–27 discrete text elements** competing for attention, with no visual hierarchy beyond the `›` cursor.

### Comparison: macOS permission prompts

macOS shows:
- 1 app name
- 1 verb sentence
- 2 buttons

**3 elements.** KosmoKrator shows 17–27. The ratio is 6–9× more information density.

---

## 6. Is "Always Allow" Safe Enough?

### Current behavior

"Always allow" calls `SessionGrants::grant($toolName)` — a **per-tool, per-session** grant. Key properties:

- **Per-tool granularity:** "Always allow" for `bash` doesn't auto-approve `file_write`. ✅
- **Session-scoped:** Grants reset when the session ends. ✅
- **No argument-level scoping:** Granting `bash` approves *all* bash commands for the session, including destructive ones. ❌
- **No path restriction:** Granting `file_write` approves writes to *any* path, including outside the project. ❌
- **No confirmation step:** Unlike macOS's "Always allow" which requires a biometric confirmation, this is a single Enter press. ❌
- **No visual indicator of active grants:** After granting, there's no persistent badge showing "bash: session-approved". The user must remember. ❌

### Specific risks

1. **"Always allow bash"** — the user approves `npm test` and later the agent runs `rm -rf node_modules`. Both are `bash` tool calls. The grant is too coarse.
2. **"Always allow file_write"** — the user approves writing `src/Feature.php` and later the agent writes to `~/.bashrc`. Both are `file_write` tool calls.
3. **No revocation UI** — there's no way to see or revoke session grants without restarting the session. `PermissionEvaluator::resetGrants()` exists but isn't exposed in the TUI.

### Comparison: Claude Code

Claude Code's "Always allow for this session" has the same per-tool granularity issue, but Claude Code mitigates it by:
- Showing a **persistent status indicator** when tools are auto-approved
- Offering **command-pattern allowlists** (allow `npm test` but not `rm`)
- Placing mode switches **outside the prompt**

### Recommendations for "Always allow"

1. Add a confirmation toast: "✓ bash approved for this session (4 calls auto-approved)"
2. Show active grants in the status bar
3. Consider command-pattern grants for `bash` and path-scoped grants for `file_*`

---

## 7. Error Recovery from Wrong Permission Choice

### Current behavior

| Mistake | Recovery path | User-visible? |
|---------|--------------|---------------|
| Denied a needed tool | Agent retries or rephrases; may ask again | Partially — depends on agent behavior |
| Allowed a dangerous tool | No undo. Tool executes immediately. | ❌ No "are you sure?" for dangerous operations |
| Switched to Prometheus by mistake | Must navigate to `/settings` or know the keyboard shortcut | ❌ No on-screen guidance |
| Granted "Always allow" for wrong tool | Must restart session or know about `resetGrants()` | ❌ No revocation UI |
| Hit Esc accidentally (maps to Deny) | Agent retries or fails | Partially — may disrupt flow |

### Critical gap: no confirmation for dangerous "always" choices

When the user selects "Prometheus ⚡" (auto-approve everything) or "Always allow" for `bash`, there's no secondary confirmation. A single Enter press permanently changes the session's security posture until restart.

macOS requires Touch ID/Face ID for "Always Allow" in Keychain. iOS shows a confirmation dialog before changing location permissions to "Always". KosmoKrator treats a mode switch the same as a single-file approval.

### Critical gap: no undo

If a tool executes and the result is wrong (e.g., `file_write` overwrites important content), there's no built-in rollback. Git-tracked projects have `git checkout`, but:
1. The prompt doesn't indicate whether the file is git-tracked
2. There's no "last chance to revert" indicator
3. The `ProjectBoundaryCheck` prevents out-of-project writes, but that's invisible to the user

---

## 8. Recommendations

### R1. Split the decision into two layers (progressive disclosure)

**Current:** 5 flat options in one list.  
**Proposed:** Primary decision first, then optional mode switch.

```
┌─ ⌨ Edit Approval ─────────────────────────────────────┐
│ File Write · src/UI/Theme.php                          │
│ Risk: 🟡 Writes workspace files                        │
│                                                        │
│ + public static function newMethod(): string           │
│ + {                                                    │
│ +     return 'hello';                                  │
│ … (12 more lines)                                      │
│                                                        │
│ Approve this file write?                               │
│                                                        │
│ › [a] Allow once      Just this call                   │
│   [A] Allow session   All file_write this session      │
│   [d] Deny            Block this call                  │
│                                                        │
│ [m] Change mode…  ·  Enter confirm  Esc deny           │
└────────────────────────────────────────────────────────┘
```

Pressing `m` opens a secondary menu:

```
┌─ Permission Mode ──────────────────────────────────────┐
│                                                        │
│ Current: Argus ◉ (ask every time)                      │
│                                                        │
│ › [g] Guardian ◈   Auto-approve safe, ask for risky   │
│   [p] Prometheus ⚡ Auto-approve everything            │
│   [a] Argus ◉      Ask every time                     │
│                                                        │
│ Mode applies to all future tool calls this session.    │
│ You can change this anytime with /mode.                │
│                                                        │
│ Enter confirm  Esc cancel                              │
└────────────────────────────────────────────────────────┘
```

**Benefit:** Reduces cognitive load from 5 options to 3 for the common case. Mode switching is opt-in, not in the way.

### R2. Add risk-level badges

Color-code the border and title based on static analysis:

| Risk | Border Color | Example |
|------|-------------|---------|
| 🟢 Read | Green accent | `file_read`, `grep`, `cat` |
| 🟡 Write | Amber accent | `file_edit`, `file_write` in project |
| 🔴 Destructive | Red accent | `rm`, `git push --force`, writes outside project |

Implementation: `GuardianEvaluator` already classifies commands. Extend the preview builder to include a risk level, and have `PermissionPromptWidget` use different `Theme::*` colors for the border.

### R3. Add "why" context (agent reasoning)

Include 1–2 lines of the agent's reasoning before the tool call:

```
│ 💭 "I need to update the Theme class to add the new   │
│     color helper requested by the user."               │
```

This follows the iOS pattern of requiring a usage description. The agent's tool-calling message typically includes reasoning that can be extracted.

### R4. Keyboard shortcuts for fast decisions

Add single-key shortcuts to avoid arrow-key navigation:

- `a` → Allow once (default, also Enter)
- `A` (shift+a) → Always allow (session)
- `d` → Deny (also Esc)
- `g` → Guardian mode
- `p` → Prometheus mode

This matches lazygit's single-key navigation and Git's `y/n` prompts.

### R5. Confirmation for mode switches and "always allow"

Add a secondary confirmation when the user picks a mode-changing or session-wide option:

```
│ ⚠ Switching to Prometheus will auto-approve ALL tool  │
│ calls for the rest of this session.                    │
│                                                        │
│   [Enter] Confirm switch    [Esc] Cancel               │
```

### R6. Show active grants in status bar

After granting "Always allow" for a tool, show a persistent indicator:

```
 bash ⚡ session  │  Guardian ◈  │  1.2k tokens
```

This lets users see at a glance which tools are auto-approved. Include a way to revoke (e.g., click/select the indicator).

### R7. Cap preview height for small terminals

If `RenderContext::getRows()` < 30, collapse sections to minimal mode:
- Show only the first section (Command/File)
- Skip Scope and Expected result
- Reduce Preview to 3 lines

### R8. Make "Always allow" smarter for bash

Instead of per-tool grants, consider **per-command-pattern grants**:

```
│ [A] Allow session   All bash this session              │
│ [P] Allow pattern   Just "npm *" this session          │
```

The `GuardianEvaluator::safeCommandPatterns` infrastructure already supports glob matching. Extending `SessionGrants` to store patterns instead of just tool names would enable this.

---

## 9. Proposed Mockups

### Mockup A: Minimal decision prompt (common case)

```
┌─ 🟡 ⌨ File Edit · src/UI/Theme.php ───────────────────┐
│                                                        │
│ + public static function newMethod(): string           │
│ + {                                                    │
│ +     return 'hello';                                  │
│   - private static function oldMethod(): string        │
│ … (8 more lines)                                       │
│                                                        │
│   › Allow once          Execute this file edit         │
│     Allow for session   Auto-approve file_edit         │
│     Deny                Block this edit                │
│                                                        │
│   Enter ✓   Esc ✗   m Mode…                           │
└────────────────────────────────────────────────────────┘
```

**Changes:**
- Risk badge (🟡) in title
- Tool name + file path in title bar (saves a section)
- 3 options instead of 5
- `m` for mode switch is opt-in
- Keyboard hints use symbols, not words

### Mockup B: Bash command with agent reasoning

```
┌─ 🟡 ⌨ Bash Command ───────────────────────────────────┐
│ 💭 "Running tests to verify the refactoring."          │
│                                                        │
│ Command                                                │
│   vendor/bin/pest --filter=PermissionPromptTest        │
│                                                        │
│ Expected: runs targeted tests and reports the result   │
│                                                        │
│   › [a] Allow once       Run this command              │
│     [A] Allow session    Auto-approve bash             │
│     [d] Deny             Block this command            │
│                                                        │
│   Enter ✓   Esc ✗   m Mode…                           │
└────────────────────────────────────────────────────────┘
```

### Mockup C: Dangerous command with confirmation

```
┌─ 🔴 ⌨ Bash Command ───────────────────────────────────┐
│ 💭 "Cleaning up the build artifacts before release."   │
│                                                        │
│ Command                                                │
│   rm -rf build/ dist/                                  │
│                                                        │
│ ⚠ This command modifies files on disk.                 │
│                                                        │
│   › [a] Allow once       Run this command              │
│     [A] Allow session    ⚠ Auto-approve ALL bash      │
│     [d] Deny             Block this command            │
│                                                        │
│   Enter ✓   Esc ✗   m Mode…                           │
└────────────────────────────────────────────────────────┘

[After selecting "Allow session" (A) + Enter]

┌─ ⚠ Confirm Session Grant ─────────────────────────────┐
│                                                        │
│ Auto-approve ALL bash commands for this session?       │
│ This includes potentially destructive commands.        │
│                                                        │
│ Grants reset when the session ends.                    │
│ Revoke anytime: /grants                                │
│                                                        │
│   [Enter] Yes, auto-approve    [Esc] Cancel            │
└────────────────────────────────────────────────────────┘
```

### Mockup D: Mode switch dialog (via `m` key)

```
┌─ 🔧 Permission Mode ──────────────────────────────────┐
│                                                        │
│ Current: Guardian ◈ (auto-approve safe, ask for risky) │
│                                                        │
│   › Guardian ◈    Auto-approve safe operations         │
│     Argus ◉       Ask for every tool call              │
│     Prometheus ⚡  Auto-approve everything (risky)      │
│                                                        │
│ Mode applies to all future tool calls this session.    │
│ Change anytime: /mode                                  │
│                                                        │
│   Enter confirm   Esc cancel                           │
└────────────────────────────────────────────────────────┘
```

### Mockup E: Status bar with active grants

```
 file_edit ✓session │ Guardian ◈ │ Arg 4.7s │ 2.1k tok │ $0.03
```

The `✓session` badge indicates `file_edit` has been session-granted. It's clickable (or has a keyboard shortcut) to revoke.

---

## 10. Summary Scorecard

| Dimension | Current | Target | Priority |
|-----------|---------|--------|----------|
| Action clarity (what's happening) | B | A | Medium |
| Risk communication | D | A | 🔴 High |
| Option comprehensibility | C | A | 🔴 High |
| Cognitive load | C− | B+ | 🔴 High |
| Preview quality | B+ | A | Low |
| "Always allow" safety | C | B+ | 🟡 Medium |
| Error recovery | D | B | 🟡 Medium |
| Keyboard efficiency | B | A | Low |
| Agent reasoning context | F | B | 🟡 Medium |
| Terminal size adaptability | C | B | Low |

### Top 3 priorities

1. **Split options into primary decision + mode switch** (R1) — single highest-impact change
2. **Add risk badges** (R2) — enables fast triage without reading the full preview
3. **Add confirmation for mode switches and session grants** (R5) — prevents accidental security downgrades

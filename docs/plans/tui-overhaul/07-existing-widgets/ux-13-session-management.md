# UX Audit: Session Management

> **Research Question**: How good is session management in KosmoKrator's TUI?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `TuiModalManager.php`, `TuiConversationRenderer.php`, `SessionManager.php`, `SessionRepository.php`, `MessageRepository.php`, `Database.php`, `ResumeCommand.php`, `SessionsCommand.php`, `RenameCommand.php`, `SessionFormatter.php`, `TuiCoreRenderer.php`, `TuiInputHandler.php`

---

## Executive Summary

KosmoKrator's session management is **architecturally solid but UX-thin**. The persistence layer (`SessionRepository` → SQLite with WAL mode) is robust: sessions, messages, compaction, and cleanup all work correctly at the data layer. The problem is entirely in the **presentation and interaction** layer. Users interact with sessions through three slash commands (`/resume`, `/sessions`, `/rename`) and a bare `SelectListWidget` picker. There is no persistent sidebar, no visual session indicator, no search/filter in the picker, and no confirmation before destructive operations.

Compared to Claude Code (auto-resume with `--resume`), ChatGPT (always-visible sidebar), and even Vim (`:mksession` / `:source`), KosmoKrator's session management feels like a hidden feature rather than a first-class workflow. Sessions exist, but the user must remember they exist and know the right commands.

**Severity**: Medium-High. Session management is critical for any agent that works on multi-turn tasks. Poor discoverability and missing guardrails directly cause lost work (e.g., accidentally deleting the current session via `/sessions delete <prefix>`).

---

## 2. Architecture Overview

### 2.1 Data Model

```
sessions (SQLite)
├── id: UUID v4 (TEXT PK)
├── project: absolute path (TEXT)
├── title: auto-set from first user message, max 80 chars (TEXT, nullable)
├── model: LLM model identifier (TEXT)
├── created_at: Unix float timestamp (TEXT)
└── updated_at: Unix float timestamp (TEXT, bumped on every message)

messages (SQLite)
├── id: auto-increment (INTEGER PK)
├── session_id → sessions.id (FK)
├── role: user | assistant | system | tool_result
├── content, tool_calls, tool_results, tokens_in, tokens_out
├── compacted: 0 | 1 (excluded from active context)
└── created_at (ISO datetime)
```

**Key facts**:
- Sessions are **per-project** (scoped by working directory path)
- Auto-titling: first user message, truncated to 80 chars (`SessionManager.php:152`)
- Messages are fully serialized (tool calls, tool results) and restorable
- Compaction replaces old messages with a system summary, then deletes the originals
- Cleanup (`/sessions clean`) uses `ROW_NUMBER()` partitioning to protect the N most recent sessions per project

### 2.2 Command Interface

| Command | Trigger | Interactive? | Immediate? |
|---------|---------|-------------|------------|
| `/resume` | TUI input or `/resume <id>` | Yes (picker if no args) | No |
| `/sessions` | TUI input | No (text output) | Yes |
| `/sessions clean [N]` | TUI input | No | Yes |
| `/sessions delete <id>` | TUI input | No | Yes |
| `/rename <title>` | TUI input | No | Yes |

### 2.3 Session Picker Flow

```
User types /resume (no args)
  → ResumeCommand::execute()
    → SessionManager::listSessions(50)
    → Build items[] with value, label, description
    → UIManager::pickSession(items)
      → TuiModalManager::pickSession(items)
        → SelectListWidget (maxVisible: 12, style: 'slash-completion')
        → Blocks via Revolt Suspension
        → Returns selected session ID or null (on cancel)

User types /resume <id-or-prefix>
  → SessionManager::findSession(args)
    → find() exact match, then findByPrefix()
    → If found: resumeSession() → loadHistory() → ToolResultDeduplicator
    → If not: "No session found matching '<args>'"
```

---

## 3. Audit Findings

### 3.1 Session Picker: Is It Easy to Find and Resume Sessions?

**Rating: 4/10 — Functional but bare**

The session picker (`TuiModalManager::pickSession()`) is a plain `SelectListWidget` with no header, no instructions, and no visual hierarchy:

```
Current picker (conceptual):
┌──────────────────────────────────────────────────────┐
│  Fix the failing test for login (current)            │
│  Refactor payment module                             │
│  Add user authentication                            │
│  Why is the CI failing?                             │
│  Implement dark mode                                │
│  (empty)                                            │
│  Debug memory leak in worker                        │
│  ...                                                │
└──────────────────────────────────────────────────────┘
```

**Problems**:

1. **No header or instructions** — The picker appears as a floating select list with no title, no hint text (e.g., "Select a session to resume"), no keyboard shortcut hints (↑↓ to navigate, Enter to select, Esc to cancel). A user who hasn't used `/resume` before will not know what they're looking at.

2. **No search/filter** — Sessions are listed in `updated_at DESC` order only. With 50 sessions, finding a specific one requires linear scanning. There's no fuzzy matching, no type-to-filter. Compare: Claude Code's `--resume` accepts a substring match against session titles. ChatGPT has a search bar in the sidebar.

3. **Description is minimal** — Each item shows `"{msgCount} msgs, {age}"` as the description. Missing: model used, token count, whether the session has active tasks, whether it was compacted.

4. **No visual distinction for the current session** — The label appends ` (current)` as plain text. There's no color, icon, or dimming to distinguish it from other entries. Selecting the current session is a no-op that wastes user time.

5. **No empty-state guidance** — When `items === []`, `pickSession()` returns `null` immediately. The calling code in `ResumeCommand` shows "No sessions to resume." as a notice. Better: show a hint about creating a session by starting a conversation.

6. **maxVisible: 12 is arbitrary** — On a 50-row terminal, only 12 sessions are visible at once. The remaining 38 require scrolling with no indication of total count.

**Code reference**: `TuiModalManager.php:330–365`, `ResumeCommand.php:44–66`

### 3.2 Session Switching: Is It Smooth?

**Rating: 5/10 — Correct but jarring**

The resume flow (`ResumeCommand.php:78–98`):

```php
$history = $ctx->sessionManager->resumeSession($sessionId);
$ctx->agentLoop->setHistory($history);
$ctx->permissions->resetGrants();

// Re-apply stored mode setting
$modeSetting = $ctx->sessionManager->getSetting('mode');
if ($modeSetting !== null) {
    $mode = AgentMode::from($modeSetting);
    $ctx->agentLoop->setMode($mode);
    $ctx->ui->showMode($mode->label(), $mode->color());
}

$ctx->ui->clearConversation();
$ctx->ui->replayHistory($history->messages());
```

**What works well**:
- Conversation history is fully restored and replayed via `TuiConversationRenderer::replayHistory()`
- Permission grants are reset (preventing stale tool permissions from the old session)
- Agent mode is restored from saved settings
- Session is touched (bumped to top of recent list)

**What's missing**:
1. **No confirmation dialog** — Resuming a session while the current session has unsaved work silently clears the conversation. There's no "You have N messages in the current session. Resume anyway?" prompt.

2. **Visual discontinuity** — `clearConversation()` wipes the entire screen, then `replayHistory()` dumps all messages at once. There's no transition animation, no "Loading session..." indicator, no progressive rendering.

3. **No indication of what was restored** — The notice says `"Resumed: {title} ({count} messages)"` but doesn't show which mode, which model, or what the last topic was.

4. **Permission mode not restored** — While agent mode (edit/plan/ask) is restored, the permission mode (Guardian/Argus/Prometheus) is not explicitly restored from the session's settings. Only the `mode` setting is checked.

5. **Task state is lost** — The `TaskStore` is not serialized per-session. Resuming a session that had subagent tasks running shows no task tree, even if tasks were in progress when the session was last active.

### 3.3 History Replay: Does It Render Correctly?

**Rating: 7/10 — Comprehensive but dense**

`TuiConversationRenderer::replayHistory()` (lines 28–192) handles every message type:

| Message Type | Replay Treatment | Quality |
|-------------|-----------------|---------|
| UserMessage | `⟡ {content}` with user-message style | ✅ Clean |
| AssistantMessage text | MarkdownWidget or AnsiArtWidget | ✅ Good |
| Tool calls (file ops) | Icon + label + path, CollapsibleWidget if >120 chars | ✅ Good |
| Tool calls (bash) | BashCommandWidget with result | ✅ Good |
| Tool calls (omens) | DiscoveryBatchWidget (grouped) | ✅ Good |
| Tool calls (tasks) | Skipped (task bar shows tree) | ⚠️ No tree on resume |
| ask_user / ask_choice | QuestionRecap with Q&A pair | ✅ Good |
| Tool results | CollapsibleWidget with diff/highlight | ✅ Good |
| ToolResultMessage | Paired with preceding tool call via toolCallId index | ✅ Correct |

**Issues**:

1. **All messages render at once** — For a 200-message session, replay creates 200+ widgets synchronously. This can cause a noticeable pause (500ms+) and the user sees a sudden wall of content with no loading indicator.

2. **Collapsed state is not preserved** — `CollapsibleWidget` instances are always created in the default (collapsed?) state. If the user had expanded a tool result during the original session, that state is lost on resume.

3. **Discovery batches group across the entire history** — The `$discoveryGroup` accumulates omens tool calls and flushes on non-omens calls. This works correctly but may group calls that were visually separated in the original session.

4. **ANSI art detection is correct** — `containsAnsiEscapes()` checks for `\x1b[` and routes to `AnsiArtWidget`, which is appropriate.

5. **Tool result deduplication runs on load** — `ToolResultDeduplicator` replaces stale file reads with `[Superseded — ...]` placeholders. This reduces context sent to the LLM but may confuse users who see "[Superseded]" in their replayed history without explanation.

### 3.4 Session Naming: Can Users Name/Identify Sessions?

**Rating: 6/10 — Auto-naming works, manual naming is hidden**

**Auto-naming** (`SessionManager.php:148–153`):
```php
if ($session['title'] === null && $role === 'user' && $content !== null) {
    $title = mb_substr($content, 0, 80);
    $this->sessions->updateTitle($this->currentSessionId, $title);
}
```
- First user message becomes the title (truncated to 80 chars)
- This works reasonably well for short, focused prompts
- Fails for multi-line prompts (newlines are preserved in the title)
- Fails for vague prompts like "fix it" or "continue"

**Manual naming** (`RenameCommand`):
- `/rename <title>` or `/rename "Title with spaces"`
- Immediate command, no feedback beyond a notice
- Not suggested anywhere in the UI — no prompt to name the session
- Not shown in auto-completion hints prominently

**Identification in session list** (`SessionsCommand::formatSessionLine`):
```
  a1b2c3d4  Fix the failing test for login  (12 msgs, 5m ago)
  e5f6a7b8  Refactor payment module          (45 msgs, 2h ago) ←
```
- Shows first 8 chars of UUID, truncated preview (60 chars), message count, relative age
- Current session marked with `←`
- The `last_user_message` takes priority over `title` in the preview — this can show a follow-up message instead of the session's topic

**What's missing**:
1. **No emoji/tag system** — No way to categorize or prioritize sessions (e.g., 🔴 urgent, 🟢 done, 🔵 research)
2. **No pinned sessions** — No mechanism to pin important sessions to the top
3. **Title never updates after the first message** — If the conversation drifts to a different topic, the title becomes stale
4. **No title in status bar** — The current session title is never displayed in the TUI status bar or title area

### 3.5 Session Cleanup: Is Old Session Management Easy?

**Rating: 5/10 — Functional but risky**

**Cleanup commands**:
```
/sessions            → List up to 50 sessions (text output)
/sessions clean      → Delete sessions older than 30 days (keeps 5/project)
/sessions clean 7    → Delete sessions older than 7 days (keeps 5/project)
/sessions delete abc → Delete session with ID prefix "abc"
```

**Problems**:

1. **No confirmation on delete** — `/sessions delete <id>` immediately deletes the session and all its messages via a transaction. No "Are you sure?" prompt. No undo. If the user guesses the wrong prefix, they lose data.

2. **No dry-run for cleanup** — `/sessions clean` doesn't show which sessions would be deleted before deleting them. A count is returned after the fact ("Cleaned up 12 session(s)"), but the user doesn't know which ones.

3. **Prefix matching is ambiguous** — `findByPrefix()` returns `null` if the prefix matches more than one session. The error message "Session not found: {id}" doesn't explain that the prefix was ambiguous.

4. **No session size information** — There's no way to see how much storage a session uses (messages, memories). Users cleaning up have no way to prioritize large sessions.

5. **List output is plain text** — `/sessions` outputs a text notice with one line per session. This doesn't leverage the TUI at all — no select list, no color coding, no interactivity.

6. **No archive/export** — There's no way to export a session before deleting it. Once deleted, all context is lost.

### 3.6 Context Preservation: What's Lost on Resume?

**Rating: 6/10 — Core state preserved, ambient state lost**

**Preserved across resume**:
| State | Mechanism | Reliability |
|-------|-----------|-------------|
| Conversation messages | SQLite `messages` table | ✅ Full fidelity |
| Tool call arguments + results | JSON-serialized in messages | ✅ Full fidelity |
| Session title | `sessions.title` | ✅ Preserved |
| Agent mode (edit/plan/ask) | `settings` table (mode key) | ✅ Restored |
| Model selection | `sessions.model` | ✅ Stored |
| Memories | `memories` table (project-scoped) | ✅ Survive session switch |
| Settings (project/global) | `settings` table | ✅ Survive session switch |

**Lost on resume**:
| State | Impact | Severity |
|-------|--------|----------|
| Permission grants | Reset to mode default | Low — expected behavior |
| Permission mode (Guardian/Argus/Prometheus) | Not explicitly restored | Medium — user must re-set |
| Collapsible widget state | All collapsed | Low — cosmetic |
| Scroll position | Reset to top | Low — cosmetic |
| Task tree (subagent tasks) | Not serialized per session | High — lost work visibility |
| Streaming response (if interrupted) | Partial message may be saved | Low — rare edge case |
| Compacted context window | Summary replaces original | Low — by design |

**Critical gap**: Task state is the biggest loss. If a user had 3 subagents running across multiple files, resuming the session shows the conversation but no active task tracking. The user must mentally reconstruct what was happening.

---

## 4. Competitive Comparison

### 4.1 Claude Code

| Feature | Claude Code | KosmoKrator | Gap |
|---------|-------------|-------------|-----|
| Auto-resume | `claude --resume` picks latest session | Must use `/resume` explicitly | Medium |
| Session list | `claude --resume` with picker | `/resume` picker | Similar |
| Title/ID search | Substring match on titles | ID prefix only, no title search | High |
| Session naming | Not supported | `/rename` command | KosmoKrator ahead |
| History replay | Full conversation replay | Full conversation replay | Similar |
| Session continuity | Resumes where left off | Same | Similar |
| Cleanup | No built-in cleanup | `/sessions clean` | KosmoKrator ahead |

### 4.2 ChatGPT

| Feature | ChatGPT | KosmoKrator | Gap |
|---------|---------|-------------|-----|
| Sidebar visibility | Always visible, left panel | Hidden behind `/sessions` | Very High |
| Search/filter | Full-text search in sidebar | No search in picker | High |
| Pinned conversations | Yes | No | Medium |
| Folder organization | Yes | No (flat list per project) | Medium |
| Auto-titling | LLM-generated title | First user message (80 chars) | High |
| Rename inline | Click title to rename | `/rename` command | Medium |
| Visual indicators | Unread dot, shared icon | None | High |

### 4.3 Vim (`:mksession`)

| Feature | Vim | KosmoKrator | Gap |
|---------|-----|-------------|-----|
| Explicit save | `:mksession` saves to file | Auto-saves every message | KosmoKrator ahead |
| Named sessions | `:mksession ~/.vim/sessions/project.vim` | `/rename` + UUID | Medium |
| Session restoration | `vim -S session.vim` | `/resume` | Similar |
| State preserved | Buffers, windows, tabs, registers | Messages, settings, mode | Different scopes |
| Multiple sessions | Multiple session files | Multiple DB rows | Similar |

---

## 5. Recommendations

### 5.1 Session Picker Overhaul (Priority: High)

**Current**: Bare `SelectListWidget` with no header, no search, no context.

**Proposed mockup**:

```
┌─ Resume Session ─────────────────────────────────────────────────────────┐
│  ↑↓ navigate  ·  type to filter  ·  Enter select  ·  Esc cancel         │
│  ─────────────────────────────────────────────────────────────────────── │
│  🔵 Fix the failing test for login                              ← current │
│     12 msgs · claude-3.5-sonnet · 5m ago                                │
│                                                                          │
│  ○ Refactor payment module                                               │
│    45 msgs · gpt-4o · 2h ago                                            │
│                                                                          │
│  ○ Add user authentication                                               │
│    23 msgs · claude-3.5-sonnet · 1d ago                                 │
│                                                                          │
│  ○ Why is the CI failing?                                                │
│    8 msgs · gpt-4o · 3d ago                                             │
│                                                                          │
│  ○ Implement dark mode                                                   │
│    67 msgs · claude-3.5-sonnet · 5d ago                                 │
│                                                                          │
│  ── showing 5 of 23 ── type to filter ────────────────────────────────  │
└──────────────────────────────────────────────────────────────────────────┘
```

**Changes needed**:
1. Add header with title ("Resume Session") and keyboard hints
2. Add type-to-filter (fuzzy match on title + last user message)
3. Show model name in description
4. Visual distinction for current session (🔵 + dim + "← current")
5. Show total count vs. visible count
6. Two-line items: title (bold) + metadata (dim)

### 5.2 Session Status Indicator (Priority: High)

Add the current session title to the TUI status bar or a dedicated header line:

```
Current:
  Edit · Guardian ◈ · 12.4k/200k · claude-3.5-sonnet

Proposed:
  Edit · Guardian ◈ · 12.4k/200k · claude-3.5-sonnet · 📂 Fix the failing test
```

This gives users constant awareness of which session they're in.

### 5.3 Confirmation Dialog for Destructive Operations (Priority: High)

`/sessions delete <id>` needs a confirmation step. Use `TuiModalManager::askChoice()`:

```
┌─ Delete Session ────────────────────────────────────────────────────────┐
│                                                                          │
│  Delete "Refactor payment module" (45 messages)?                         │
│  This action cannot be undone.                                           │
│                                                                          │
│  ┌────────────────┐  ┌────────────────┐                                  │
│  │    Delete       │  │    Cancel      │                                  │
│  └────────────────┘  └────────────────┘                                  │
└──────────────────────────────────────────────────────────────────────────┘
```

### 5.4 Confirmation Before Switching Sessions (Priority: Medium)

When the current session has > 0 messages and the user runs `/resume`, show a brief confirmation:

```
┌─ Resume Session ────────────────────────────────────────────────────────┐
│                                                                          │
│  Current session has 12 messages.                                        │
│  Resume a different session? (Current session is auto-saved.)            │
│                                                                          │
│  Resume    ·    Cancel                                                   │
└──────────────────────────────────────────────────────────────────────────┘
```

### 5.5 Progressive History Replay (Priority: Medium)

Instead of dumping all widgets at once:

1. Show a "Loading session..." status
2. Render the last 5 turns immediately
3. Render older turns in batches of 10 in the background
4. Add a "↑ Scroll up to load earlier messages" affordance

```
  ┌──────────────────────────────────────────────────────────┐
  │  ⏳ Loading session... showing recent messages first.     │
  │  ↑ Scroll up to load 187 earlier messages                 │
  └──────────────────────────────────────────────────────────┘
```

### 5.6 Interactive Session List (Priority: Medium)

Upgrade `/sessions` from plain text to an interactive modal:

```
┌─ Sessions ──────────────────────────────────────────────────────────────┐
│  ↑↓ navigate  ·  Enter resume  ·  d delete  ·  r rename  ·  q close    │
│  ─────────────────────────────────────────────────────────────────────── │
│  🔵 Fix the failing test for login                              ← active │
│     12 msgs · claude-3.5-sonnet · 5m ago                                │
│                                                                          │
│  ○ Refactor payment module                        [d to delete]          │
│    45 msgs · gpt-4o · 2h ago                                            │
│                                                                          │
│  ○ Add user authentication                        [d to delete]          │
│    23 msgs · claude-3.5-sonnet · 1d ago                                 │
│                                                                          │
│  ── 23 sessions · 3 older than 30 days ── `/sessions clean` to prune ── │
└──────────────────────────────────────────────────────────────────────────┘
```

With inline actions:
- `Enter` — resume selected session
- `d` — delete selected session (with confirmation)
- `r` — rename selected session
- `q` / `Esc` — close

### 5.7 Smarter Auto-Titling (Priority: Medium)

Replace the raw first-message approach with a smarter heuristic:

1. **Strip common prefixes**: "please", "can you", "I need"
2. **Remove newlines and collapse whitespace** before truncating
3. **Update title on conversation drift**: If the assistant summarizes the conversation (via compaction), extract a title from the summary
4. **Suggest a title after 3 turns**: Show an inline hint: `💡 Session title: "Fix login test". /rename to change.`

### 5.8 Session Picker Search/Filter (Priority: High)

Add fuzzy filtering to the picker widget:

```php
// When the user types in the picker, filter items:
$selectList->onInput(function (string $query) use ($allItems) {
    $filtered = $this->fuzzyMatch($allItems, $query);
    $selectList->setItems($filtered);
});
```

This requires extending `SelectListWidget` to support an input mode where typing filters the list rather than selecting items. Alternatively, add a separate `InputWidget` above the list that filters on each keystroke.

### 5.9 Task State Serialization (Priority: High)

The biggest context gap on resume is lost task state. Recommendations:

1. Serialize the `TaskStore` tree as JSON alongside the session (new column or separate table)
2. On resume, restore the task tree with tasks marked as "interrupted"
3. Show an inline notice: `⚠ 3 tasks were running when this session ended. They have been marked as interrupted.`

### 5.10 `/sessions clean` Dry Run (Priority: Low)

Add a `--dry-run` flag or show the list before deleting:

```
/sessions clean --dry-run

  Would delete 12 sessions older than 30 days:
    e5f6a7b8  Old feature branch work     (5 msgs, 35d ago)
    1a2b3c4d  Test something              (2 msgs, 42d ago)
    ...
  Run /sessions clean to confirm.
```

---

## 6. Summary Scorecard

| Dimension | Score | Key Issue |
|-----------|-------|-----------|
| Session picker ease-of-use | 4/10 | No search, no header, no context |
| Session switching smoothness | 5/10 | No confirmation, jarring clear+replay |
| History replay fidelity | 7/10 | Comprehensive but dense, no progressive loading |
| Session naming/identification | 6/10 | Auto-title works, manual naming hidden |
| Session cleanup safety | 5/10 | No confirmation, no dry-run |
| Context preservation | 6/10 | Messages preserved, task state lost |
| **Overall** | **5.5/10** | **Solid foundation, poor presentation** |

---

## 7. Implementation Priority

| Priority | Recommendation | Effort | Impact |
|----------|---------------|--------|--------|
| 🔴 P0 | Confirmation dialog for `/sessions delete` | Small | High |
| 🔴 P0 | Search/filter in session picker | Medium | High |
| 🔴 P0 | Header + keyboard hints in picker | Small | High |
| 🟡 P1 | Session title in status bar | Small | Medium |
| 🟡 P1 | Task state serialization | Medium | High |
| 🟡 P1 | Confirmation before session switch | Small | Medium |
| 🟢 P2 | Interactive session list modal | Medium | Medium |
| 🟢 P2 | Progressive history replay | Medium | Medium |
| 🟢 P2 | Smarter auto-titling | Small | Medium |
| 🔵 P3 | Dry-run for `/sessions clean` | Small | Low |
| 🔵 P3 | Session export/archive | Medium | Low |

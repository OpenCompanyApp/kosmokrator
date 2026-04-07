# UX Audit: Mental Model Alignment

> **Research Question**: What is the user's mental model of KosmoKrator, and does the TUI match it?
>
> **Date**: 2026-04-07
> **Auditor**: UX Research Agent
> **Files examined**: `AgentMode.php`, `AgentType.php`, `AgentPhase.php`, `PermissionMode.php`, `PermissionPromptWidget.php`, `PermissionEvaluator.php`, `SubagentOrchestrator.php`, `SubagentDisplayManager.php`, `SwarmDashboardWidget.php`, `TuiCoreRenderer.php`, `TuiToolRenderer.php`, `TuiInputHandler.php`, `SettingsSchema.php`, `Theme.php`
> **Prior audits referenced**: ux-01 through ux-14

---

## Executive Summary

KosmoKrator's TUI presents a **richer conceptual model than any competing AI coding agent**, but this richness creates a **mental model gap**: users arriving from Claude Code, Aider, or ChatGPT expect a simpler "chat → action → result" loop. What they find is a system with three agent modes, three permission modes, three agent types, subagent trees with dependency chains, and 46+ slash commands across three namespaces.

The core tension: **KosmoKrator is architecturally a multi-agent system with granular permissions, but it presents itself as a chat interface.** The chat metaphor works for conversation, but breaks down when the system spawns parallel subagents, switches modes, or asks permission for operations the user didn't directly request.

**Overall grade: C+** — the system works for users who already understand the mental model (i.e., the developers), but new users must construct the model by inference. The TUI exposes all the right information but fails to frame it into a coherent narrative that matches user expectations.

---

## 1. Mode Confusion: Edit / Plan / Ask

### 1.1 The Conceptual Model

KosmoKrator has three agent modes (`src/Agent/AgentMode.php:11-136`):

| Mode | Color | Can write files | Can spawn subagents | System prompt framing |
|------|-------|----------------|--------------------|-----------------------|
| **Edit** | Green `#50c878` | ✅ | ✅ General, Explore, Plan | "Full access to all tools… Execute the user's request directly." |
| **Plan** | Purple `#a078ff` | ❌ | ✅ General, Explore, Plan | "READ-ONLY phase. Produce a detailed, actionable plan." |
| **Ask** | Orange `#ffb43c` | ❌ | ❌ | "Read and search files to answer questions, but MUST NOT modify anything." |

Switched via `Shift+Tab` (cycling), `/mode`, or the explicit `/edit`, `/plan`, `/ask` commands.

### 1.2 Mental Model Problems

**Problem 1: The mode taxonomy doesn't map to user intent.**

Users don't think in terms of "what tools are available." They think:
- *"I want to change something"* → should map to Edit
- *"I want to understand the codebase"* → could map to Ask *or* Plan
- *"I want a plan before acting"* → should map to Plan

But the actual distinction is about **tool permissions**, not intent. Plan mode *can* spawn General subagents that have write access — meaning "read-only Plan mode" isn't truly read-only when subagents are involved. This creates a **leaky abstraction**: the user sees "Plan (read-only)" but the system's subagents can still write.

**Problem 2: Mode switching is frictionful and undersignaled.**

The current mode is shown only in the status bar (`ProgressBarWidget` at the bottom of the screen) as a small colored badge. There is no:
- Mode banner or indicator near the input prompt
- Confirmation when switching modes
- Explanation of what changed when cycling with `Shift+Tab`
- Mode-appropriate input hint (e.g., "Changes will be applied directly" vs "Read-only — no files will be modified")

The user can switch modes accidentally (`Shift+Tab` is easy to hit when intending `Tab` for autocomplete) with no confirmation or undo.

**Problem 3: Ask mode creates expectation mismatch.**

Ask mode blocks *all* subagents, which means a user asking "what does this codebase do?" in Ask mode gets a shallow answer (single agent, no parallel exploration), while the same question in Plan mode gets a much richer answer (subagents explore concurrently). The user doesn't understand *why* the same question yields different depth — the mode system silently changes agent behavior without surfacing the tradeoff.

### 1.3 Severity

**Medium-High.** Mode confusion leads to either:
- Users staying in Edit mode always (never using Plan/Ask, losing the safety benefits)
- Users switching to Ask mode and wondering why the agent seems "dumber"
- Users accidentally switching modes and not understanding why behavior changed

### 1.4 Reference Comparison

| Tool | Mode concept | How it's surfaced |
|------|-------------|-------------------|
| **Claude Code** | No modes — single "do everything" agent | Status shows model name only |
| **Aider** | Architect/Editor/Ask modes (similar to KosmoKrator) | Mode shown in prompt prefix (`aider>` vs `architect>`) |
| **Cursor** | Agent/Ask modes | Tab-based toggle in input area |
| **ChatGPT** | No modes — single conversation | N/A |

Aider's approach is closest but uses **prompt prefix** as the primary indicator (not a status bar), making the current mode unmissable. KosmoKrator buries it in the status bar.

---

## 2. Agent Autonomy Perception

### 2.1 What the User Sees

When the user sends a message, the TUI transitions through phases (`AgentPhase`):

```
Thinking → Tools → Idle
  (blue)    (amber)   (✓)
```

During **Thinking**, the user sees:
- A celestial spinner (randomly selected from 14 themes)
- A mythological phrase ("Consulting the Oracle at Delphi…")
- An elapsed timer (when no subagents are running)

During **Tools**, the user sees:
- Individual tool call widgets (file_read, bash, file_edit, etc.)
- Discovery batches (grouped read-only operations)
- Permission prompts (if applicable)
- Subagent spawn notifications

### 2.2 Mental Model Problems

**Problem 1: "Consulting the Oracle" is whimsy, not information.**

The user's mental model: *"The AI is thinking about my problem."*  
What the system shows: *"♃ Aligning the celestial spheres…"*

This is charming on first exposure but actively unhelpful on the 50th turn. Users want to know *what the agent is doing*, not receive a mythological metaphor. Claude Code shows context verbs: "Analyzing your codebase…", "Reading 3 files…", "Writing changes…". The cosmic theming **obscures agent intent**.

**Problem 2: Tool call explosion creates a "black box" perception.**

A single user request can generate 10-30 tool calls. The TUI shows each one as a widget (or batches them into DiscoveryBatches), but the user sees a wall of tool activity without understanding the *narrative*:

```
▶ file_read src/Foo.php
▶ file_read src/Bar.php
▶ grep pattern="auth" src/
▶ file_edit src/Foo.php
▶ bash "phpunit --filter=testAuth"
```

The user sees *what happened* but not *why*. There's no "the agent read Foo.php and Bar.php to understand the auth flow, then edited Foo.php to fix the bug, then ran tests to verify." The narrative exists in the LLM's reasoning (shown in CollapsibleWidget "Reasoning") but is collapsed by default.

**Problem 3: The thinking-to-tools transition is invisible.**

The phase switches from Thinking → Tools, the spinner color changes from blue to amber, but there's no explicit moment where the user can say "ah, it stopped thinking and started doing." The transition is seamless — which sounds good but means the user can't distinguish deliberation from action.

### 2.3 Severity

**Medium.** Autonomy perception affects trust. If users can't understand what the agent is doing, they either:
- Trust it blindly (dangerous with write access)
- Distrust it and interrupt frequently (frustrating, defeats the point of an agent)
- Switch to Prometheus mode to avoid permission prompts (defeats safety)

---

## 3. Trust Building: The Permission System

### 3.1 The Permission Architecture

Three permission modes (`PermissionMode.php`):

| Mode | Symbol | Behavior |
|------|--------|----------|
| **Guardian** ◈ | Silver | Auto-approve safe ops, ask for risky |
| **Argus** ◉ | Steel blue | Ask for every governed tool call |
| **Prometheus** ⚡ | Gold | Auto-approve all governed calls |

Default: **Guardian**. The evaluation chain (`PermissionEvaluator.php:33-68`) is sophisticated: blocked paths → deny patterns → session grants → project boundary → rules → mode override.

### 3.2 Mental Model Problems

**Problem 1: Users don't understand what "Guardian" approves automatically.**

Guardian mode auto-approves:
- All reads (file_read, glob, grep)
- Writes inside project root
- Bash commands matching a safe whitelist *without shell operators* (`;`, `&&`, `|`)

The user's mental model: *"Guardian is the middle option — it asks me sometimes."*  
The reality: Guardian auto-approves the vast majority of operations. The user only sees prompts for genuinely ambiguous or risky operations.

But because the user doesn't understand the heuristics, they may:
- Assume Guardian asks for everything (and switch to Prometheus to "save clicks")
- Assume Guardian blocks dangerous operations (and trust it blindly)
- Not understand why some operations are auto-approved and others aren't

**Problem 2: The 5-option permission prompt conflates three decision axes.**

`PermissionPromptWidget.php` presents:

```
1. Allow once          ← scope decision
2. Always allow        ← scope decision (session-wide)
3. Guardian ◈          ← mode switch
4. Prometheus ⚡        ← mode switch
5. Deny                ← rejection
```

This mixes:
- **Scope**: once vs. session-wide (options 1 vs. 2)
- **Mode switching**: current mode → Guardian/Prometheus (options 3-4)
- **Rejection**: deny (option 5)

A user who wants to "allow this but keep being asked" picks option 1. A user who wants to "allow all bash commands this session" picks option 2 — but that grants *all* future calls for that tool, including destructive ones. The granularity is per-tool, not per-operation-type.

**Problem 3: Permission prompts arrive without agent reasoning.**

When the agent decides to run `rm -rf vendor/`, the permission prompt shows the command but not *why* the agent chose to run it. The reasoning is in the collapsed "Reasoning" widget above, but the permission prompt doesn't reference it. The user must:
1. Notice the permission prompt
2. Scroll up to find the reasoning
3. Read the reasoning
4. Scroll back down to the prompt
5. Make a decision

This is a **trust-breaking** workflow. Permission decisions require context, and the prompt strips it away.

### 3.3 Severity

**High.** Permissions are the primary trust boundary between user and agent. If users don't understand what they're approving, the permission system is theater — it creates the *feeling* of safety without providing actual informed consent.

### 3.4 Reference Comparison

| Tool | Permission model | How it surfaces |
|------|-----------------|-----------------|
| **Claude Code** | Binary: allow/deny per-tool | Simple Y/N prompt with tool name and args |
| **Aider** | No permissions (auto-applies) | N/A — auto-commits changes |
| **Cursor** | Binary: apply/reject per-change | Inline diff with accept/reject buttons |
| **KosmoKrator** | 3 modes × 5 options × per-tool grants | Complex decision tree in terminal |

KosmoKrator has the most granular permission system, but granularity without clarity is worse than simplicity. Claude Code's binary Y/N is less powerful but more understandable.

---

## 4. Mental Model of Subagents

### 4.1 The Architecture

KosmoKrator can spawn subagents (`SubagentOrchestrator.php`) with:

| Property | Options |
|----------|---------|
| **Type** | General (read+write), Explore (read-only), Plan (read-only) |
| **Execution** | `await` (parent waits) or `background` (parent continues) |
| **Dependencies** | `depends_on` chains with circular dependency rejection |
| **Grouping** | Sequential groups for ordered execution |
| **Concurrency** | Global semaphore (default: 10 concurrent) |

### 4.2 What the User Sees

The inline view (`SubagentDisplayManager.php`) shows:

```
⏺ 3 agents (2 running, 1 done)
├─ ● Explore  research-1  · Research auth patterns (42s)
├─ ● General  implement  · Write auth middleware (38s)
└─ ✓ Explore  audit-1    · 1m 12s · 8 tools
```

Plus a loader: `⟡ 3 agents active · 1 done · 0:42 · ctrl+a dash`

The full dashboard (`SwarmDashboardWidget`) is behind `Ctrl+A` and shows progress bars, token/cost tracking, per-type breakdowns, and failure details.

### 4.3 Mental Model Problems

**Problem 1: "Subagent" is a developer concept, not a user concept.**

Users from ChatGPT, Claude Code, or Cursor have no mental model for "subagents." They think in terms of:
- "The AI is working on my task"
- "The AI is researching"
- "The AI is making changes"

KosmoKrator shows a tree with agent *types* (General, Explore, Plan), *names* (research-1, implement), and *descriptions*. But the types reuse the same names as the mode system (Edit/Plan/Ask) — creating a confusing overlap. A user in **Ask** mode (no subagents) sees the same terminology used for agent **types** (Plan agents exist). Are "Ask mode" and "Plan agent type" related? The user has no way to know.

**Problem 2: The parent-child relationship is visually unclear.**

The inline tree shows agents at one level. But subagents can spawn their own children (General → Explore → Explore). The tree uses box-drawing connectors, but:
- There's no visual distinction between "the main agent" and "a subagent"
- The depth is flattened in the inline view
- The dashboard shows depth numbers but not a proper tree visualization

**Problem 3: Users don't understand what "await" vs "background" means for them.**

When a subagent runs in `await` mode, the parent stops and waits. When it runs in `background`, the parent continues. From the user's perspective:
- **await**: "The agent paused to delegate, then resumed" → feels sequential, understandable
- **background**: "Multiple things are happening simultaneously" → confusing, no precedent in competing tools

The background execution model is KosmoKrator's most powerful differentiator, but it's also the most confusing for users who expect a linear conversation.

### 4.4 Severity

**Medium-High.** Subagent visibility directly impacts perceived performance and trust. If the user sees "3 agents active · 0:42 · ctrl+a dash" for two minutes with no visible progress, they'll assume the system is stuck. The parallel execution model is a major selling point but only if users can understand it.

### 4.5 Reference Comparison

No competing tool has a comparable subagent system visible to the user. Claude Code has internal parallelism but doesn't expose it. Aider is strictly sequential. This is an opportunity — but the visualization must match the user's mental model, not the system's architecture.

---

## 5. Expectation vs. Reality: New User Journey

### 5.1 Where Users Come From

| Source | Mental model | Key expectation |
|--------|-------------|-----------------|
| **Claude Code** | Chat with an AI that can edit files | Simple prompt → response → diff flow |
| **Aider** | AI pair programmer | Git-integrated, auto-commits, minimal UI |
| **Cursor** | AI-enhanced IDE | Inline suggestions, chat sidebar |
| **ChatGPT** | Conversational AI | Chat bubbles, streaming text, no file access |
| **Terminal tools** (htop, vim, lazygit) | Keyboard-driven, modal | Keyboard shortcuts, modes, efficient workflows |

### 5.2 The First 30 Seconds

**What the user expects:**
1. Start the tool
2. Type a prompt
3. See the AI start working
4. See results

**What happens in KosmoKrator:**
1. Start the tool → 8-second intro animation (cosmic ASCII art, spinner) with no keyboard hints
2. Prompt appears → single-line input with no discoverability aids
3. Type a prompt → AI enters "Thinking" phase with mythological phrase
4. Permission prompt appears (Guardian asks for first write operation) → 5 options, unclear implications
5. Agent starts spawning subagents → tree appears with "3 agents active"
6. Discovery batches flood the conversation → wall of collapsed tool calls
7. Agent streams response → MarkdownWidget with cosmic theming

**The gap:** The user expected "chat → result." They got "ceremony → mysterious thinking → permission decision → parallel agent swarm → tool call flood → response." Every layer of sophistication is another layer of confusion for a new user.

### 5.3 First-Time Discoverability Issues

| Concept | How to discover it | Is it discoverable? |
|---------|-------------------|-------------------|
| Edit/Plan/Ask modes | `Shift+Tab` | No — no hint anywhere |
| Subagent dashboard | `Ctrl+A` | Barely — "ctrl+a dash" in loader text |
| Tool result expand/collapse | `Ctrl+O` | No — audit ux-07 found this undiscoverable |
| Permission modes | Permission prompt | Partially — only visible when prompted |
| 46+ slash commands | `/help` | Partially — requires knowing `/help` exists |
| Multi-line input | `Shift+Enter` | No — no hint anywhere |
| Settings | `/settings` | Partially — standard command convention |
| Scroll history | `PgUp/PgDn` | No — no scroll indicator |

### 5.4 Severity

**High.** First impression determines whether users stay. The 8-second intro animation (documented in ux-01) followed by a featureless prompt creates a poor first impression for users expecting Claude Code's immediate utility.

---

## 6. Information Density

### 6.1 The Density Spectrum

KosmoKrator's conversation view contains these information types per turn:

| Layer | Content | Visual weight |
|-------|---------|---------------|
| User message | `⟡ {text}` with background | Low |
| Thinking phase | Celestial spinner + phrase + timer | Medium |
| Reasoning | Collapsible "Reasoning" block | Low (collapsed) |
| Discovery batch | Grouped read-only tools (3-10 items) | High |
| Tool calls | Individual widgets (bash, edit, write) | High |
| Tool results | Collapsible result blocks | Medium |
| Subagent tree | Live status tree | Medium |
| Subagent loader | Active count + timer | Low |
| Agent response | Streaming Markdown | Medium |
| Status bar | Mode · Permission · Tokens · Cost | Low |

### 6.2 Mental Model Problems

**Problem 1: The tool call layer dominates the viewport.**

A single edit request generates ~21 lines of TUI output (per ux-03). For a 5-file refactor, that's 100+ lines of tool calls between the user's message and the agent's response. The user must scroll through all of it to reach the summary.

Discovery batches help (grouping read-only operations), but they create a different problem: a collapsed batch says "5 file reads" but hides *which* files were read. The user must expand to find out — defeating the purpose of collapsing.

**Problem 2: The status bar is informationally dense but conceptually thin.**

The bottom status bar shows: `Edit · Guardian ◈ · 12.4k tokens · $0.03`

This is useful for power users but meaningless for new users who don't know what "Guardian ◈" means or whether $0.03 is high or low. There's no progressive disclosure — all information is shown at all times.

**Problem 3: Reasoning is collapsed by default.**

The agent's chain-of-thought ("I'll read the auth controller to understand the middleware flow") is the single most useful piece of information for understanding *why* the agent is doing something. But it's collapsed into a "Reasoning" accordion that most users will never expand.

### 6.3 Severity

**Medium.** Information density is a balancing act. Power users want everything visible; new users want simplicity. The current TUI optimizes for neither — it shows too much operational detail (tool calls) and too little contextual meaning (reasoning, narrative).

---

## 7. Feedback Loops: User Control Perception

### 7.1 Control Mechanisms

| Mechanism | How it works | User awareness |
|-----------|-------------|---------------|
| **Mode switching** | `Shift+Tab` cycles Edit → Plan → Ask | Low — no onboarding |
| **Permission approval** | 5-option prompt on governed tools | Medium — visible when triggered |
| **Subagent cancellation** | Per-agent cancel + cancel-all | Low — `Ctrl+A` → dashboard |
| **Input commands** | 46+ slash commands (`/mode`, `/settings`, etc.) | Low — no help overlay |
| **Tool result toggle** | `Ctrl+O` expand/collapse all | Low — undiscoverable |
| **Scroll history** | `PgUp/PgDn` scroll conversation | Low — no scroll indicator |
| **Force refresh** | `Ctrl+L` redraw screen | Very low — emergency feature |

### 7.2 Mental Model Problems

**Problem 1: Control is reactive, not proactive.**

The user can only interact with the system at specific moments:
- **Before**: Choosing mode before sending a message
- **During**: Approving permissions as they arrive
- **After**: Scrolling to review results

There's no **during** control for the main agent's execution. Once the user sends a message, they can only:
- Cancel the entire turn (Escape during thinking)
- Cancel specific subagents (via dashboard)
- Approve/deny individual tool calls

But they can't say "stop what you're doing and let me redirect you." They can't pause the agent mid-turn, adjust the approach, and resume. This creates a **commit-and-wait** interaction pattern that feels like submitting a batch job, not having a conversation.

**Problem 2: The permission system creates an illusion of control.**

Permissions let the user approve/deny individual operations, but:
- The user doesn't know the *plan* — they see one operation at a time
- Denying an operation doesn't stop the agent from trying an alternative
- The agent might retry denied operations with slightly different parameters
- There's no "show me your plan before executing" view (the Plan mode exists but requires switching *before* the turn, not during)

**Problem 3: No feedback on what happens after denial.**

When the user denies a tool call, the agent receives the denial and must decide what to do next. The user sees the denial result, but not the agent's reaction to it. Did the agent:
- Try an alternative approach?
- Give up on that subtask?
- Ask the user for clarification?
- Silently skip a step that affects the final result?

The user doesn't know. Denial is a dead end in the feedback loop.

### 7.3 Severity

**Medium.** Control perception affects user confidence. Users who feel out of control will either micromanage (approving every tool call in Argus mode) or abdicate (switching to Prometheus mode). Neither is healthy.

---

## 8. Comparison: Claude Code vs. KosmoKrator Mental Models

### 8.1 Claude Code's Mental Model

Claude Code presents a deliberately simple model:

```
User types → AI thinks → AI uses tools → AI responds
                ↑                              │
                └──── User can scroll up ──────┘
```

Key characteristics:
- **No modes** — single agent that does everything
- **Binary permissions** — Y/N per tool, no modes
- **No subagent visibility** — parallelism is internal
- **Linear conversation** — one thing at a time
- **Tool calls as cards** — inline, compact, always visible
- **Streaming reasoning** — thinking is shown inline, not collapsed

The tradeoff: less control, less visibility, less sophistication. But the mental model is trivially learnable in 30 seconds.

### 8.2 KosmoKrator's Mental Model

KosmoKrator presents a richer but more complex model:

```
User types → AI thinks → AI uses tools → AI spawns subagents → AI responds
     ↑           │              │                    │              │
     │      Mode selection  Permission          Dashboard       │
     │      (Edit/Plan/Ask) (Guardian/       (Ctrl+A)           │
     │                      Argus/                              │
     │                      Prometheus)                          │
     │                                                           │
     └─────────── Shift+Tab ──── 46 commands ──── Scroll ───────┘
```

Key characteristics:
- **Three modes** — Edit/Plan/Ask with different tool sets
- **Three permission modes** — Guardian/Argus/Prometheus with different auto-approve rules
- **Subagent tree** — parallel agents with dependencies
- **Non-linear execution** — background subagents, concurrent operations
- **Tool calls as widgets** — rich display with collapse/expand
- **Collapsed reasoning** — chain-of-thought hidden by default

### 8.3 Cognitive Load Comparison

| Dimension | Claude Code | KosmoKrator | Delta |
|-----------|-------------|-------------|-------|
| Concepts to learn | 1 (chat) | 6+ (modes, permissions, subagents, commands, phases, settings) | **5× more** |
| First-turn decisions | 0 (just type) | 2 (choose mode, handle first permission) | **Infinite % more** |
| Status indicators | 1 (model name) | 5+ (mode, permission, tokens, cost, phase) | **5× more** |
| Keyboard shortcuts needed | 0 | 3+ (Shift+Tab, Ctrl+A, Ctrl+O) | **Infinite % more** |
| Time to productivity | ~30 seconds | ~5 minutes (estimated) | **10× slower** |

### 8.4 Where KosmoKrator's Complexity Is Justified

The complexity pays off in specific scenarios:
- **Large refactors** — subagent parallelism is genuinely faster
- **Codebase exploration** — Explore agents are more thorough than a single agent
- **Safety-critical changes** — Guardian mode with project boundary checks is more protective
- **Cost tracking** — real-time token/cost visibility prevents bill shock

The problem isn't that these features exist — it's that they're **always visible**, even when the user just wants to ask a simple question.

---

## 9. Recommendations: Aligning TUI with User Mental Model

### 9.1 Progressive Disclosure (Priority: Critical)

The single most impactful change: **don't show all complexity on the first turn.**

**Proposed onboarding gradient:**

| Turn | What's visible | What's hidden |
|------|---------------|---------------|
| 1-3 | Chat input, streaming response, status bar (mode only) | Permission prompts (auto-approve in Guardian), tool call details, subagents |
| 4-10 | Tool call summaries ("3 files read, 1 edited"), collapsible details | Subagent tree, discovery batches |
| 11+ | Full TUI with all widgets | Nothing — power user mode |

Implementation: a `novice_turn_count` setting that progressively reveals widgets. First-time users start in a simplified view that expands as they use the tool.

### 9.2 Mode Clarity (Priority: High)

**Problem**: Mode is a small badge in the status bar.
**Fix**: Make the mode unmissable.

1. **Mode indicator in the prompt area** — not just the status bar. A colored prefix:
   ```
   [Edit] > type your message...
   [Plan] > type your message...
   [Ask]  > type your message...
   ```

2. **Mode confirmation on switch** — when `Shift+Tab` cycles modes, show a brief toast:
   ```
   Switched to Plan mode — read-only, no file changes
   ```

3. **Mode-appropriate hints** — below the input, show context-dependent text:
   - Edit: "Changes will be applied directly"
   - Plan: "Agent will produce a plan without modifying files"
   - Ask: "Agent will research without modifying files or spawning helpers"

4. **Resolve the Plan/Ask overlap** — either merge them or clearly differentiate. Currently, Plan = "read-only but can spawn subagents" and Ask = "read-only and no subagents." This distinction is too subtle. Consider:
   - **Ask**: "Quick answers, no changes" (single agent, read-only)
   - **Plan**: "Deep analysis with detailed plan" (subagents allowed, read-only)
   - **Edit**: "Execute changes" (full access)

### 9.3 Permission System Simplification (Priority: High)

**Problem**: 5 options conflating 3 decision axes.
**Fix**: Restructure into a two-step flow.

**Step 1: Allow or Deny?**
```
┌─ Edit Approval ──────────────────────────────────┐
│  file_edit src/AuthController.php                │
│  - Remove deprecated auth method (lines 45-52)   │
│  + Use new AuthService (lines 45-48)             │
│                                                   │
│  [✓ Allow]  [✗ Deny]  [? Why this change]        │
└───────────────────────────────────────────────────┘
```

**Step 2 (only if Allow): One-time or session?**
```
  Remember this decision?
  [Just this once]  [Always allow file_edit]
```

**Step 3 (only if Deny or repeated approvals): Mode suggestion**
```
  Switch to Prometheus ⚡ to auto-approve all operations?
  [Keep Guardian ◈]  [Switch to Prometheus ⚡]
```

This separates the axes and only shows the mode-switch option when it's contextually relevant.

### 9.4 Subagent Visualization as Activity Feed (Priority: Medium-High)

**Problem**: The agent tree is architecture, not narrative.
**Fix**: Show subagents as a **task list**, not a process tree.

Replace:
```
├─ ● Explore  research-1  · Research auth patterns (42s)
├─ ● General  implement  · Write auth middleware (38s)
└─ ✓ Explore  audit-1    · 1m 12s · 8 tools
```

With:
```
⏵ Working on your request...
  ✓ Explored authentication patterns (1m 12s)
  ⟳ Writing auth middleware (38s)
  ⟳ Researching authorization edge cases (42s)
```

Key changes:
- Lead with the user's goal ("Working on your request"), not system architecture
- Show completed tasks as checkmarks with human descriptions
- Show in-progress tasks with spinners and elapsed time
- Drop agent type labels (Explore/General/Plan) from the default view
- Reserve the tree view for the dashboard (Ctrl+A) where power users expect detail

### 9.5 Narrative Context for Tool Calls (Priority: Medium)

**Problem**: Tool calls are a list of operations, not a story.
**Fix**: Add a one-line narrative summary before each tool batch.

```
─── Reading the authentication flow ───────────────
  ▶ src/AuthController.php
  ▶ src/Middleware/Auth.php
  ▶ grep "auth" in src/

─── Fixing the deprecated auth method ─────────────
  ▶ file_edit src/AuthController.php (3 lines changed)
  
─── Verifying the fix ─────────────────────────────
  $ phpunit --filter=testAuth  ✓ All 4 tests passed
```

This gives the user a mental model of *why* each batch of tools is being used, not just *what* tools are called.

### 9.6 Replace Cosmic Phrases with Context Verbs (Priority: Medium)

**Problem**: "Consulting the Oracle at Delphi" is whimsical but uninformative.
**Fix**: Use the agent's actual reasoning to generate a context verb.

Instead of a random phrase, extract the first action from the LLM's response:
- "Analyzing the authentication flow…"
- "Reading 3 files to understand the middleware…"
- "Planning the refactoring approach…"

Keep the cosmic spinner animation (it's distinctive) but replace the phrase with semantic content. The mythological phrases could be reserved for idle/waiting states, not active work.

### 9.7 Collapsible Reasoning → Visible by Default (Priority: Medium)

**Problem**: Agent reasoning is the most useful context but is hidden by default.
**Fix**: Show the first 2-3 lines of reasoning, collapsed after that.

```
▼ Reasoning
  I need to understand the authentication flow first. Let me read
  the AuthController and the middleware to see how tokens are validated.
  ▸ Show more...
```

This gives the user enough context to understand *why* the next tool calls happen, without overwhelming them with the full chain-of-thought.

### 9.8 Smart Status Bar (Priority: Low-Medium)

**Problem**: Status bar shows everything always, regardless of relevance.
**Fix**: Context-sensitive status bar content.

| State | Status bar shows |
|-------|-----------------|
| Idle, no subagents | `Edit · Guardian · /help for commands` |
| Thinking | `Edit · Thinking… (12s) · 8.2k tokens` |
| Tools running | `Edit · Guardian · Editing AuthController.php · $0.02` |
| Subagents active | `Edit · Guardian · 3 agents · $0.05` |
| Permission prompt | `Edit · Guardian · Approval needed · 12.4k tokens · $0.03` |

The status bar should tell the user what's *most relevant right now*, not everything at all times.

---

## 10. Summary: The Mental Model Gap

```
USER'S EXPECTED MODEL:          KOSMOKRATOR'S ACTUAL MODEL:

  ┌──────────┐                   ┌──────────────────────────────┐
  │  User     │                   │  User                        │
  │  prompt   │                   │  prompt + mode selection     │
  └────┬─────┘                   └──────┬───────────────────────┘
       │                                │
       ▼                                ▼
  ┌──────────┐                   ┌──────────────────────────────┐
  │  AI      │                   │  Agent (with phase model:    │
  │  thinks  │                   │  Thinking → Tools → Idle)    │
  └────┬─────┘                   └──────┬───────────────────────┘
       │                                │
       ▼                                ▼
  ┌──────────┐                   ┌──────────────────────────────┐
  │  AI      │                   │  Permission system           │
  │  acts    │                   │  (Guardian/Argus/Prometheus) │
  └────┬─────┘                   │  + evaluation chain          │
       │                         └──────┬───────────────────────┘
       ▼                                │
  ┌──────────┐                          ▼
  │  AI      │                   ┌──────────────────────────────┐
  │  responds│                   │  Subagent orchestration      │
  └──────────┘                   │  (types, deps, concurrency)  │
                                 └──────┬───────────────────────┘
                                        │
                                        ▼
                                 ┌──────────────────────────────┐
                                 │  Tool execution              │
                                 │  (batched, collapsed, rich)  │
                                 └──────┬───────────────────────┘
                                        │
                                        ▼
                                 ┌──────────────────────────────┐
                                 │  Agent response              │
                                 │  (streaming Markdown)        │
                                 └──────────────────────────────┘
```

The user expects a **3-step model** (think → act → respond). KosmoKrator has a **6-step model** (mode → think → permission → subagent → tools → respond). The TUI needs to **collapse the middle steps** for new users while keeping them accessible for power users.

---

## 11. Prioritized Action Items

| # | Action | Impact | Effort | Priority |
|---|--------|--------|--------|----------|
| 1 | Progressive disclosure: hide complexity for first 3 turns | Very High | Medium | **P0** |
| 2 | Mode indicator in prompt area (not just status bar) | High | Low | **P0** |
| 3 | Permission prompt restructuring (two-step flow) | High | Medium | **P1** |
| 4 | Context verbs replace cosmic phrases | High | Low | **P1** |
| 5 | Subagent visualization as task list | Medium-High | Medium | **P1** |
| 6 | Narrative tool call headers | Medium | Medium | **P2** |
| 7 | Reasoning visible by default (2-3 lines) | Medium | Low | **P2** |
| 8 | Smart status bar (context-sensitive) | Medium | Low | **P2** |
| 9 | First-run onboarding (skip animation, show hints) | High | Medium | **P1** |
| 10 | `/help` overlay with keybindings | Medium | Low | **P2** |

---

*End of UX-17: Mental Model Alignment Audit*

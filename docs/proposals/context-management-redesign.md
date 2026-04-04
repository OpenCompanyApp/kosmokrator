# Context Management Redesign

> Status: Proposal. This document expands the current context-management roadmap using patterns observed in `tmp/codex`, `tmp/claude-src`, `tmp/oh-my-claudecode`, and `tmp/opencode`.

This is a forward-looking design document. It describes improvements beyond the current shipped pipeline and includes both recommended changes and optional experiments.

## Why This Exists

KosmoKrator already ships a layered context pipeline:

- output truncation
- tool-result deduplication
- pruning
- LLM compaction
- oldest-turn trimming fallback

That baseline works, but it still has structural weaknesses:

- compaction boundaries are computed independently in multiple places
- subagents use a weaker context policy than the main agent
- token budgeting is coarse
- compaction produces a flat summary but does not preserve protected operating context as a first-class structure
- persistent memories exist, but recall remains fairly primitive

The projects under `tmp/` show several stronger patterns:

- explicit replacement-history compaction instead of summary-only compaction
- effective-context budgeting with reserved output headroom
- lightweight micro-pruning before expensive compaction
- tiered memory and selective recall
- transcript/session recall outside the live prompt
- stronger subagent-specific overflow handling
- better observability and failure guards

## Scope

This document covers all major ideas surfaced during the comparative review, not only the immediately recommended ones:

1. unified compaction planning and replacement history
2. effective-context budgeting
3. protected context reinjection after compaction
4. micro-pruning before full compaction
5. truncation storage for oversized tool outputs
6. tiered persistent memory
7. selective memory recall
8. session-history recall/search
9. subagent-specific context policy
10. failure guards and circuit breakers
11. context-health observability
12. optional advanced heuristics and experiments

## Current-State Problems

### 1. Boundary Drift

Compaction currently decides what to replace in more than one place:

- `src/Agent/ContextCompactor.php`
- `src/Agent/ConversationHistory.php`
- `src/Session/SessionManager.php`

This means in-memory replacement and persisted compaction can diverge if the rules change in one place but not another.

### 2. Headless/Subagent Degradation

The main interactive flow can compact. Headless flows only trim oldest turns. Subagents therefore have the least durable context policy even though they often do the most tool-heavy work.

### 3. Coarse Token Estimation

Current estimation is based on a flat character heuristic. That is good enough for rough checks but too weak for accurate budgeting around:

- large tool outputs
- JSON-heavy tool calls
- code vs prose
- reserved output tokens
- model switches to smaller windows

### 4. Flat Summary Replacement

Compaction currently replaces old context with a single summary message. It does not explicitly preserve:

- active mode
- current task tree
- current environment snapshot
- current parent brief for subagents
- any protected operator directives

These may survive in practice, but they are not guaranteed.

### 5. Memory Exists, Recall Is Underspecified

KosmoKrator can persist memories, including memories derived from compaction summaries, but it does not yet separate memory classes cleanly or use a bounded relevance-selection flow.

## External Patterns Worth Borrowing

### Codex

Observed in `tmp/codex/codex-rs`:

- compaction creates explicit replacement history, not only a summary
- protected initial context can be re-injected around compaction
- compaction can trim oldest items during the compaction attempt itself if the compaction prompt overflows
- context limits are based on model metadata, not one global rule

### Claude Code

Observed in `tmp/claude-src`:

- effective context window reserves output headroom
- warning, error, auto-compact, and blocking thresholds are distinct
- microcompact removes low-value tool payloads before full compaction
- memory recall scans cheap headers first and then selects top relevant files
- repeated auto-compact failure uses circuit breakers
- context-management health is surfaced to the user

### oh-my-claudecode

Observed in `tmp/oh-my-claudecode`:

- notepad tiers: always-loaded context, working memory, and manual memory
- pre-compact reinjection of small project memory and directives
- session-history search over summaries and transcripts
- pending context injection queues for one-shot reinsertion

### OpenCode

Observed in `tmp/opencode`:

- prune before full compaction
- full oversized tool output can be written to disk while only a preview remains inline
- compaction can replay a user turn in overflow scenarios
- plugin hooks can augment compaction prompts

## Goals

### Primary Goals

- preserve continuity through long sessions without silent context loss
- make compaction deterministic and persistence-safe
- reduce unnecessary full compactions
- keep subagents viable in long-running trees
- improve cross-session recall without bloating the live prompt

### Secondary Goals

- improve user visibility into context health
- make behavior tunable per model
- leave room for experiments without destabilizing the core agent loop

### Non-Goals

- perfect token accounting matching provider internals exactly
- replacing live conversation with an external database-first retrieval system
- introducing a vector database or heavy semantic indexing in the first pass

## Proposed Architecture

### 1. Unified Compaction Plan

Introduce a first-class `CompactionPlan` or `CompactionResult` object. Instead of each layer recomputing boundaries, one planner computes the exact replacement once and all consumers use that result.

Suggested shape:

```php
final class CompactionPlan
{
    public function __construct(
        public readonly int $keepFromMessageIndex,
        public readonly array $keptMessageIds,
        public readonly array $compactedMessageIds,
        public readonly string $summary,
        public readonly array $replacementMessages,
        public readonly array $extractedMemories,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly array $stats,
    ) {}
}
```

Responsibilities:

- `ContextCompactor` computes the plan
- `ConversationHistory` applies `replacementMessages`
- `SessionManager` persists the exact `compactedMessageIds` and summary from the plan
- observability reads `stats` instead of re-deriving them

Benefits:

- removes duplicated boundary logic
- makes compaction persistence-safe
- allows richer replacement than a single summary message
- makes testing easier

### 2. Replacement History, Not Only Summary

Compaction should produce a replacement history that may contain:

- one summary system message
- one protected reinjection block
- optionally one compact memory block
- then the recent untouched turns

Instead of:

```text
[summary]
[recent turns]
```

Prefer:

```text
[protected operating context]
[summary of compacted history]
[selected recalled memory or pending brief]
[recent turns]
```

This follows the stronger Codex pattern and reduces accidental instruction loss.

### 3. Effective Context Budgeting

Replace a single percent-of-window rule with a richer model.

Suggested per-model configuration:

```yaml
agent:
  context:
    reserve_output_tokens: 16000
    warning_buffer_tokens: 24000
    auto_compact_buffer_tokens: 12000
    blocking_buffer_tokens: 3000
    auto_compact_enabled: true
```

Derived values:

- `effective_context_window = model_context_window - reserve_output_tokens`
- `warning_threshold = effective_context_window - warning_buffer_tokens`
- `auto_compact_threshold = effective_context_window - auto_compact_buffer_tokens`
- `blocking_threshold = effective_context_window - blocking_buffer_tokens`

Expected behavior:

- warning state before auto-compact
- proactive micro-prune before full compaction
- hard-stop or forced emergency compaction near blocking
- recompute thresholds when switching models

### 4. Improved Token Estimation

Token estimation does not need to be exact, but it should be more structured.

Suggested improvements:

- separate estimation for prose, code, JSON, tool calls, and tool results
- conservative padding factor on rough estimates
- count system prompt, task tree, environment context, and injected memories explicitly
- track recent observed prompt-token deltas from provider responses and use them to calibrate future estimates

Optional extension:

- maintain lightweight rolling correction factors per provider/model pair

### 5. Protected Context Reinjection

After compaction, re-inject a small protected block that does not depend on the summary prompt remembering everything.

Candidate contents:

- current agent mode
- current cwd and repo root
- current branch if available
- active task tree
- current user constraints and instructions that must survive
- current parent brief for subagents
- current permission mode

This block should be small, normalized, and rebuilt from runtime state rather than conversation text.

Suggested class:

```php
final class ProtectedContextBuilder
{
    public function buildMainAgentContext(...): array;
    public function buildSubagentContext(...): array;
}
```

### 6. Micro-Pruning Before Full Compaction

Add a cheap, deterministic pass before LLM compaction.

Micro-prune targets:

- old tool results
- old media/document payloads
- stale repeated file reads
- superseded grep/glob/search output
- tool results already represented by newer richer reads

Progression:

1. deduplicate
2. supersede stale reads
3. prune old low-value tool outputs
4. if still near limit, compact
5. if compaction fails, emergency trim or replay strategy

This should be available in both interactive and headless flows.

### 7. Progressive Tool Result Replacement

Do not use only one placeholder shape. Use multiple progressively richer replacement formats depending on policy:

- cleared:
  `[Old tool result content cleared]`
- superseded:
  `[Superseded by later file_read of /path/Foo.php]`
- structural summary:
  `[file_read /src/Foo.php, 245 lines, class Foo with methods bar() and baz()]`
- truncation pointer:
  `[Full output saved to .kosmokrator/truncation/tool_123; preview kept inline]`

This preserves more semantic value than a uniform tombstone string.

### 8. Truncation Storage for Oversized Outputs

When a tool result is too large:

- keep a bounded inline preview
- save the full payload to a local truncation store
- inject a pointer and usage hint
- let the agent or subagent inspect slices later using targeted reads/search

Potential local storage:

```text
.kosmokrator/truncation/
```

Benefits:

- keeps the live prompt compact
- preserves recoverability
- works well with grep/read-offset tools
- reduces pressure to keep huge shell and file-read output in memory

### 9. Tiered Memory Model

Split memory into three classes.

#### Priority Context

Always loaded. Very small. High-confidence durable constraints.

Examples:

- repository-specific invariants
- critical user workflow preferences
- known project hazards

#### Working Memory

Session-local or short-lived notes. Auto-pruned by age or staleness.

Examples:

- current investigation state
- active hypotheses
- recent but not durable discoveries

#### Durable Memory

Cross-session project, user, and decision memories.

Examples:

- architecture facts not obvious from code
- repeated user preferences
- prior technical decisions and rationale

This is a stronger replacement for a single undifferentiated memory bucket.

### 10. Selective Memory Recall

Do not inject all memories. Add a bounded relevance-selection step.

Flow:

1. scan memory metadata cheaply
2. exclude already surfaced memories
3. exclude noisy reference material for tools already active
4. select top `K` memories for the current task
5. inject only short rendered snippets

Implementation options:

- start with SQLite metadata scan plus heuristic ranking
- optionally use a lightweight side-query model later

Heuristic ranking signals:

- memory type weight
- keyword overlap with user request and task tree
- recency or freshness
- prior usefulness
- explicit user pinning

### 11. Session-History Recall/Search

Move older context recovery out of the live prompt and into targeted recall.

Capabilities:

- search prior session titles
- search compaction summaries
- search prior full transcripts for the same project
- search prior subagent summaries

This supports:

- resuming interrupted work
- recovering prior decisions without keeping them resident
- starting a fresh thread with good recall

Potential user-facing features:

- `/recall <query>`
- `/sessions search <query>`
- automatic recall suggestions on `/resume`

### 12. Subagent-Specific Context Policy

Subagents should not share the exact same thresholds as the main agent.

Subagent policy should include:

- smaller effective context windows
- aggressive micro-pruning
- protected parent brief injected as a compact block
- compact-or-prune behavior in headless mode, not trim-only
- circuit breaker on repeated compaction failures

Suggested rule of thumb:

- main agent optimizes for continuity and broad recall
- subagents optimize for narrow task focus and fast turnover

### 13. Failure Guards and Circuit Breakers

Repeated auto-compaction failure should not thrash the model or the UI.

Track:

- consecutive compaction failures
- consecutive context-overflow errors
- emergency trims performed
- last successful compaction point

Suggested behavior:

- first failure: retry with more aggressive micro-prune
- second failure: compact with a smaller protected set
- third failure: enter circuit-breaker mode and stop automatic retries for a period
- expose the state to the user

### 14. Compaction Prompt Extensibility

Allow the compaction prompt to be augmented by internal providers or future plugin hooks.

Possible uses:

- domain-specific file summaries
- language-aware structural extraction
- project-specific compaction hints
- excluding noisy tool families

This should be optional. The base compaction path must remain stable without external hooks.

### 15. Replay-Aware Overflow Recovery

When overflow is severe, consider replaying the current user turn against a freshly compacted history instead of repeatedly trimming the live thread.

Use carefully:

- useful when the latest turn is the important one
- dangerous if it hides prior context loss

This is an optional advanced path, not a first-pass requirement.

### 16. Background Consolidation

Add a low-priority background process that periodically consolidates working memory into durable memory or small priority notes.

Triggers may include:

- idle time
- session count
- elapsed wall time
- after successful compaction

Guardrails:

- lock to avoid concurrent consolidators
- strict size budgets
- skip while the main loop is under active context pressure

### 17. Context-Health Observability

Expose context health explicitly in the UI and logs.

Metrics to surface:

- estimated prompt usage
- effective context window
- warning and compact thresholds
- tokens saved by dedup, prune, and compaction
- last compaction summary length
- consecutive compaction failures
- whether protected reinjection was applied
- memory items injected this turn

Possible surfaces:

- status bar
- `/context` or `/debug context`
- log events
- subagent dashboard integration

## Optional Advanced Heuristics

These are valuable, but should remain experimental until the deterministic foundation is stable.

### 1. Semantic Importance Scoring

Score tool results by importance and prune the lowest-value outputs first.

Signals:

- reference density
- decision influence
- tool-type weight
- downstream dependency

This idea already exists in `docs/proposals/context-management-strategies.md` and remains compatible with this redesign.

### 2. Sliding Context Tiers

Apply different fidelity rules by age:

- last 2 turns: full fidelity
- turns 3 to 5: summarized tool results
- turns 6+: cleared or superseded outputs

This gives smoother degradation than a single hard compaction boundary.

### 3. File Content Cache

Cache file reads by `(path, mtime)` and replace repeated large reads with references rather than full content.

### 4. Session Branching

Let the user fork a long session into a fresh thread seeded by summary plus protected context.

### 5. Model-Switch Compaction

When switching to a smaller-window model, proactively compact before the next turn rather than waiting for an overflow condition.

## Proposed Components

### New or Expanded Runtime Components

- `ContextBudget`
  - computes effective windows and thresholds
- `CompactionPlanner`
  - computes one `CompactionPlan`
- `ProtectedContextBuilder`
  - builds non-conversational protected context blocks
- `MicroPruner`
  - cheap deterministic context reduction
- `TruncationStore`
  - persists oversized outputs for later targeted inspection
- `MemorySelector`
  - bounded recall over stored memories
- `SessionRecall`
  - search interface over summaries and transcript metadata
- `ContextTelemetry`
  - status and observability layer

### Existing Components To Refactor

- `ContextManager`
  - orchestrates threshold checks and policy decisions
- `ContextCompactor`
  - becomes planner plus summarizer instead of summary-only helper
- `ConversationHistory`
  - applies replacement history from a plan instead of recomputing a boundary
- `SessionManager`
  - persists plan outputs directly
- `TokenEstimator`
  - upgraded or wrapped by `ContextBudget`
- `SubagentFactory`
  - provides headless agents with a real context policy

## Data Model Changes

Potential persistence additions:

- compaction records table or extended message metadata:
  - compacted message ids
  - summary text
  - saved tokens
  - failure count
  - protected-context metadata
- memory metadata:
  - class: `priority`, `working`, `durable`
  - pinned flag
  - last surfaced time
  - freshness score
- truncation store metadata:
  - path
  - source tool
  - byte size
  - retention expiry

## Suggested Rollout Phases

### Phase 1: Deterministic Foundation

- unify compaction planning
- remove duplicated boundary logic
- add effective-context budgeting
- expose context-health metrics internally

### Phase 2: Cheap Context Wins

- strengthen micro-pruning
- add richer supersede placeholders
- add truncation storage
- enable better headless/subagent policy

### Phase 3: Continuity and Recall

- protected context reinjection
- tiered memory model
- selective memory recall
- session-history recall/search

### Phase 4: Advanced Behaviors

- circuit breakers
- replay-aware overflow recovery
- background consolidation
- prompt hooks
- semantic importance scoring
- sliding context tiers

## Tradeoffs

### Benefits

- more reliable long-session continuity
- lower chance of drift between in-memory and persisted state
- fewer unnecessary LLM compaction calls
- better subagent stability
- better cross-session recall

### Costs

- more moving parts in the agent loop
- more metadata to persist and test
- more policy complexity per model and per agent type
- more UI/state concepts for debugging

### Main Risk

The main risk is overengineering before the deterministic base is fixed. The correct order is:

1. unify compaction planning
2. improve budgeting and pruning
3. add reinjection and recall
4. add heuristic and background systems

## Recommended First Implementation Slice

Even though this document covers the full idea set, the best first slice is still:

1. `CompactionPlan` as the single source of truth
2. effective-context budgeting
3. micro-prune in both main and headless flows
4. protected context reinjection

That sequence improves correctness first and opens the door for the rest.

## Relationship to Existing Docs

- `docs/architecture/overview.md` remains the current-state document
- `docs/proposals/context-compaction.md` is a historical snapshot of the first compaction design
- `docs/proposals/context-management-strategies.md` remains a useful experimental appendix for heuristics like semantic importance scoring and sliding tiers

This document is intended to become the main future-state reference for context-management redesign work.

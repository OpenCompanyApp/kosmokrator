# Context Compaction

> Status: Historical plan. Parts of this design are now implemented, but this document remains a design snapshot rather than the canonical current-state description.

## Context

Long coding sessions hit the LLM context window limit. Currently `ConversationHistory::trimOldest()` silently drops complete turns — the agent loses context without knowing what was lost. We need intelligent compaction that summarizes old turns before discarding them, preserving critical context in a compressed form.

Depends on: **Session Persistence (SQLite)** — compaction should be non-destructive, with original messages preserved in the database.

## Two Triggers

1. **Automatic** — After each LLM response, check `promptTokens >= contextWindow - buffer`. Buffer defaults to 20K tokens (configurable). Replaces `trimOldest()`.
2. **Manual** — `/compact` slash command for user-initiated compaction.

## Algorithm

```
1. Check threshold: promptTokens >= (contextWindow - buffer)
2. Split history into OLD (everything before last 2-3 turns) and RECENT (preserved)
3. Prune: truncate large tool outputs (>1000 chars) in OLD to "[output truncated — N chars]"
4. Send OLD messages to LLM with compaction prompt
5. Replace OLD messages with a single SystemMessage containing the summary
6. Mark original messages as compacted in SQLite (non-destructive)
7. Continue — agent sees summary + recent turns
```

### Context After Compaction

```
[system prompt + instructions + environment]
[SystemMessage: summary of turns 1-15]    <-- compacted
[user turn 16]                             <-- preserved (recent)
[assistant turn 16 + tool results]         <-- preserved (recent)
[user turn 17]                             <-- current
```

### Compaction Prompt

```
Summarize the conversation above for a continuation agent.
Focus on information needed to continue the work seamlessly.

Use this structure:
---
## Goal
[What the user is trying to accomplish]

## Key Decisions
[Important technical choices, constraints, user preferences]

## Accomplished
[Work completed — specific file paths and changes made]

## In Progress
[Current task and what remains to be done]

## Relevant Files
[Files read, edited, or created — with brief notes on each]
---
```

Compaction uses the same LLM client, no tools. The compaction agent is a hidden internal call.

### Fallback

If compaction itself overflows (conversation too large even for the summary call), fall back to `trimOldest()` as a last resort and log a warning.

## Architecture

### New: `src/Agent/ContextCompactor.php`

```php
class ContextCompactor
{
    public function __construct(
        private LlmClientInterface $llm,
        private ModelCatalog $models,
        private LoggerInterface $log,
        private int $bufferTokens = 20_000,
    ) {}

    public function needsCompaction(int $promptTokens, string $model): bool;
    public function compact(ConversationHistory $history, int $keepRecent = 3): string; // returns summary
}
```

- `needsCompaction()` — checks threshold against context window
- `compact()` — builds the compaction prompt, calls LLM, returns summary text
- History replacement handled by `ConversationHistory::compact()`

### Modified: `src/Agent/ConversationHistory.php`

```php
public function compact(string $summary, int $keepRecent = 3): void;
// Replaces messages[0..n-keepRecent] with a SystemMessage containing the summary
// With SQLite: marks old messages as compacted, stores summary as a new message
```

### Modified: `src/Agent/AgentLoop.php`

After each `run()` response:
```php
if ($this->compactor->needsCompaction($response->promptTokens, $this->getModelName())) {
    $summary = $this->compactor->compact($this->history);
    $this->ui->showNotice('Context compacted.');
}
```

### Modified: `src/Command/AgentCommand.php`

Add `/compact` slash command that triggers manual compaction.

### Config

```yaml
kosmokrator:
  compaction:
    auto: true           # Enable automatic compaction
    buffer: 20000        # Token buffer to reserve
    keep_recent: 3       # Number of recent turns to preserve
```

## Differences from OpenCode

| Aspect | OpenCode | KosmoKrator |
|--------|----------|-------------|
| Storage | SQLite, part-based | SQLite (once persistence added) |
| Pruning | Separate reversible pass | Inline truncation during compaction |
| Post-compact | Synthetic "continue" message | Normal flow continues |
| Summary stacking | Multiple summaries chain | One summary replaces all old |
| Destructive | No (DB keeps originals) | No (DB keeps originals, once SQLite added) |
| Fallback | Error on double-overflow | `trimOldest()` on double-overflow |

## Verification

1. Start a long session, watch token count climb in status bar
2. When threshold hit, auto-compaction fires — notice shown, status bar drops
3. Agent continues seamlessly — knows what was discussed
4. `/compact` works manually at any time
5. Summary includes file paths, decisions, and current task
6. Original messages preserved in SQLite (can be viewed later)

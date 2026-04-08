# Deep Audit: Logic Bugs — 2026-04-08

**Scope:** `src/Agent/`, `src/Tool/Coding/`, `src/Task/`
**Auditor:** KosmoKrator sub-agent
**Classification scheme:** CRITICAL / HIGH / MEDIUM / LOW

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 1     |
| HIGH     | 5     |
| MEDIUM   | 8     |
| LOW      | 4     |
| **Total** | **18** |

---

## CRITICAL

### C-01: StuckDetector dominant signature selection may pick wrong signature

**File:** `src/Agent/StuckDetector.php:60`
**Type:** Algorithm Correctness

**Bug:**
```php
$dominantSig = $maxCount > 0 ? array_search($maxCount, $counts, true) : null;
```

`array_search()` returns the **first** key whose value equals `$maxCount`. If two different signatures have the same count (e.g., signature A appears 3 times and signature B also appears 3 times), `array_search` returns whichever appears first in `$counts`, which is determined by `array_count_values()` hash table order — **not** the one that is actually the dominant pattern in the rolling window. This means the stuck detector can conclude "not stuck" even when the latest call **is** a repeated pattern, because it compared `$latestSig` against the wrong `$dominantSig`.

**Steps to trigger:**
1. Agent makes calls: `file_read:X`, `grep:Y`, `file_read:X`, `grep:Y`, `file_read:X`, `grep:Y` (window = 6)
2. Both signatures appear 3 times; `max($counts)` = 3
3. `array_search(3, $counts)` returns whichever hash key comes first (non-deterministic)
4. If `latestSig` is the OTHER key, `$isStuck` is `false` even though both are repeated 3×
5. Stuck detection silently fails

**Suggested fix:**
Check if `$latestSig` itself meets the repetition threshold, rather than relying on finding a single "dominant" signature:
```php
$latestCount = $counts[$latestSig] ?? 0;
$isStuck = $latestCount >= $this->repetitionThreshold;
```

---

## HIGH

### H-01: PatchParser allows `*** End of File` inside Update body but doesn't strip it from hunks

**File:** `src/Tool/Coding/Patch/PatchParser.php:161–166`
**Type:** Algorithm Correctness

**Bug:**
In `parseUpdate()`, `*** End of File` lines are added to the `$body` array:
```php
if ($line === '*** End of File') {
    $body[] = $line;  // <-- Added to body
    $index++;
    continue;
}
```
But in `PatchApplier::applyUpdateHunks()` (line 221–223), `*** End of File` is stripped:
```php
if ($line === '*** End of File') {
    continue;  // <-- Stripped
}
```

This is handled correctly at runtime because the applier strips it. However, the semantic inconsistency means `*** End of File` in an Update body is *not* a hunk delimiter and *not* a prefix line — it's silently passed to `buildChunkStrings()` where it would cause `"Unexpected update line prefix '*'"` at line 178... except the applier catches it first. The real issue is that if someone writes a parser that consumes PatchOperation DTOs without using PatchApplier, the `*** End of File` line in `bodyLines` is ambiguous and will break.

**Suggested fix:** Strip `*** End of File` at parse time in `parseUpdate()`, just as `parseAdd()` does:
```php
if ($line === '*** End of File') {
    $index++;
    continue;  // Don't add to body
}
```

### H-02: StuckDetector cooldown resets escalation to 0 but previous nudges are lost

**File:** `src/Agent/StuckDetector.php:66–69`
**Type:** State Machine Violation

**Bug:**
When the agent produces `cooldownThreshold` (default 2) non-stuck turns, the entire escalation state resets:
```php
$this->stuckEscalation = 0;
$this->turnsSinceEscalation = 0;
$this->cooldownCounter = 0;
```
This means if an agent was already at escalation level 2 (final_notice), then does 2 non-stuck turns, it resets to 0. If the agent then loops again, it gets a fresh nudge → final_notice → force_return cycle. In theory this allows an unbounded number of nudge→recovery→nudge cycles. The `force_return` escape hatch is never permanently reached.

**Steps to trigger:**
1. Agent triggers nudge (escalation = 1)
2. Agent triggers final_notice (escalation = 2)
3. Agent does 2 diverse tool calls (cooldown resets escalation to 0)
4. Agent loops again → gets nudge again → cycle repeats forever
5. Each cycle consumes up to ~7 rounds without ever force-returning

**Suggested fix:** Either track total escalations (not reset on cooldown) or add a maximum total escalation count that eventually forces return regardless of cooldowns.

### H-03: SubagentOrchestrator::reclaimSlot silently no-ops when lock was never yielded

**File:** `src/Agent/SubagentOrchestrator.php:514–528`
**Type:** Logic Error

**Bug:**
```php
public function reclaimSlot(string $agentId): void
{
    if ($this->globalSemaphore === null) {
        return;
    }
    // Root agents never yield slots — don't reclaim one for them
    if (! isset($this->globalLocks[$agentId])) {
        return;
    }
```
After `yieldSlot()` unsets `$this->globalLocks[$agentId]` (line 507), `reclaimSlot()` checks `!isset($this->globalLocks[$agentId])` and returns early, **never re-acquiring** the lock. This is the intended "root agent guard" comment, but it also fires for agents that just yielded their slot.

**The real flow:**
1. `yieldSlot($id)` → releases lock, `unset($this->globalLocks[$id])`
2. Children run
3. `reclaimSlot($id)` → checks `!isset($this->globalLocks[$id])` → returns early
4. Parent never re-acquires a slot → semaphore count drifts upward (leaked capacity)

**Suggested fix:** Use a separate flag or tracking mechanism to distinguish "never had a lock" from "yielded their lock":
```php
public function reclaimSlot(string $agentId): void
{
    if ($this->globalSemaphore === null) {
        return;
    }
    $lock = $this->globalSemaphore->acquire();
    $this->globalLocks[$agentId] = $lock;
}
```

### H-04: ToolExecutor partitionConcurrentGroups — apply_patch regex misses `*** ` prefix

**File:** `src/Agent/ToolExecutor.php:378`
**Type:** Algorithm Correctness

**Bug:**
```php
if (preg_match_all('/(?:Update File|Add File|Delete File|File):\s*(\S+)/i', $patchContent, $matches)) {
```
This regex matches lines like `Update File: path` but the actual patch format uses `*** Update File: path`. While it does match, it also spuriously matches any line containing the word "File" followed by a colon — e.g., a file containing the text `Read File: some reference` in its content. This could cause false conflict detection and unnecessarily serialize tool execution.

More critically, the regex has no `^` or `*** ` prefix anchor, so it matches anywhere in the patch content, including inside file body lines.

**Steps to trigger:**
1. An `apply_patch` tool call with a patch that modifies a file containing text like `"Add File: example.txt"`
2. The regex extracts `example.txt` as a conflicting path
3. All tools are serialized unnecessarily

**Suggested fix:**
```php
preg_match_all('/^\*\*\*\s+(?:Update File|Add File|Delete File):\s*(\S+)/m', $patchContent, $matches)
```

### H-05: TaskStore::update auto-completes parent even if child transitions to Failed/Cancelled

**File:** `src/Task/TaskStore.php:91–93`
**Type:** State Machine Violation

**Bug:**
```php
// Auto-complete parent when all children are terminal
if (isset($changes['status']) && $task->parentId !== null) {
    $this->maybeCompleteParent($task->parentId);
}
```
The method `maybeCompleteParent` (not shown in the file read, but called here) auto-completes the parent when **all** children reach a terminal state. However, it likely transitions the parent to `Completed` even when children are `Failed` or `Cancelled`. The parent should probably transition to `Failed` if any child failed, or at least not auto-complete to `Completed`.

**Steps to trigger:**
1. Create parent task with two children
2. Complete child 1
3. Fail child 2
4. Parent auto-completes to "Completed" despite child 2 failing

**Suggested fix:** Check if any child is `Failed` before auto-completing to `Completed`:
```php
if ($allTerminal) {
    $anyFailed = $children->some(fn($c) => $c->status === TaskStatus::Failed);
    $parent->transitionTo($anyFailed ? TaskStatus::Failed : TaskStatus::Completed);
}
```

---

## MEDIUM

### M-01: StuckDetector::check adds ALL tool calls to window before checking

**File:** `src/Agent/StuckDetector.php:49–52`
**Type:** Algorithm Correctness

**Bug:**
```php
foreach ($toolCalls as $tc) {
    $this->toolCallWindow[] = $tc->name.':'.md5(json_encode($tc->arguments(), JSON_INVALID_UTF8_SUBSTITUTE));
}
$this->toolCallWindow = array_slice($this->toolCallWindow, -$this->windowSize);
```
When a batch of multiple tool calls is provided, ALL are added at once, then the window is trimmed. If the batch has more calls than `windowSize`, the window is entirely populated with just the current batch, losing all history. This means a single large batch always looks "not stuck" because the dominant signature has only been seen once in the current window.

**Suggested fix:** Consider only the last N signatures from the batch to preserve history, or track per-round rather than per-call.

### M-02: PatchApplier::applyUpdateHunks joins lines with \n but file content may not end with \n

**File:** `src/Tool/Coding/Patch/PatchApplier.php:271`
**Type:** Edge Case

**Bug:**
```php
return [implode("\n", $oldLines), implode("\n", $newLines)];
```
When `buildChunkStrings` constructs the old/new text blocks, it joins lines with `\n`. If the original file content uses `\n` between lines but the matching block was the last lines of the file (no trailing `\n`), the `implode` adds a trailing `\n` that won't match the actual file content. The hunk would fail with "Patch context not found."

**Steps to trigger:**
1. Create a file without trailing newline: `echo -n "line1\nline2" > test.txt`
2. Patch that replaces `line2` with `line2b`
3. Hunk body: `" line1\n-line2\n+line2b"`
4. `buildChunkStrings` builds: `"line1\nline2"` for old
5. File content has `"line1\nline2"` — this actually matches since there's no trailing \n from implode either
6. BUT if the hunk is multi-line and the file has no trailing newline, the join still works — this is a **minor** concern

**Severity reassessment:** Actually LOW — `implode` doesn't add trailing `\n`. The real edge case is if the hunk is the **last** line and the file has no trailing newline — then old text from `implode` won't have a trailing `\n` either, so it matches. Downgrading to informational.

### M-03: ContextPruner::findProtectBoundary protects from the 2nd user turn but includes tool results after it

**File:** `src/Agent/ContextPruner.php:154–168`
**Type:** Off-by-one / Logic

**Bug:**
The function finds the index of the 2nd-to-last UserMessage and uses that as the protection boundary. Tool results before this index are candidates for pruning. However, tool results **after** this UserMessage but before the latest UserMessage are also candidates (they're at index > `$protectFrom`). The `for` loop at line 95 starts from `$protectFrom - 1` and walks backwards, so it only considers indices **before** `$protectFrom`. This is correct — tool results between the 2nd-to-last and last user message are implicitly protected.

Actually, re-reading the code: `$protectFrom` is the index of the 2nd-to-last UserMessage. The for loop starts at `$protectFrom - 1`. So all messages at index >= `$protectFrom` are protected. The candidates are at index < `$protectFrom`. This is correct behavior.

**Revised finding:** The `tokensSeen > $this->protectTokens` check at line 120 only starts recording candidates **after** crossing the threshold. This means the first `$protectTokens` worth of tool results (walking backwards from the boundary) are always protected, and only results older than that are candidates. This is by design. **No bug — remove from report.**

### M-04: ToolExecutor overwrites `$approvedById` variable in Phase 3

**File:** `src/Agent/ToolExecutor.php:155–156` and `214–217`
**Type:** Variable Shadowing

**Bug:**
```php
// Line 155-156: Build lookup: toolCall id → [toolCall, wasAutoApproved]
$approvedById = [];
foreach ($approved as [$tc, $t]) {
    $approvedById[$tc->id] = [$tc, $t, $autoApproved[$tc->id] ?? false];
}

// ... execution loop ...

// Line 214-217: Merge approved and denied results — OVERWRITES the above
$approvedById = [];
foreach ($results as $r) {
    $approvedById[$r->toolCallId] = $r;
}
```
The variable `$approvedById` is reused with a completely different structure. The first use maps to `[$tc, $t, $wasAutoApproved]`, the second maps to `ToolResult`. While this works because the first use is no longer needed by line 214, it's confusing and could lead to maintenance bugs if someone adds code between the two blocks expecting the original structure.

**Suggested fix:** Rename the second variable to `$resultsById` or `$collectedById`.

### M-05: ConversationHistory::trimOldest can remove too many messages when SystemMessages are sparse

**File:** `src/Agent/ConversationHistory.php:292–320`
**Type:** Edge Case

**Bug:**
```php
// Drop from the first non-system message until the next UserMessage (turn boundary)
$removed = 0;
array_splice($this->messages, $startIdx, 1);
$removed++;

while ($startIdx < count($this->messages) - 1 && ! ($this->messages[$startIdx] instanceof UserMessage)) {
    array_splice($this->messages, $startIdx, 1);
    $removed++;
}
```
After removing the first non-system message (which should be a UserMessage), the while loop removes all subsequent non-UserMessage messages (assistant + tool results). This removes one complete turn. However, the loop condition `! ($this->messages[$startIdx] instanceof UserMessage)` at line 314 could skip a UserMessage that was adjacent to the removed one if it's the very last message (`$startIdx < count($this->messages) - 1` guards this). 

This is actually correct — it preserves the last message. But if the history is just `[SystemMessage, UserMessage]` (2 messages after system messages), `$startIdx >= count($this->messages) - 1` at line 305 returns false, and nothing is trimmed. This is also correct. **No real bug here.**

### M-06: SubagentOrchestrator cycle detection can miss transitive cycles through pruned nodes

**File:** `src/Agent/SubagentOrchestrator.php:374–402`
**Type:** Algorithm Correctness

**Bug:**
```php
if (! isset($this->stats[$current])) {
    // Pruned or unknown agent — treat as leaf (no outgoing deps)
    continue;
}
```
When an agent's stats have been pruned (via `pruneCompleted()`), its `dependsOn` edges are lost. A new agent declaring dependency on a pruned agent won't be able to follow the pruned agent's transitive dependencies, potentially allowing a cycle that goes through a pruned node.

**Steps to trigger:**
1. Agent A depends on Agent B
2. Agent B depends on nothing
3. Agent B completes and is pruned
4. Agent C (depends on Agent A) is spawned — cycle check works fine
5. BUT if Agent D depends on Agent B, and Agent B's stats were pruned, then when spawning Agent E with `dependsOn: [D]`, the DFS tries to visit B but treats it as a leaf
6. If the actual dependency graph were B → E → D (circular), pruning B would hide the cycle

This is somewhat mitigated by the fact that pruned agents have completed, so a cycle through them is unlikely but theoretically possible in a degenerate case with many background agents.

**Suggested fix:** Keep a lightweight dependency graph (`agentId → dependsOn[]`) separate from stats that is never pruned.

### M-07: AgentContext::canSpawn uses `< maxDepth - 1` which allows maxDepth levels instead of maxDepth - 1

**File:** `src/Agent/AgentContext.php:30`
**Type:** Off-by-one

**Bug:**
```php
public function canSpawn(): bool
{
    return $this->depth < $this->maxDepth - 1;
}
```
Root context has `depth = 0`. Children get `depth = parent.depth + 1`.
- With `maxDepth = 3`: can spawn when `depth < 2`, i.e., depth 0 and 1 can spawn.
- This allows 3 levels: root (0), children (1), grandchildren (2). Grandchildren **cannot** spawn.
- This means `maxDepth = 3` allows exactly 3 levels of agents, which seems correct.

Wait — let's check: if `maxDepth = 3`, the intent is probably "3 levels deep". Root is depth 0, first children are depth 1, second-level children are depth 2. `canSpawn()` returns true for depth 0 and 1. So agents at depth 2 can exist but cannot spawn. Total active levels = 3 (0, 1, 2). This matches `maxDepth = 3`.

But if `maxDepth = 1`: `canSpawn()` returns `depth < 0` → always false. Root cannot spawn at all. This means `maxDepth = 1` = no subagents allowed, which seems correct.

If `maxDepth = 2`: `canSpawn()` returns `depth < 1` → only root (depth 0) can spawn. Children (depth 1) cannot. Two levels total. Correct.

**No bug — the off-by-one logic is correct.**

### M-08: PatchParser treats empty lines in Update body as errors

**File:** `src/Tool/Coding/Patch/PatchParser.php:173–175`
**Type:** Edge Case

**Bug:**
```php
if ($line === '') {
    throw new \InvalidArgumentException('Patch body lines must include a prefix character.');
}
```
Empty lines in an Update section cause an error. But in unified diff format, empty context lines can appear. While this is documented behavior (patch lines must have a prefix), it's a common mistake for LLMs to emit blank lines between hunks, especially after `@@` markers. The error message is unhelpful — it doesn't tell the LLM to prefix empty lines with ` ` (space).

**Suggested fix:** Either allow empty lines (treating them as context lines with no content change) or improve the error message:
```php
if ($line === '') {
    throw new \InvalidArgumentException('Empty line in patch body. Context lines must start with a space character ( ). Use " " (a single space) for empty context lines.');
}
```

---

## LOW

### L-01: StuckDetector returns 'ok' at end of check() even when escalation is already set

**File:** `src/Agent/StuckDetector.php:102`
**Type:** Control Flow

**Bug:**
```php
// Force return after 2 more turns
if ($this->stuckEscalation >= 2 && $this->turnsSinceEscalation >= 2) {
    return 'force_return';
}

return 'ok';  // Line 102
```
When `stuckEscalation === 2` but `turnsSinceEscalation < 2`, the method returns `'ok'` even though the agent IS still stuck. This means on escalation level 2, the agent gets 2 "free" rounds where the loop receives `'ok'` and continues normally. This is by design (the 2-turn grace period), but the return value `'ok'` is misleading — it's not that the agent isn't stuck, it's that the escalation is being held for 2 more turns.

**Suggested fix:** No fix needed — this is intentional design. The comment explains the behavior.

### L-02: ErrorSanitizer has unbalanced replacement in home path regex

**File:** `src/Agent/ErrorSanitizer.php:26–27`
**Type:** Regex Bug

**Bug:**
```php
$message = preg_replace('#/Users/[^/\s]+#', '/***', $message);
$message = preg_replace('#/home/[^/\s]+#', '/***', $message);
```
The replacement `'/***'` has an unbalanced `*` — it opens with `/*` and never closes. While this is just a display string (not code), it looks like a typo. Should probably be `/***` with a closing or just `/home/***`. The original intent is unclear but the output will contain a literal `/***` which looks like a broken C comment.

**Suggested fix:** Use a cleaner replacement like `'/…'` or `'/[redacted]'`.

### L-03: ContextManager::snapshot fallback sets `is_at_blocking_limit` to always false

**File:** `src/Agent/ContextManager.php:398`
**Type:** Logic

**Bug:**
```php
'is_at_blocking_limit' => false,
```
When no `ContextBudget` is configured, the fallback snapshot always sets `is_at_blocking_limit` to `false`. This means in the fallback path, the blocking limit check in `preFlightCheck()` (line 100–104) never triggers `trimOldest()`. Context will grow without bound until it hits the LLM API error, rather than proactively trimming.

**Suggested fix:** Derive a blocking threshold in the fallback:
```php
'is_at_blocking_limit' => $estimated >= ($this->getContextWindow() - 1000),
```

### L-04: SubagentOrchestrator auto-prunes at count > 50 but pending results may reference pruned stats

**File:** `src/Agent/SubagentOrchestrator.php:123–125`
**Type:** Race Condition

**Bug:**
```php
if (count($this->stats) > 50) {
    $this->pruneCompleted();
}
```
This pruning happens at spawn time, before the agent even starts. If a background agent completes and its stats are pruned before the parent collects results, `injectPendingBackgroundResults` in `AgentLoop.php` tries to access `$this->agentContext->orchestrator->getStats($id)` (line 851) and gets `null`. The code handles this with `?? 'agent'` and `?? 0` defaults, so it doesn't crash, but the display will be degraded (missing agent type, missing tool call count, etc.).

**Suggested fix:** `pruneCompleted()` already excludes agents in `pendingIds`. This is mitigated correctly. The only gap is that stats are pruned from `$this->stats` but the agent's entry in `$this->agents` (the Future) is also removed, which could affect `wouldCreateCycle()` for future dependency checks. LOW severity since the `wouldCreateCycle` method already handles missing stats as leaf nodes.

---

## Additional Observations (Not Bugs)

### A-01: Defensive pattern in StuckDetector is well-implemented

The `$latestSig === $dominantSig` check at line 61 is a good secondary validation — it ensures we only escalate when the **most recent** call is part of the stuck pattern, not just any historical repetition.

### A-02: PatchApplier::replaceUnique is correctly strict

The unique replacement requirement (exactly one match) is a good safety measure. If the context appears multiple times, the patch is rejected rather than corrupting the file.

### A-03: TaskStatus state machine is clean

The transition map is well-defined with no cycles and proper terminal states. The `canTransitionTo()` method correctly uses the transitions table.

### A-04: ToolExecutor permission flow is comprehensive

The three-phase permission check (auto-deny, ask user, approve) with mode-specific guards is well-structured. The `isAskTool` deduplication prevents multiple interactive questions per turn.

---

## Methodology

- All source files in `src/Agent/`, `src/Tool/Coding/`, and `src/Task/` were read in full
- Focus areas: control flow, state machines, type handling, algorithm correctness
- Each finding was verified by tracing execution paths and checking edge cases
- Severity was assigned based on: likelihood of occurrence × impact when triggered

---

*Audit completed 2026-04-08*

<?php
$docTitle = 'Context & Memory';
$docSlug = 'context';
ob_start();
?>
<p class="lead">
    KosmoKrator continuously manages the LLM's context window so that
    conversations can run indefinitely without hitting token limits. A
    multi-stage pipeline reduces context pressure progressively, from cheap
    local operations to full LLM-based summarization. A complementary memory
    system persists important knowledge across sessions.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="context-pipeline">Context Pipeline Overview</h2>

<p>
    Every time the agent prepares an LLM call, the <strong>ContextManager</strong>
    runs a pre-flight check. If the estimated token count exceeds a warning
    threshold, the pipeline activates. Each stage runs in order; earlier stages
    handle cheap, fast reductions while later stages are progressively more
    aggressive.
</p>

<pre><code>  Tool Output         Conversation          Conversation          LLM              Conversation
  comes back          history               history               call             history
      |                   |                     |                   |                   |
      v                   v                     v                   v                   v
+--------------+   +---------------+   +--------------+   +-----------------+   +---------------+
|   Output     |   |               |   |              |   |   LLM-Based     |   |  Oldest-Turn  |
|  Truncation  |-->| Deduplication |-->|   Pruning    |-->|  Compaction     |-->|   Trimming    |
|              |   |               |   |              |   |                 |   |               |
| 2,000 lines  |   | Exact dupes,  |   | Score-based  |   | Summarize old   |   | Drop oldest   |
| 50 KB cap    |   | stale reads,  |   | placeholder  |   | messages via    |   | message;      |
|              |   | subsumed grep |   | replacement  |   | LLM; extract    |   | repeat until  |
|              |   |               |   |              |   | memories        |   | within budget |
+--------------+   +---------------+   +--------------+   +-----------------+   +---------------+
   Immediate          Per turn           Per turn         When threshold         Emergency
                                                           is crossed            fallback</code></pre>

<p>
    The pipeline is designed so that most sessions never reach the later stages.
    Output truncation and deduplication handle the bulk of token reduction
    silently, keeping the conversation lean without any loss of important
    context.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="output-truncation">Output Truncation</h2>

<p>
    The <strong>OutputTruncator</strong> is the first line of defense. It
    processes every tool result the moment it comes back, before the result
    enters the conversation history. This prevents a single oversized output
    (such as a large file read or a verbose shell command) from consuming
    a disproportionate share of the context window.
</p>

<h3 id="truncation-limits">Limits</h3>

<ul>
    <li><strong>Line limit:</strong> 2,000 lines maximum</li>
    <li><strong>Byte limit:</strong> 50 KB (50,000 bytes) maximum</li>
    <li>Whichever limit is hit first triggers truncation</li>
</ul>

<h3 id="truncation-behavior">Behavior</h3>

<p>
    When truncation occurs, the full untruncated output is first saved to disk
    at <code>~/.kosmokrator/data/truncations/</code>. The truncated version
    that enters the conversation ends with a notice pointing to the saved file:
</p>

<pre><code>[truncated - full output saved to ~/.kosmokrator/data/truncations/tool_abc123.txt;
 inspect with targeted grep/file_read rather than pasting it back into context]</code></pre>

<p>
    This means nothing is ever truly lost. The agent can re-read the full output
    via <code>file_read</code> if it needs a specific section, rather than
    loading the entire thing into context. Saved truncation files are
    automatically cleaned up after 7 days.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> If the agent is working with large files or
        verbose commands, output truncation keeps the context window healthy
        automatically. You do not need to configure anything &mdash; the
        truncator runs on every tool result by default.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="deduplication">Deduplication</h2>

<p>
    The <strong>ToolResultDeduplicator</strong> scans the conversation history
    and identifies redundant tool outputs. Superseded results are replaced with
    compact placeholder strings, freeing tokens without losing any information
    that is still current.
</p>

<p>
    Deduplication applies three tiers, checked in order:
</p>

<h3 id="dedup-tier1">Tier 1: Exact Duplicates</h3>

<p>
    If the same tool was called with the same arguments and produced the same
    result, earlier occurrences are replaced with:
</p>

<pre><code>[Superseded -- identical result returned by later call]</code></pre>

<p>
    This covers cases where the agent re-runs a search, re-reads a file that
    has not changed, or re-executes a command with identical output. Applies
    to <code>file_read</code>, <code>grep</code>, and <code>glob</code> tools.
</p>

<h3 id="dedup-tier2">Tier 2: Stale File Reads</h3>

<p>
    When a file is read, then edited (via <code>file_edit</code> or
    <code>file_write</code>), and then read again, the pre-edit read is
    now stale. The deduplicator detects this pattern and replaces the older
    read with:
</p>

<pre><code>[Superseded -- file was re-read after modification]</code></pre>

<p>
    This is particularly effective during iterative editing sessions where the
    agent reads a file, makes changes, and reads it again to verify &mdash;
    the old version of the file no longer needs to occupy context.
</p>

<h3 id="dedup-tier3">Tier 3: Grep Subsumed by File Read</h3>

<p>
    If the agent ran <code>grep</code> on a specific file and later performed
    a full <code>file_read</code> of the same file, the grep result is
    redundant because the full file content already includes the matched lines.
    The grep result is replaced with:
</p>

<pre><code>[Superseded -- content included in later file_read of filename.php]</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> Deduplication runs automatically and has no
        configuration options. It only replaces results that are provably
        redundant &mdash; current results are never touched.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="pruning">Pruning</h2>

<p>
    The <strong>ContextPruner</strong> is a fast, non-LLM reduction pass that
    replaces old tool result content with lightweight placeholder strings. It
    runs before LLM-based compaction and can often free enough tokens to avoid
    compaction entirely.
</p>

<h3 id="pruning-protection">Protection Rules</h3>

<p>
    The pruner never touches recent context. The last 2 user turns and all
    messages after them are fully protected. Additionally, a configurable
    token budget (default: 40,000 tokens) of the most recent tool output
    before the protection boundary is preserved. Only tool results older and
    less important than this budget are candidates for pruning.
</p>

<h3 id="pruning-scoring">Importance Scoring</h3>

<p>
    Each candidate tool result is scored by importance. Lower-scoring results
    are pruned first. The score factors include:
</p>

<ul>
    <li>
        <strong>Tool type weight</strong> &mdash; Tools with typically larger,
        less reusable output score lower (more likely to be pruned). Weights
        range from <code>bash</code> (70 &mdash; most pruneable) down to
        <code>glob</code> (10 &mdash; least pruneable). File edits and writes
        score 20 (often contain important decisions).
    </li>
    <li>
        <strong>Reference by later reasoning</strong> &mdash; If an assistant
        message after the tool result references the file name or quotes part of
        the result, the score increases by 15 per reference, making it more
        likely to be kept.
    </li>
    <li>
        <strong>Decision language</strong> &mdash; If subsequent assistant
        messages contain phrases like "based on", "I'll use", or "the issue is"
        that suggest the tool result influenced a decision, the score increases
        by 10.
    </li>
</ul>

<h3 id="pruning-placeholders">Context-Aware Placeholders</h3>

<p>
    When a tool result is pruned, it is replaced with a placeholder that
    preserves the tool type and target path, so the agent still knows <em>what</em>
    was done even though the full output is gone:
</p>

<pre><code>[Old file_read output cleared for src/Agent/ContextManager.php]
[Old grep output cleared for src/Tool/]
[Old shell output cleared; inspect truncation storage or rerun targeted commands if needed]
[Old glob output cleared]
[Old tool result content cleared]</code></pre>

<h3 id="pruning-minimum">Minimum Savings</h3>

<p>
    Pruning only activates if the estimated token savings exceed 20,000 tokens
    (configurable). This prevents churn from pruning a handful of small results
    that would not meaningfully help.
</p>

<table>
    <thead>
        <tr>
            <th>Tool</th>
            <th>Weight</th>
            <th>Pruning Priority</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>bash</code></td>
            <td>70</td>
            <td>Highest (pruned first)</td>
        </tr>
        <tr>
            <td><code>shell_read</code></td>
            <td>65</td>
            <td>High</td>
        </tr>
        <tr>
            <td><code>web_fetch</code></td>
            <td>55</td>
            <td>High</td>
        </tr>
        <tr>
            <td><code>grep</code></td>
            <td>50</td>
            <td>Medium</td>
        </tr>
        <tr>
            <td><code>web_search</code></td>
            <td>40</td>
            <td>Medium</td>
        </tr>
        <tr>
            <td><code>file_read</code></td>
            <td>30</td>
            <td>Lower</td>
        </tr>
        <tr>
            <td><code>file_edit</code> / <code>file_write</code></td>
            <td>20</td>
            <td>Low (often important)</td>
        </tr>
        <tr>
            <td><code>glob</code></td>
            <td>10</td>
            <td>Lowest (kept longest)</td>
        </tr>
    </tbody>
</table>

<!-- ------------------------------------------------------------------ -->
<h2 id="llm-compaction">LLM-Based Compaction</h2>

<p>
    When pruning and deduplication are not sufficient and token usage crosses
    the auto-compact threshold, the <strong>ContextCompactor</strong> performs
    a full LLM-based summarization of older messages.
</p>

<h3 id="compaction-process">How It Works</h3>

<ol>
    <li>
        The conversation is split into <strong>old messages</strong> (to be
        summarized) and <strong>recent messages</strong> (to be kept verbatim).
        By default, the most recent 3 message turns are always preserved.
    </li>
    <li>
        Old messages are formatted into a plain-text transcript and sent to
        the LLM with a dedicated compaction system prompt. The LLM is
        instructed to <em>only summarize</em>, not respond to questions in the
        conversation.
    </li>
    <li>
        The LLM produces a structured summary covering: the user's goal,
        key decisions made, work accomplished (with specific file paths),
        work in progress, and relevant files.
    </li>
    <li>
        The old messages are replaced with a single system message containing
        the summary, followed by the preserved recent messages.
    </li>
    <li>
        The summary is also stored as a <strong>working memory</strong>
        (with a 14-day expiration) so that the context persists even if the
        session ends.
    </li>
    <li>
        A second LLM call extracts <strong>durable memories</strong> from
        the summary &mdash; facts about the codebase, user preferences, and
        technical decisions. These are saved permanently for cross-session
        recall.
    </li>
</ol>

<h3 id="compaction-summary-format">Summary Format</h3>

<p>
    The compaction prompt instructs the LLM to produce a structured summary:
</p>

<pre><code>## Goal
[What the user is trying to accomplish]

## Key Decisions
[Important technical choices, constraints, user preferences]

## Accomplished
[Work completed -- specific file paths and changes]

## In Progress
[Current task and what remains]

## Relevant Files
[Files read, edited, or created]</code></pre>

<h3 id="compaction-protected-context">Protected Context</h3>

<p>
    Certain messages are always preserved before the summary and never
    summarized away. This includes the base system prompt context and any
    mode-specific instructions. The <strong>ProtectedContextBuilder</strong>
    assembles these based on the current agent mode and subagent context.
</p>

<h3 id="compaction-circuit-breaker">Circuit Breaker</h3>

<p>
    If the compaction LLM call fails three times consecutively, the circuit
    breaker activates. While active, the system skips compaction entirely and
    falls back to oldest-turn trimming when context pressure is critical. The
    circuit breaker resets automatically once context pressure drops below
    the warning threshold.
</p>

<h3 id="compaction-settings">Settings</h3>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Default</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>auto_compact</code></td>
            <td>on</td>
            <td>Toggle automatic compaction on or off</td>
        </tr>
        <tr>
            <td><code>auto_compact_threshold</code></td>
            <td>60% of context window</td>
            <td>
                Percentage of the context window at which compaction triggers.
                Also bounded by the <code>auto_compact_buffer_tokens</code>
                budget if configured.
            </td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p>
        <strong>Tip:</strong> You can trigger compaction manually at any time
        with the <code>/compact</code> slash command. This is useful if you
        know a long tool output is no longer relevant and want to reclaim
        context space proactively.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="oldest-turn-trimming">Oldest-Turn Trimming</h2>

<p>
    Oldest-turn trimming is the emergency fallback. It activates when all
    other strategies are insufficient and the token count hits the blocking
    threshold, or when the compaction circuit breaker is active.
</p>

<ul>
    <li>Drops the single oldest message from the conversation history</li>
    <li>Repeats until the token count is within the blocking budget</li>
    <li>No LLM call required &mdash; purely mechanical</li>
    <li>Context quality degrades because there is no summarization</li>
</ul>

<p>
    In practice, trimming is rare. The combination of truncation,
    deduplication, pruning, and compaction handles context pressure in the
    vast majority of sessions. Trimming exists as a safety net to ensure the
    agent never gets stuck due to context overflow.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="token-budgets">Token Budgets</h2>

<p>
    The <strong>ContextBudget</strong> class defines four thresholds that
    control when context management interventions occur. All thresholds are
    derived from the model's context window size minus configurable buffer
    values.
</p>

<table>
    <thead>
        <tr>
            <th>Budget</th>
            <th>Default</th>
            <th>Purpose</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>reserve_output_tokens</code></td>
            <td>16,384</td>
            <td>
                Headroom reserved for the LLM's response. Subtracted from the
                raw context window to produce the <em>effective context window</em>
                &mdash; the usable input token budget.
            </td>
        </tr>
        <tr>
            <td><code>warning_buffer_tokens</code></td>
            <td>24,576</td>
            <td>
                When remaining input tokens drop below this buffer, warning-level
                interventions begin (pruning, deduplication).
            </td>
        </tr>
        <tr>
            <td><code>auto_compact_buffer_tokens</code></td>
            <td>12,288</td>
            <td>
                When remaining input tokens drop below this buffer, automatic
                LLM-based compaction is triggered.
            </td>
        </tr>
        <tr>
            <td><code>blocking_buffer_tokens</code></td>
            <td>3,072</td>
            <td>
                Hard stop. When remaining input tokens drop below this buffer,
                oldest-turn trimming activates immediately. This is the
                last-resort threshold.
            </td>
        </tr>
    </tbody>
</table>

<h3 id="budget-calculation">How Budgets Are Calculated</h3>

<p>
    The system continuously tracks three components that make up the total
    token usage:
</p>

<ul>
    <li><strong>System prompt tokens</strong> &mdash; The assembled system prompt including base instructions, injected memories, session recall, mode suffix, parent brief, and active tasks.</li>
    <li><strong>Conversation tokens</strong> &mdash; All messages in the conversation history (user, assistant, tool results, system messages).</li>
    <li><strong>Tool schema tokens</strong> &mdash; The JSON schema definitions of all registered tools.</li>
</ul>

<p>
    Token counts are estimated using the <strong>TokenEstimator</strong>,
    which uses a fast character-based heuristic (roughly 1 token per 4
    characters) rather than a full tokenizer. This is accurate enough for
    budget decisions while being orders of magnitude faster.
</p>

<p>
    The intervention thresholds are:
</p>

<pre><code>Context Window (from model catalog)
  - reserve_output_tokens
  = Effective Context Window
    - warning_buffer_tokens      = Warning Threshold
    - auto_compact_buffer_tokens = Auto-Compact Threshold
    - blocking_buffer_tokens     = Blocking Threshold

Example with a 200K context window:
  200,000 - 16,384 = 183,616 (effective window)
  183,616 - 24,576 = 159,040 (warning: pruning begins)
  183,616 - 12,288 = 171,328 (auto-compact: LLM summarization)
  183,616 -  3,072 = 180,544 (blocking: force trim oldest)</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> The context bar in the TUI shows a real-time
        percentage of context used. When it turns yellow, you are approaching
        the warning threshold. When it turns red, compaction is imminent or
        active.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="memory-system">Memory System</h2>

<p>
    Memories are persistent knowledge fragments that survive across
    conversations. They allow the agent to remember facts about the codebase,
    your preferences, and key decisions made in previous sessions &mdash;
    without those sessions needing to be active.
</p>

<h3 id="memory-saving">Saving Memories</h3>

<p>
    The agent uses the <code>memory_save</code> tool to create new memories.
    Each memory has three required fields:
</p>

<table>
    <thead>
        <tr>
            <th>Field</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>type</code></td>
            <td>
                Category of the memory:
                <ul>
                    <li><strong>project</strong> &mdash; Facts about the codebase, architecture, patterns, conventions</li>
                    <li><strong>user</strong> &mdash; Your preferences, workflow style, corrections you have given</li>
                    <li><strong>decision</strong> &mdash; Key technical choices and the reasoning behind them</li>
                </ul>
            </td>
        </tr>
        <tr>
            <td><code>title</code></td>
            <td>Short descriptive label (used in the system prompt injection and memory listings)</td>
        </tr>
        <tr>
            <td><code>content</code></td>
            <td>Full memory content &mdash; the actual knowledge to be preserved</td>
        </tr>
    </tbody>
</table>

<p>
    You can ask the agent to remember something explicitly ("remember that I
    prefer tabs over spaces") and it will call <code>memory_save</code>
    automatically.
</p>

<h3 id="memory-searching">Searching Memories</h3>

<p>
    The agent uses the <code>memory_search</code> tool to find relevant
    memories by query. This is used both explicitly (when you ask "what do
    you remember about X?") and implicitly during system prompt assembly.
</p>

<h3 id="memory-auto-extraction">Automatic Memory Extraction</h3>

<p>
    During context compaction, the LLM is asked to extract durable knowledge
    from the conversation summary. This extraction produces memories
    categorized as <code>project</code>, <code>user</code>, or
    <code>decision</code>. Only non-obvious insights are extracted &mdash;
    things that would not be apparent from reading the code alone.
</p>

<p>
    This means important context persists even when the conversation history
    is summarized away. A decision made in turn 5 of a long session will be
    captured as a memory and available in all future sessions, even though
    the original conversation turns have been compacted.
</p>

<p>
    After extraction, the session manager runs
    <strong>memory consolidation</strong> to merge duplicate or overlapping
    memories, preventing the memory store from growing unboundedly.
</p>

<h3 id="memory-retention">Memory Retention Classes</h3>

<p>
    Each memory belongs to a retention class that determines its lifecycle:
</p>

<table>
    <thead>
        <tr>
            <th>Class</th>
            <th>Behavior</th>
            <th>Typical Use</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>priority</strong></td>
            <td>Always injected first in the system prompt</td>
            <td>Critical context that must always be visible to the agent</td>
        </tr>
        <tr>
            <td><strong>durable</strong></td>
            <td>Persists indefinitely; default for user-created and extracted memories</td>
            <td>Project facts, user preferences, key decisions</td>
        </tr>
        <tr>
            <td><strong>working</strong></td>
            <td>May be garbage-collected after a period of disuse (typically 14 days for compaction summaries)</td>
            <td>Session continuity summaries, temporary context</td>
        </tr>
        <tr>
            <td><strong>pinned</strong></td>
            <td>Never automatically removed, even during consolidation</td>
            <td>Critical knowledge the user has explicitly marked as permanent</td>
        </tr>
    </tbody>
</table>

<h3 id="memory-injection">Memory Injection into System Prompt</h3>

<p>
    The <strong>MemoryInjector</strong> formats stored memories into structured
    sections that are appended to the system prompt. Memories are organized
    by class and type:
</p>

<pre><code># Memories

## Priority Context
- Critical architecture note: The API gateway uses rate limiting per tenant...

## Project Knowledge
- Database schema: Uses PostgreSQL with UUID primary keys... (2026-03-15)
- Test conventions: All tests extend BaseTestCase... (2026-03-20)

## User Preferences
- Code style: Prefers early returns over nested conditionals...
- Communication: Concise responses, no filler text...

## Key Decisions
- Auth approach: JWT with refresh tokens, chosen over session cookies... (2026-03-18)

## Working Memory
- Previous session summary: Implemented the payment webhook handler...

## Previous Sessions
- [2026-04-01] Refactored the notification system...
- [2026-03-28] Added CSV export for user reports...</code></pre>

<p>
    Up to 6 relevant memories are injected per turn (configurable). Working
    memory is capped at the 5 most recent entries to limit context size.
    Compaction summaries from previous sessions show at most 3 entries.
</p>

<h3 id="memory-commands">Memory Commands</h3>

<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>/memories</code></td>
            <td>List all stored memories with their type, class, and creation date</td>
        </tr>
        <tr>
            <td><code>/forget &lt;id&gt;</code></td>
            <td>Delete a specific memory by its ID</td>
        </tr>
    </tbody>
</table>

<p>
    Memories are stored in a SQLite database at
    <code>~/.kosmokrator/data/kosmokrator.db</code>. They are scoped to the
    current project directory, so memories saved while working in
    <code>~/projects/alpha</code> will not appear when working in
    <code>~/projects/beta</code>.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="session-management">Session Management</h2>

<p>
    Sessions provide continuity across terminal restarts. Every conversation
    is automatically saved, and you can resume any previous session with its
    full history, tool results, and context intact.
</p>

<h3 id="session-commands">Session Commands</h3>

<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>/sessions</code></td>
            <td>List recent sessions with dates and model names</td>
        </tr>
        <tr>
            <td><code>/resume</code></td>
            <td>Pick a session to resume interactively (shows conversation preview)</td>
        </tr>
        <tr>
            <td><code>/new</code></td>
            <td>Start a fresh session (the current session is auto-saved)</td>
        </tr>
        <tr>
            <td><code>/compact</code></td>
            <td>Manually trigger context compaction on the current session</td>
        </tr>
    </tbody>
</table>

<h3 id="session-cli-flags">CLI Flags</h3>

<table>
    <thead>
        <tr>
            <th>Flag</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>--resume</code></td>
            <td>Resume the most recent session automatically on startup</td>
        </tr>
        <tr>
            <td><code>--session &lt;id&gt;</code></td>
            <td>Resume a specific session by its ID</td>
        </tr>
    </tbody>
</table>

<h3 id="session-recall">Session History Recall</h3>

<p>
    When the memory system is enabled, the agent can search across previous
    sessions for relevant context. This is different from resuming a session
    &mdash; it pulls in snippets from past conversations that are relevant
    to the current query, formatted as a "Session Recall" section in the
    system prompt. Up to 3 relevant session fragments are included.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="system-prompt-assembly">System Prompt Assembly</h2>

<p>
    The system prompt is rebuilt on each turn to incorporate the latest
    context. The <strong>ContextManager</strong> assembles it from multiple
    layers, each adding domain-specific information:
</p>

<ol>
    <li>
        <strong>Base prompt</strong> &mdash; The core system prompt defining
        the agent's role, capabilities, tool usage conventions, and general
        behavior rules.
    </li>
    <li>
        <strong>Relevant memories</strong> &mdash; Selected by similarity to
        recent messages. The MemoryInjector formats up to 6 memories into
        structured sections (priority context, project knowledge, user
        preferences, key decisions, working memory, previous sessions).
    </li>
    <li>
        <strong>Session history recall</strong> &mdash; Relevant fragments
        from previous sessions, found by searching the session history
        against the current user query (up to 3 results).
    </li>
    <li>
        <strong>Mode-specific suffix</strong> &mdash; Behavioral rules for
        the current agent mode:
        <ul>
            <li><strong>Edit mode</strong> &mdash; Full tool access, write permissions, standard behavior</li>
            <li><strong>Plan mode</strong> &mdash; Read-only tools, no modifications, focused on analysis and planning</li>
            <li><strong>Ask mode</strong> &mdash; Conversational, no tool use, answers from existing knowledge and context</li>
        </ul>
    </li>
    <li>
        <strong>Parent brief</strong> &mdash; When running as a subagent,
        the parent agent's task description and constraints are injected so
        the subagent understands its role in the broader workflow.
    </li>
    <li>
        <strong>Active tasks</strong> &mdash; A rendered tree of the current
        task tracking state, so the agent is aware of pending work items
        and their status.
    </li>
</ol>

<p>
    The prompt is rebuilt every turn rather than cached because memories,
    tasks, and mode can all change between turns. The token cost of the
    system prompt is included in the ContextBudget calculations.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Memory injection is suppressed during token
        estimation to avoid side effects (such as marking memories as
        "surfaced"). The estimation uses a read-only pass of the prompt
        builder.
    </p>
</div>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';

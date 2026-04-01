# Context Management Strategies

> Status: Proposal. This document describes possible future improvements beyond the context-management pipeline that currently ships.

Future improvements to KosmoKrator's context management beyond the current three-layer system (truncation → pruning → compaction).

## 1. Semantic Importance Scoring

Score each tool result (0.0–1.0) to decide what to prune first. Four signals, no LLM call:

- **Reference density** — How much of the tool result did the assistant actually quote/use in its response? Split result into lines, count how many appear in the assistant's text. High overlap = load-bearing.
- **Decision influence** — Did the assistant make a decision citing this result? Detect decision language ("the issue is", "I'll use", "based on") + file path/tool name in the following assistant message.
- **Tool type weight** — Static: `bash` 0.7 (irreproducible), `grep` 0.5 (re-searchable), `file_read` 0.3 (on disk), `glob` 0.1 (trivial to redo), `file_write`/`file_edit` 0.2 (just confirmations).
- **Downstream dependency** — Did values from this result appear in arguments of later tool calls? (grep finds path → file_read uses that path). Breaking the chain loses reasoning context.

Combined score: `0.3 × reference + 0.25 × decision + 0.25 × type + 0.2 × dependency`

Pruner sorts candidates by score ascending, prunes lowest-value first until it hits the savings target. High-importance results survive even if old.

## 2. Tool Result Deduplication

The LLM frequently re-reads the same file (read → edit → read to verify). Each re-read dumps redundant content into context.

Three tiers:

- **Exact duplicate** — Same tool + same args + same result → replace older with `[superseded — same content returned by later call]`
- **Same-file re-read** — `file_read` same path, different offset/limit or after `file_edit` on that path → old content is stale, supersede it
- **Semantic overlap** — `grep` returns lines from `foo.php`, then `file_read foo.php` returns those same lines plus more → grep result is now a subset, replace with `[content included in later file_read of foo.php]`

Runs eagerly after each tool call (before adding to history, scan backwards for matches). Detection is a hash lookup + string comparison — microseconds.

The supersede message preserves the *fact* that the read happened (the LLM knows the file was relevant) without the *content* (which exists in the newer result).

### How They Combine

Dedup runs first as a cheap pass (always safe). Then importance scoring handles the rest — pruner removes lowest-scored results first. Together they form a priority queue:

1. Duplicates → always prune
2. Low-importance results (low reference density, no decision influence) → prune when over budget
3. High-importance results → survive until compaction
4. Protected recent turns (last 2 user messages) → never pruned

## 3. Other Ideas (Not Yet Designed)

- **Progressive summarization** — Instead of `[cleared]`, replace with a heuristic summary: `[file_read /src/Foo.php: 245 lines, PHP class with methods bar(), baz()]`. No LLM call, just structural extraction.
- **Pre-flight context budget** — Before sending to LLM, estimate prompt size via `TokenEstimator` and proactively prune/compact. Avoids wasted API calls.
- **Sliding context tiers** — Last 2 turns: full fidelity. Turns 3-5: tool results summarized. Turns 6+: tool results cleared, assistant responses truncated. Graceful degradation instead of a cliff.
- **File content caching** — Store file reads in a local cache keyed by `path:mtime`. Replace tool result with compact reference. Re-read from cache instead of re-reading from disk.
- **Session branching** — `/branch` snapshots the current session and starts fresh with just a summary. Old session preserved intact and resumable.

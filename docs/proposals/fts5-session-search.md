# FTS5 Session Search + LLM Summarization

**Status**: Deferred — not currently needed

## Why it was investigated

Hermes-agent uses FTS5 full-text search on SQLite message history with LLM-powered summarization of matching sessions. KosmoKrator uses `LIKE '%query%'` on the messages table.

## Current state

- `MessageRepository::searchProjectHistory()` uses LIKE, scoped to project, excluding compacted messages
- Returns individual message rows, not session-level grouping
- Auto-injected into system prompt (3 results via `ContextManager`) and on-demand via `memory_search(scope="history")` (8 results)
- Results are raw 220-char truncated snippets, not LLM-summarized

## Why it's deferred

Benchmarked on the real kosmokrator project DB (11,546 messages):

| Query | Time |
|-------|------|
| `LIKE '%refactoring%' LIMIT 3` | 0.026s |
| `LIKE '%the%' LIMIT 3` (worst case) | 0.018s |

Sub-30ms on the largest project. The LLM API call that follows takes 2-30 seconds. LIKE is ~0.1% of total latency. FTS5 would add migration complexity, sync overhead, and rebuild logic for a non-existent performance problem.

## What to revisit if

- Message count exceeds ~100K (hundreds of sessions per project)
- A user-facing search feature is added that needs relevance ranking (BM25)
- Multi-word tokenized search is needed (current LIKE treats query as a substring)
- Session recall limit is increased significantly (currently 3 auto-injected, 8 via tool)
- LLM summarization is desired (requires configuring a second cheap model)

## Reference: Hermes architecture

Hermes's `session_search_tool.py` does:
1. FTS5 search → group by session → deduplicate
2. Resolve child/delegation sessions to parents
3. Load full conversations, truncate to 100K chars centered on matches
4. Parallel LLM summarization via Gemini Flash
5. Two modes: recent sessions (metadata-only, zero cost) and keyword search (LLM summarized)

Key patterns worth borrowing when this becomes relevant:
- `_truncate_around_matches()` — center the window on where query terms appear
- `_resolve_to_parent()` — walk `parent_session_id` chain to group child sessions
- Per-session summaries instead of raw message snippets

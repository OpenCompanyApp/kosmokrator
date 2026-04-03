# Command Inspiration — From oh-my-claudecode

Audit of oh-my-claudecode's 31 slash commands compared against KosmoKrator's existing 22. Candidates for future implementation, grouped by priority.

## High-Value Additions

### /autopilot
Full autonomous pipeline from idea to verified working code. 5 phases: Expand (clarify requirements) → Plan (architecture) → Execute (write code) → QA (test + verify) → Validate (acceptance criteria). User kicks it off and walks away.

### /ralph (persistence loop)
"The boulder never stops." Keeps retrying a task until verified complete. PRD-driven verification — after each attempt, checks acceptance criteria. Mandatory architect review before marking done. Max retry limit prevents infinite loops. Named after Sisyphus's boulder — fits the mythology theme.

### /trace
Evidence-driven investigative debugging. Generates competing hypotheses for a bug, ranks them by evidence weight, then runs discriminating probes (targeted searches/tests) to narrow down the root cause. Structured output: ranked explanations with confidence scores.

### /deep-interview
Socratic requirements gathering before expensive work. Asks probing questions, scores ambiguity mathematically across dimensions (Goal, Constraints, Criteria, Context). Won't proceed until ambiguity drops below threshold (~20%). Uses challenge agents: Contrarian (pokes holes), Simplifier (finds simpler approaches), Ontologist (clarifies terms). Prevents wasted swarm runs on vague requests.

### /deslop
AI slop cleaner — regression-safe deletion-first cleanup of AI-generated bloat. Reviews code for: unnecessary abstractions, over-engineering, dead code, excessive comments, unused error handling. Deletion-first workflow: remove before rewriting. Optional reviewer-only mode (reports but doesn't change). Natural complement to /unleash — clean up after the swarm.

### /deepinit
One-shot comprehensive codebase documentation generator. Crawls entire project, generates hierarchical AGENTS.md-style docs across all directories. Useful for onboarding new contributors or giving AI agents better context.

## Medium-Value Additions

### /team
Staged pipeline with named roles: team-plan → team-prd → team-exec → team-verify → team-fix. Each stage is a specialized agent with handoff documents preserving decisions, alternatives, and risks between stages. Inter-agent messaging for coordination.

### /ultraqa
Autonomous QA cycling: run tests → analyze failures → fix → re-run → verify. Repeats up to 5 cycles or until all tests pass. Useful after large refactors or /unleash runs.

### /doctor
Self-diagnostic command. Checks: PHP version, extensions, config validity, provider API keys, database connectivity, TUI availability, dependency versions. Reports issues with suggested fixes.

### /cancel
Gracefully cancel any active mode (autopilot, ralph, unleash) with intelligent state cleanup. Auto-detects what's running and tears it down properly.

### /learner
Extract a reusable debugging pattern or technique from the current conversation. Quality-gated: only saves if the pattern is generalizable. Stores as a "skill" that can be referenced in future sessions.

## Already Covered by KosmoKrator

| OMC Command | KosmoKrator Equivalent |
|---|---|
| /plan, /ralplan | /plan (consensus planning could be added) |
| /ask | /ask |
| /setup | `kosmokrator setup` |
| /hud | Built-in status bar |
| /cancel | Ctrl+C cascading cancellation |
| /ultrawork (parallel execution) | /unleash + SubagentOrchestrator |
| /skill (memory/patterns) | /memories system |
| /external-context | Memory search + file tools |

## OMC Patterns Worth Noting

- **Mathematical ambiguity gating** — weighted scoring before execution prevents wasted work on vague requests
- **Handoff documents** — structured context preservation between pipeline stages (decisions, alternatives, risks)
- **Challenge agents** — Contrarian/Simplifier/Ontologist roles that stress-test plans before execution
- **Consensus planning** — Planner/Architect/Critic loop produces better plans than single-agent planning
- **Magic keywords** — trigger commands without `/` prefix (e.g. typing "autopilot" activates the pipeline)

## Source

Analysis based on: `/tmp/oh-my-claudecode/skills/*/SKILL.md`

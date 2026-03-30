# Subagent Architecture

KosmoKrator supports spawning child agent loops (subagents) for parallel research, exploration, and complex multi-step tasks. Subagents are autonomous — they run their own tool loop, manage their own context, and return a summary to the parent.

## Agent Types

Three agent types with strict permission inheritance:

| Type        | Read | Write | Spawn children         |
|-------------|------|-------|------------------------|
| **General** | yes  | yes   | General, Explore, Plan |
| **Explore** | yes  | no    | Explore only           |
| **Plan**    | yes  | no    | Explore only           |

Permissions only narrow downward, never widen. An Explore agent can never spawn a General child.

```
Allowed spawns:

  Parent \ Child │ General │ Explore │ Plan │
  ───────────────┼─────────┼─────────┼──────┤
  General        │    ✓    │    ✓    │   ✓  │
  Explore        │    ✗    │    ✓    │   ✗  │
  Plan           │    ✗    │    ✓    │   ✗  │
```

### Permission Inheritance Tree

```
                    ┌─────────────────────────────────────┐
                    │            ROOT (General)            │
                    │  tools: ALL + subagent               │
                    └──────┬──────────┬──────────┬────────┘
                           │          │          │
              ┌────────────▼──┐  ┌────▼───────┐  ┌▼────────────┐
              │   General     │  │  Explore   │  │    Plan     │
              │ tools: ALL    │  │ tools: RO  │  │ tools: RO   │
              │ + subagent    │  │ + subagent │  │ + subagent  │
              └──────┬────────┘  └─────┬──────┘  └──────┬──────┘
                     │                 │                 │
            can spawn:          can spawn:         can spawn:
         General/Explore/Plan   Explore ONLY       Explore ONLY
                     │                 │                 │
              ┌──────▼────────┐  ┌─────▼──────┐  ┌──────▼──────┐
              │   General     │  │  Explore   │  │   Explore   │
              │ (depth 2)     │  │ (depth 2)  │  │  (depth 2)  │
              │ tools: ALL    │  │ tools: RO  │  │  tools: RO  │
              │ + subagent*   │  │ + subagent*│  │  + subagent*│
              └───────────────┘  └────────────┘  └─────────────┘

              * subagent tool present only if depth < maxDepth
              RO = file_read, grep, glob, bash (read-only)
```

## Depth Control

Default max depth: 3 (root → sub → subsub). Configurable in settings.

The depth counter travels with each spawn — child inherits `depth + 1` and the same `maxDepth`. At `depth = maxDepth - 1` the subagent tool is removed from the child's registry.

## Tool Scoping

`ToolRegistry::scoped(AgentContext)` returns a filtered copy based on agent type and depth:

```
  depth 0, General          depth 1, Explore         depth 2, Explore
  ┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
  │ ✓ file_read      │     │ ✓ file_read      │     │ ✓ file_read      │
  │ ✓ file_write     │     │ ✗ file_write     │     │ ✗ file_write     │
  │ ✓ file_edit      │     │ ✗ file_edit      │     │ ✗ file_edit      │
  │ ✓ grep           │     │ ✓ grep           │     │ ✓ grep           │
  │ ✓ glob           │     │ ✓ glob           │     │ ✓ glob           │
  │ ✓ bash           │     │ ✓ bash (read)    │     │ ✓ bash (read)    │
  │ ✓ subagent       │     │ ✓ subagent       │     │ ✗ subagent       │
  └──────────────────┘     │   (explore only) │     │   (at maxDepth)  │
                           └──────────────────┘     └──────────────────┘
```

## Concurrency Model

Two orthogonal axes control how subagents execute:

### Axis 1: Parent Flow (does the parent wait?)

| Mode           | Behavior                                                                   | Use case                                  |
|----------------|----------------------------------------------------------------------------|-------------------------------------------|
| **`await`**    | Tool call blocks. Result returns as normal tool result. Multiple awaits in one response still run concurrently (existing parallel tool execution) | "Look this up, I need the answer to continue" |
| **`background`** | Parent gets immediate ack. Result injected later as a message on the parent's next LLM turn | "Go research this, I'll keep working"     |

### Axis 2: Inter-Agent Coordination (do siblings wait for each other?)

| Mechanism                | Behavior                                                              | Use case                                          |
|--------------------------|-----------------------------------------------------------------------|---------------------------------------------------|
| *(none)*                 | Full parallelism                                                      | "Explore these 50 treaties independently"         |
| **`depends_on: [id]`**  | Agent doesn't start until referenced agent(s) finish. Can chain A → B → C | "Scrape the index first, then use it for subpages" |
| **`group: "name"`**     | Agents in the same named group run sequentially (FIFO). Different groups run in parallel | "Hit Playwright one at a time"                    |

These axes are orthogonal. A `background` agent can have `depends_on`. An `await` agent can be in a group.

```
                        Axis 1: PARENT FLOW
                        (does parent wait?)

                    await              background
                 ┌───────────────┬────────────────────┐
                 │               │                    │
  no coord       │  Parent blocks │  Parent continues  │
  (default)      │  Result inline │  Result injected   │
                 │               │  later as message  │
                 │               │                    │
              ───┼───────────────┼────────────────────┤
                 │               │                    │
  depends_on     │  A blocks     │  A runs background  │
                 │  then B runs  │  B auto-starts     │
                 │  then B       │  when A finishes    │
                 │  returns      │                    │
                 │               │                    │
              ───┼───────────────┼────────────────────┤
                 │               │                    │
  group          │  A runs,      │  A runs background  │
  ("browser")    │  B queues,    │  B queues,          │
                 │  B runs when  │  runs when A done  │
                 │  A done       │                    │
                 │               │                    │
                 └───────────────┴────────────────────┘
```

## SubagentTool Parameters

```
task:       string   (required)  — what the agent should do
type:       enum     (optional)  — general | explore | plan (default: explore)
mode:       enum     (optional)  — await | background (default: await)
id:         string   (optional)  — name this agent for depends_on references
depends_on: string[] (optional)  — agent IDs that must finish first
group:      string   (optional)  — sequential execution group name
```

## Execution Scenarios

### Scenario 1: Tax Treaty Army (max parallelism)

```
  Parent (General)
    │
    │  LLM says: spawn 50 explore agents, all background
    │
    ├──async──▶ Explore "treaty DE-NL"  ──────────▶ done ──┐
    ├──async──▶ Explore "treaty DE-US"  ────────▶ done ──┐ │
    ├──async──▶ Explore "treaty DE-JP"  ──▶ done ──┐     │ │
    ├──async──▶ Explore "treaty DE-FR"  ─────▶ ... │     │ │
    │  ...46 more...                               │     │ │
    │                                              │     │ │
    ▼ parent keeps working                         │     │ │
    │                                              ▼     ▼ ▼
    │◀──── results injected as they finish ◀───────┴─────┴─┘
    ▼
   LLM sees all results, synthesizes
```

### Scenario 2: Playwright Scraping (sequential group)

```
  Parent (General)
    │
    │  spawn 5 agents, all in group:"browser"
    │
    ├──bg──▶ ┌─ group "browser" queue ──────────────────┐
    ├──bg──▶ │                                          │
    ├──bg──▶ │  Agent 1 ████████ done                   │
    ├──bg──▶ │          Agent 2 ██████████ done          │
    ├──bg──▶ │                   Agent 3 ████ done       │
    │        │                        Agent 4 ████████   │
    │        │                                 Agent 5.. │
    │        └──────────────────────────────────────────┘
    ▼                        only ONE runs at a time
   parent continues
```

### Scenario 3: Pipeline with Dependencies

```
  Parent (General)
    │
    │  Turn 1:
    ├──bg──▶ Agent "sitemap" (id: sitemap)
    │           │ scrapes sitemap.xml
    │           │ returns list of 200 URLs
    │           ▼
    │         done ─────────────┐
    │                           │ result passed to dependents
    ├──bg──▶ Agent "products"   │
    │         depends_on: [sitemap]
    │           │               │
    │           │◀──────────────┘ starts now, receives sitemap result
    │           │ spawns 200 sub-subagents (Explore)
    │           │   ├──▶ Explore "product /p/1"
    │           │   ├──▶ Explore "product /p/2"
    │           │   └──▶ ... (group:"browser", 3 at a time)
    │           ▼
    │         done ──▶ injected to parent
    ▼
   parent was working on other things the whole time
```

### Scenario 4: Quick Lookup (sync/await)

```
  Parent (General)
    │
    │  LLM says: subagent(mode: await, task: "find user schema")
    │
    ├──await──▶ Explore agent
    │           ├── grep "CREATE TABLE users"
    │           ├── file_read migrations/
    │           ├── file_read models/User.php
    │           └── returns: "users table has 12 cols..."
    │◀──result──┘
    │
    │  same turn, LLM now has the schema
    │  continues reasoning with it
    ▼
```

## Result Delivery

| Mode       | How the parent receives the result                                         |
|------------|----------------------------------------------------------------------------|
| `await`    | Normal tool result in the same turn                                        |
| `background` | Injected as a message when done. If multiple finish between parent LLM calls, they batch together |

```
  time ──────────────────────────────────────────────────────▶

  Parent:  [prompt]──▶[spawn A,B,C bg]──▶[work]──▶[LLM]──▶[work]──▶[LLM sees A,C]──▶
                          │  │  │                    ▲               ▲
  Agent A: ───────────────┘  │  │  ██████done────────┘               │
  Agent B: ──────────────────┘  │  ████████████████████████done──────┘
  Agent C: ─────────────────────┘  ████done──────────────────────────┘

  Results batch: if multiple finish between parent LLM calls,
  they're all injected together in the next turn.
```

## Internal Architecture

```
  SubagentTool (tool the LLM calls)
    ├── validates type allowed by parent's type
    ├── validates depth < maxDepth
    ├── resolves depends_on to futures
    └── dispatches to SubagentOrchestrator

  SubagentOrchestrator (singleton, manages the swarm)
    ├── agents: map of id → Future<string>
    ├── groups: map of groupName → Semaphore
    ├── spawnAgent(params, context) → Future<string> | string
    ├── await mode: future->await() inline
    ├── background mode: register future, inject result when resolved
    └── depends_on: async { awaitAll(deps); then run }

  AgentContext (value object, travels down the tree)
    ├── parentType: AgentType
    ├── depth: int
    ├── maxDepth: int (from settings)
    ├── orchestrator: SubagentOrchestrator (shared ref)
    └── stats: SubagentStats (for UI updates)
```

### Orchestrator — Shared Across the Tree

```
  ┌──────────────────────────────────────────────────┐
  │              SubagentOrchestrator                 │
  │  (singleton, lives on root, shared via context)   │
  │                                                  │
  │  agents: {                                       │
  │    "sitemap" → Future<string>  ✓ resolved        │
  │    "products" → Future<string>  ● running        │
  │    "anon-3"  → Future<string>  ● running         │
  │  }                                               │
  │                                                  │
  │  groups: {                                       │
  │    "browser" → Semaphore(1)  ── controls access  │
  │  }                                               │
  │                                                  │
  │  stats: {                                        │
  │    "sitemap"  → { tools: 12, tokens: 34100 }    │
  │    "products" → { tools: 8,  tokens: 21000 }    │
  │    "anon-3"   → { tools: 3,  tokens: 9200  }    │
  │  }                                               │
  └──────────────────────────────────────────────────┘
          │                  ▲
          │ shared ref       │ registers futures
          ▼                  │
  ┌───────────────┐   ┌─────┴─────────┐   ┌───────────────┐
  │ AgentContext   │   │ AgentContext   │   │ AgentContext   │
  │ depth: 0      │   │ depth: 1      │   │ depth: 2      │
  │ type: General  │   │ type: Explore  │   │ type: Explore  │
  │ orchestrator ──┼──▶│ orchestrator ──┼──▶│ orchestrator ──│──▶ same
  │ maxDepth: 3    │   │ maxDepth: 3    │   │ maxDepth: 3    │
  └───────────────┘   └───────────────┘   └───────────────┘
       root               subagent          sub-subagent
```

## UI Feedback

Subagents are silent (no inline rendering to terminal), but the parent's UI shows live status:

```
⏺ 3 agents running, 2 finished
  ├─ ✓ Explore "scrape sitemap" · 12 tools · 34.1k tokens · done
  ├─ ● General "scrape products" · waiting on "scrape sitemap"
  ├─ ● Explore "analyze treaty DE-NL" · 8 tools · running
  ├─ ✓ Explore "analyze treaty DE-US" · 15 tools · 41.2k tokens · done
  └─ ● Explore "analyze treaty DE-JP" · queued (group: browser)
```

Sub-subagents nest inside their parent's status line. Stats (tool count, tokens) update in real-time via the shared `SubagentStats` object.

## Files

| Action | File                                   | Purpose                                          |
|--------|----------------------------------------|--------------------------------------------------|
| New    | `src/Agent/AgentType.php`              | Enum: General, Explore, Plan                     |
| New    | `src/Agent/AgentContext.php`           | Depth, type, stats, orchestrator ref             |
| New    | `src/Agent/SubagentOrchestrator.php`   | Manages futures, groups, dependency resolution   |
| New    | `src/Agent/SubagentStats.php`          | Tool count, tokens, status — shared mutable      |
| New    | `src/Tool/Coding/SubagentTool.php`     | Validates permissions + spawns via orchestrator  |
| Modify | `src/Agent/AgentLoop.php`              | Add `runHeadless()`, accept AgentContext          |
| Modify | `src/Tool/ToolRegistry.php`            | Add `scoped(AgentContext)` for filtered copies   |
| Modify | `src/UI/RendererInterface.php`         | Add subagent status display methods              |
| Modify | Both renderers                         | Implement subagent status UI                     |
| New    | `tests/Unit/Agent/SubagentTest.php`    | Integration tests for spawn + permission rules   |
| New    | `tests/Unit/Tool/SubagentToolTest.php` | Validation, type inheritance, depth limits       |

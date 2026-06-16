# KosmoKrator — ASCII Architecture Diagrams

Visual reference for the major subsystems. All diagrams reflect current shipped
behavior (see `docs/architecture/overview.md` for the source of truth).

---

## 1. High-Level System Map

One runtime, four entry points. All paths converge on the same agent core.

```
                          ┌─────────────────────────────────────────┐
                          │              ENTRY POINTS               │
                          └─────────────────────────────────────────┘

  bin/kosmo                  kosmo -p "prompt"         SDK / PHP            kosmo acp
  (interactive)              (headless CLI)           (AgentBuilder)       (editor/IDE)
       │                          │                        │                    │
       ▼                          ▼                        ▼                    ▼
 ┌──────────┐             ┌──────────────┐        ┌─────────────┐     ┌──────────────┐
 │  Kernel  │             │ AgentCommand │        │ AgentBuilder│     │ AcpCommand   │
 │ (boot DI)│             │  (-p / JSON) │        │  → build()  │     │ (JSON-RPC)   │
 └────┬─────┘             └──────┬───────┘        └──────┬──────┘     └──────┬───────┘
      │                          │                       │                   │
      └──────────────┬───────────┴───────────┬───────────┘                  │
                     │                       │                              │
                     ▼                       ▼                              ▼
            ┌────────────────────────────────────────────────────────────────────┐
            │                      AgentSessionBuilder                          │
            │   wires: LLM client · permissions · tools · context pipeline ·     │
            │          subagent infra · UI renderer · session store              │
            └──────────────────────────────┬─────────────────────────────────────┘
                                           │
                                           ▼
            ┌────────────────────────────────────────────────────────────────────┐
            │                            AgentLoop (REPL)                          │
            │         prompt → LLM → tool calls → LLM → ... → done                 │
            └──┬──────────────┬──────────────┬───────────────┬───────────────┬────┘
               │              │              │               │               │
               ▼              ▼              ▼               ▼               ▼
        ┌────────────┐ ┌────────────┐ ┌────────────┐  ┌────────────┐  ┌────────────┐
        │ ToolExecu- │ │ Context    │ │ LLM Client │  │ Renderer   │  │ Subagent   │
        │ tor        │ │ Manager    │ │ (Amp/Prism)│  │ (TUI/ANSI/ │  │ Orchestra- │
        │            │ │            │ │            │  │  Headless) │  │ tor        │
        └────────────┘ └────────────┘ └────────────┘  └────────────┘  └────────────┘
```

---

## 2. The Agent Loop (REPL)

The central execution cycle. Runs until the LLM signals completion (no hard tool-round limit).

```
                         ┌──────────────────┐
                         │   user prompt    │
                         │  (or task input) │
                         └────────┬─────────┘
                                  │
                                  ▼
              ┌───────────────────────────────────────┐
              │          ContextManager.preflight      │
              │  check context budget vs window size   │
              └───────────────────┬───────────────────┘
                                  │
                    ┌─────────────┴─────────────┐
                    │  over budget?             │
                    │                           │
                 no │                  yes      │
                    ▼                           ▼
              ┌──────────┐       ┌───────────────────────────┐
              │          │       │  CONTEXT PIPELINE (in order)│
              │  build   │       │                            │
              │  request │       │  1. Truncate oversized     │
              │          │       │     tool outputs           │
              └────┬─────┘       │  2. Deduplicate superseded │
                   │             │     tool results           │
                   │             │  3. Prune old low-value    │
                   │             │     results (protect N)    │
                   │             │  4. Compact (LLM summary   │
                   │             │     + memory extraction)   │
                   │             │  5. Trim oldest (fallback) │
                   │             └─────────────┬─────────────┘
                   │                           │
                   └───────────┬───────────────┘
                               ▼
                   ┌───────────────────────┐
                   │   stream to LLM       │ ◄──── RetryableLlmClient
                   │   (with tools +       │       (429 / 5xx backoff)
                   │    system prompt)     │
                   └───────────┬───────────┘
                               │
                  ┌────────────┴────────────┐
                  │  finish_reason?         │
                  │                         │
       tool_calls │                stop     │
                  ▼                          │
        ┌─────────────────┐                  │
        │  ToolExecutor   │                  │
        │  (concurrent,   │                  │
        │   partitioned)  │                  │
        └────────┬────────┘                  │
                 │                            │
   ┌─────────────┴─────────────┐              │
   │  for each tool call:      │              │
   │                           │              │
   │  PermissionEvaluator      │              │
   │   → Allow / Ask / Deny    │              │
   │                           │              │
   │  execute approved tools   │              │
   │  collect results          │              │
   │  render to UI             │              │
   └─────────────┬─────────────┘              │
                 │                             │
                 ▼                             │
        ┌────────────────┐                     │
        │ append results │─────────────────────┘
        │ to history     │
        └────────────────┘     (loop back to preflight)
                                  │
                                  ▼  (on stop)
                         ┌──────────────────┐
                         │   final response  │
                         │  persist + render │
                         └──────────────────┘
```

---

## 3. Permission Evaluation Chain

Every governed tool call passes through this ordered chain. First match wins; fail-closed default.

```
                    tool call (name, args)
                            │
                            ▼
              ┌─────────────────────────────┐
              │  1. BlockedPathCheck         │   deny *.env, *.pem,
              │     (always enforced)        │   .git/*, id_rsa, *.key
              └──────────────┬──────────────┘
                     match?  │
                 ┌───────────┴───────────┐
              yes │                  no  │
                 ▼                      ▼
              DENY          ┌─────────────────────────────┐
                            │  2. DenyPatternCheck         │
                            │     (blocked bash patterns)  │
                            └──────────────┬──────────────┘
                                   match?  │
                           ┌───────────────┴───────────┐
                        yes │                      no  │
                           ▼                          ▼
                        DENY           ┌─────────────────────────────┐
                                       │  3. ProjectBoundaryCheck     │
                                       │     (writes outside root?)   │
                                       └──────────────┬──────────────┘
                                              match?  │
                                      ┌────────────────┴────────┐
                                   yes │                   no  │
                                      ▼                       ▼
                                   ASK             ┌─────────────────────────────┐
                                                  │  4. SessionGrantCheck        │
                                                  │     (approved this session?) │
                                                  └──────────────┬──────────────┘
                                                         grant? │
                                                 ┌─────────────┴───────────┐
                                               yes │                   no │
                                                  ▼                       ▼
                                               ALLOW          ┌───────────────────────────┐
                                                              │  5. RuleCheck              │
                                                              │     allow / ask / deny     │
                                                              │     per config rules       │
                                                              └─────────────┬─────────────┘
                                                                    result? │
                                                            ┌──────────────┴──────────┐
                                                         yes │                    no  │
                                                            ▼                        ▼
                                                     rule result      ┌────────────────────────────┐
                                                                      │  6. ModeOverrideCheck       │
                                                                      │                             │
                                                                      │  ┌───────────────────────┐  │
                                                                      │  │ Guardian (◈, default) │  │
                                                                      │  │ heuristic auto-approve│  │
                                                                      │  │ safe patterns → ALLOW │  │
                                                                      │  │ else → ASK            │  │
                                                                      │  └───────────────────────┘  │
                                                                      │  ┌───────────────────────┐  │
                                                                      │  │ Argus (◉)             │  │
                                                                      │  │ everything → ASK      │  │
                                                                      │  └───────────────────────┘  │
                                                                      │  ┌───────────────────────┐  │
                                                                      │  │ Prometheus (⚡)        │  │
                                                                      │  │ upgrades ASK → ALLOW  │  │
                                                                      │  │ denies still enforced │  │
                                                                      │  └───────────────────────┘  │
                                                                      └─────────────┬──────────────┘
                                                                                    ▼
                                                                              ALLOW / ASK / DENY
                                                                                       │
                                                                              (else:  DENY
                                                                               fail-closed)
```

---

## 4. Agent Modes × Permission Modes (orthogonal)

Two independent axes. Agent mode = which tools exist. Permission mode = how governed calls are approved.

```
    AGENT MODE (tool availability)                PERMISSION MODE (approval behavior)
    ─────────────────────────────                 ────────────────────────────────────

    ┌─────────────────────────────┐               ┌─────────────────────────────────┐
    │ Edit (default)              │               │ Guardian ◈ (default)            │
    │  full tool set incl. writes │               │  auto-approve safe; ask risky   │
    ├─────────────────────────────┤               ├─────────────────────────────────┤
    │ Plan                        │               │ Argus ◉                         │
    │  read/search/bash/subagent  │               │  ask for EVERY governed call    │
    │  NO file mutation           │               ├─────────────────────────────────┤
    ├─────────────────────────────┤               │ Prometheus ⚡                    │
    │ Ask                         │               │  auto-approve governed calls    │
    │  read/search/bash only      │               │  deny rules still enforced      │
    │  NO subagents, NO lua exec  │               └─────────────────────────────────┘
    │  extra: mutative bash guard │
    └─────────────────────────────┘                     composed per turn:

                                   ┌──────────────────────────────────────────────┐
                                   │           effective approval per call          │
                                   │                                                │
                                   │   tool call                                    │
                                   │     │                                          │
                                   │     ├─ agent mode allows this tool? ─ NO → N/A │
                                   │     │                                  YES    │
                                   │     ▼                                          │
                                   │     ├─ permission chain (section 3 above)      │
                                   │     │                                          │
                                   │     ▼                                          │
                                   │   ALLOW → run   |   ASK → prompt UI            │
                                   │                 |   DENY → reject + inform    │
                                   └──────────────────────────────────────────────┘
```

---

## 5. Subagent Orchestration

The spawning, dependency, and concurrency model.

```
                        PARENT AGENT (depth 0)
                        AgentLoop running in REPL
                              │
                              │ LLM calls subagent tool:
                              │   type, task, id?, depends_on?, group?, mode?
                              ▼
                   ┌─────────────────────────┐
                   │    SubagentTool         │
                   │  validate vs context:   │
                   │   - depth < max?        │
                   │   - type allowed?       │
                   │   - cycle check (DFS)   │
                   └────────────┬────────────┘
                                │
                                ▼
              ┌─────────────────────────────────────────┐
              │        SubagentOrchestrator              │
              │                                         │
              │   spawnAgent(id, task, opts)            │
              └──────────────────┬──────────────────────┘
                                 │
              ┌──────────────────┴──────────────────────┐
              │  1. wait for depends_on agents          │
              │     → inject their results into task    │
              │                                         │
              │  2. acquire GROUP semaphore             │
              │     (same group → sequential)           │
              │                                         │
              │  3. acquire GLOBAL concurrency semaphore│
              │     (default max: 10)                   │
              │                                         │
              │  4. SubagentFactory.createAndRunAgent() │
              │     → new AgentLoop with narrowed tools │
              │     → retry on transient fail (max: 2)  │
              │     → never retry 401/403               │
              └──────────────────┬──────────────────────┘
                                 │
                                 ▼
   ┌─────────────────────────────────────────────────────────────────┐
   │                    CHILD AGENTS (depth 1)                        │
   │                                                                  │
   │   TYPE NARROWING (can only reduce capabilities):                 │
   │                                                                  │
   │   general ──┬──► general  (write + spawn all)                    │
   │             ├──► explore  (read-only + spawn explore)            │
   │             ╰──► plan     (read-only + spawn explore)            │
   │                                                                  │
   │   explore  ─────► explore only                                   │
   │   plan     ─────► explore only                                   │
   │                                                                  │
   │   EXECUTION MODES:                                               │
   │   ┌──────────────┐              ┌───────────────────┐            │
   │   │ await        │              │ background        │            │
   │   │ blocks parent│              │ returns now;      │            │
   │   │ → inline tool│              │ result injected   │            │
   │   │   result     │              │ on a later turn   │            │
   │   └──────────────┘              └───────────────────┘            │
   └─────────────────────────────────────────────────────────────────┘
                                 │
                                 │   each child can itself spawn
                                 │   children (up to max_depth: 3)
                                 ▼
   ┌─────────────────────────────────────────────────────────────────┐
   │                  GRANDCHILDREN (depth 2)                         │
   │             same orchestration, narrowed context                 │
   │             subagent tool removed at max_depth                   │
   └─────────────────────────────────────────────────────────────────┘
```

---

## 6. Context Management Pipeline

Applied in strict order before each LLM call when the context budget is exceeded.

```
     conversation history grows with every turn
                        │
                        ▼
          ┌───────────────────────────┐
          │  ContextBudget.preflight   │
          │  remaining < auto_compact? │
          └─────────────┬─────────────┘
                       │
              over? ───┴─── no? → send to LLM as-is
                │
                ▼
   ╔═══════════════════════════════════════════════════╗
   ║          CONTEXT PIPELINE (ordered stages)         ║
   ╠═══════════════════════════════════════════════════╣
   ║                                                   ║
   ║  ┌─────────────────────────────────────────────┐  ║
   ║  │ 1. OUTPUT TRUNCATION                        │  ║
   ║  │    cap oversized tool results (e.g. huge    │  ║
   ║  │    file reads, long bash output)            │  ║
   ║  └────────────────────┬────────────────────────┘  ║
   ║                       ▼                           ║
   ║  ┌─────────────────────────────────────────────┐  ║
   ║  │ 2. DEDUPLICATION                            │  ║
   ║  │    remove redundant tool outputs            │  ║
   ║  │    (same file read twice, etc.)             │  ║
   ║  └────────────────────┬────────────────────────┘  ║
   ║                       ▼                           ║
   ║  ┌─────────────────────────────────────────────┐  ║
   ║  │ 3. PRUNING                                  │  ║
   ║  │    drop superseded results                  │  ║
   ║  │    (old file_read replaced by file_edit)    │  ║
   ║  │    protect recent N results (prune_protect) │  ║
   ║  └────────────────────┬────────────────────────┘  ║
   ║                       ▼                           ║
   ║  ┌─────────────────────────────────────────────┐  ║
   ║  │ 4. COMPACTION (LLM-based)                   │  ║
   ║  │    summarize older messages → working memo  │  ║
   ║  │    extract durable memories during compact  │  ║
   ║  │    triggers when < auto_compact_buffer      │  ║
   ║  └────────────────────┬────────────────────────┘  ║
   ║                       ▼                           ║
   ║  ┌─────────────────────────────────────────────┐  ║
   ║  │ 5. TRIMMING (emergency fallback)            │  ║
   ║  │    drop oldest messages if still over       │  ║
   ║  │    after compaction (rare)                  │  ║
   ║  └────────────────────┬────────────────────────┘  ║
   ║                       │                           ║
   ╚═══════════════════════╪═══════════════════════════╝
                           ▼
                ┌────────────────────┐
                │  fit within budget  │
                │  → send to LLM      │
                └────────────────────┘

   TOKEN BUDGET THRESHOLDS (configurable):
   ┌──────────────────────────┬──────────┬──────────────────────────────┐
   │ reserve_output_tokens    │  16,000  │ reserved for model response  │
   │ warning_buffer_tokens    │  24,000  │ show warning when below      │
   │ auto_compact_buffer      │  12,000  │ trigger compaction           │
   │ blocking_buffer_tokens   │   3,000  │ hard stop — never overrun    │
   └──────────────────────────┴──────────┴──────────────────────────────┘
```

---

## 7. Renderer Architecture

Composite interface split into 5 focused concerns; 4 concrete implementations.

```
                    ┌───────────────────────────────────────────┐
                    │          RendererInterface                │
                    │  (composite — combines all 5 concerns)    │
                    └──┬───────┬───────┬───────┬────────┬───────┘
                       │       │       │       │        │
            ┌──────────▼┐ ┌────▼────┐ ┌▼─────┐ ┌▼──────┐ ┌▼─────────────┐
            │ Core      │ │ Tool    │ │Dialog│ │Conver-│ │ Subagent      │
            │ Renderer  │ │ Renderer│ │Render│ │sation │ │ Renderer      │
            │ Interface │ │Interface│ │Interf│ │Render │ │ Interface     │
            │           │ │         │ │ace   │ │Interf │ │               │
            │ lifecycle │ │ tool    │ │settings│ │history│ │ spawn/tree/  │
            │ streaming │ │ call/   │ │session│ │ clear/│ │ dashboard    │
            │ status    │ │ result  │ │permis-│ │ replay│ │               │
            │ phase     │ │ display │ │sions  │ │       │ │               │
            └───────────┘ └─────────┘ └──────┘ └───────┘ └──────────────┘

                              IMPLEMENTATIONS

   ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐  ┌────────────┐
   │  TuiRenderer     │  │  AnsiRenderer    │  │ HeadlessRenderer │  │NullRenderer│
   │                  │  │                  │  │                  │  │            │
   │ Symfony TUI      │  │ Pure ANSI codes  │  │ stdout/stderr    │  │ no output  │
   │ Full-screen      │  │ readline input   │  │ for -p / JSON /  │  │ auto-      │
   │ widgets          │  │                  │  │ stream-json      │  │ approve    │
   │                  │  │ CommonMark→ANSI  │  │                  │  │            │
   │ delegates to:    │  │ tempest/highlight│  │                  │  │ (used by   │
   │ · TuiModalMgr    │  │                  │  │                  │  │  headless  │
   │ · TuiAnimationMgr│  │                  │  │                  │  │  subagents)│
   │ · Subagent       │  │                  │  │                  │  │            │
   │   DisplayMgr     │  │                  │  │                  │  │            │
   └────────┬─────────┘  └────────┬─────────┘  └────────┬─────────┘  └────────────┘
            │                     │                     │
            └──────────┬──────────┴─────────────────────┘
                       ▼
              ┌─────────────────┐         shared by all
              │     Theme       │         ─────────────
              │  (mythological) │         · planetary tool icons ☽☉♅⚡⊛✧
              │                 │         · cosmic spinners
              │                 │         · thinking phrases
              └─────────────────┘         · KosmokratorTerminalTheme
                                          (tempest/highlight)
```

---

## 8. Entry Points & Surfaces

All surfaces reuse the same runtime: provider credentials, sessions, permissions,
Lua, integrations, MCP, memory, tasks, subagents.

```
                              ┌─────────────────────┐
                              │   SHARED RUNTIME    │
                              │                     │
                              │  · AgentLoop        │
                              │  · ToolExecutor     │
                              │  · ContextManager   │
                              │  · PermissionEval   │
                              │  · SessionManager   │
                              │  · SubagentOrch     │
                              │  · IntegrationRuntime│
                              │  · McpRuntime       │
                              │  · LuaSandbox       │
                              └──────────┬──────────┘
                                         │
            ┌────────────────┬───────────┼────────────┬────────────────┐
            │                │           │            │                │
            ▼                ▼           ▼            ▼                ▼
     ┌─────────────┐  ┌───────────┐ ┌─────────┐ ┌──────────┐  ┌────────────┐
     │  Terminal    │  │  Headless │ │   SDK   │ │   ACP    │  │ Integrations│
     │  (TUI/ANSI)  │  │  (-p)    │ │ (PHP)   │ │ (JSON-   │  │ / MCP CLI  │
     │              │  │           │ │         │ │  RPC)    │  │            │
     │ bin/kosmo    │  │ kosmo -p  │ │AgentBui │ │ kosmo acp│  │ kosmo      │
     │              │  │           │ │  lder   │ │          │  │ integrations│
     │ interactive  │  │ one-shot  │ │         │ │ editor/  │  │ kosmo mcp:*│
     │ REPL         │  │ text/json │ │ embed   │ │ IDE      │  │            │
     │ slash cmds   │  │ stream    │ │ in app  │ │          │  │ Lua &      │
     │ power cmds   │  │           │ │         │ │ kosmo/*  │  │ direct     │
     │ /settings    │  │           │ │         │ │ extension│  │ calls      │
     └─────────────┘  └───────────┘ └─────────┘ └──────────┘  └────────────┘

              PERSISTENCE LAYER (shared)
     ┌───────────────────────────────────────────────────────┐
     │              SQLite  (~/.kosmo/data/)                  │
     │                                                       │
     │   sessions   messages   memories   settings   tokens   │
     └───────────────────────────────────────────────────────┘

              CONFIG LAYERS (later overrides earlier)
     ┌───────────────────────────────────────────────────────┐
     │  1. config/kosmo.yaml       (bundled defaults)        │
     │  2. config/prism.yaml       (provider definitions)    │
     │  3. ~/.kosmo/config.yaml    (user global)             │
     │  4. .kosmo.yaml             (project overrides)       │
     └───────────────────────────────────────────────────────┘
```

---

## 9. Tool Families

The tool registry, organized by family. Availability is filtered by agent mode.

```
                          ToolRegistry
                              │
        ┌─────────┬───────────┼───────────┬───────────┬──────────────┐
        │         │           │           │           │              │
        ▼         ▼           ▼           ▼           ▼              ▼
   ┌─────────┐┌────────┐┌─────────┐┌─────────┐┌───────────┐┌─────────────┐
   │ CODING  ││ SEARCH ││  SHELL  ││ COORD   ││ INTERACT  ││   MEMORY    │
   │         ││        ││ SESSION ││         ││           ││  & SESSION  │
   ├─────────┤├────────┤├─────────┤├─────────┤├───────────┤├─────────────┤
   │file_read││glob    ││shell_   ││subagent ││ask_user   ││memory_search│
   │file_    ││grep    ││  start  ││task_    ││ask_choice ││memory_save  │
   │  write  ││        ││shell_   ││  create ││           ││session_     │
   │file_    ││        ││  write  ││task_    ││           ││  search     │
   │  edit   ││        ││shell_   ││  update ││           ││session_read │
   │apply_   ││        ││  read   ││task_get ││           ││             │
   │  patch  ││        ││shell_   ││task_list││           ││             │
   │bash     ││        ││  kill   ││         ││           ││             │
   └─────────┘└────────┘└─────────┘└─────────┘└───────────┘└─────────────┘

   ┌─────────────────┐  ┌───────────────────────────────────────────┐
   │      LUA        │  │                  WEB                       │
   ├─────────────────┤  ├───────────────────────────────────────────┤
   │ lua_list_docs   │  │ web_search                                 │
   │ lua_search_docs │  │ web_fetch_external / web_fetch             │
   │ lua_read_doc    │  │ web_extract                                │
   │ execute_lua     │  │ web_crawl                                  │
   └─────────────────┘  └───────────────────────────────────────────┘

   AVAILABILITY BY AGENT MODE:
   ┌──────────┬────────┬────────┬────────┬──────┬────────┬──────┬────────┐
   │ Mode     │ Coding │ Search │ Shell  │Coord │Interact│Memory│ Lua    │
   ├──────────┼────────┼────────┼────────┼──────┼────────┼──────┼────────┤
   │ Edit     │  full  │  full  │  full  │ full │  full  │ full │  full  │
   │ Plan     │  read  │  full  │  full  │ full │  full  │ read │ docs+  │
   │ Ask      │  read  │  full  │  full  │tasks │  full  │ read │ docs   │
   └──────────┴────────┴────────┴────────┴──────┴────────┴──────┴────────┘
```

---

## 10. Stuck Detection (Headless Subagent Loops)

Prevents infinite tool-call loops in non-interactive child agents.

```
     headless subagent making tool calls
                    │
                    ▼
     ┌──────────────────────────────────┐
     │  StuckDetector                   │
     │  rolling window: 8 signatures    │
     │  (signature = tool + args hash)  │
     └──────────────┬───────────────────┘
                    │
                    ▼
        ┌───────────────────────────┐
        │  latest signature         │
        │  appears 3+ times?        │
        └─────────────┬─────────────┘
                      │
                 no ──┴── yes
                 │         │
                 │         ▼
                 │    ┌─────────────────────┐
                 │    │  STAGE 1: NUDGE     │
                 │    │  inject system msg: │
                 │    │  "consolidate"      │
                 │    └──────────┬──────────┘
                 │               │
                 │          +2 turns, still stuck?
                 │               │
                 │          yes──┴── diverse calls? ──yes──► RESET
                 │               │
                 │               ▼
                 │    ┌─────────────────────────────┐
                 │    │  STAGE 2: FINAL NOTICE      │
                 │    │  stronger instruction:      │
                 │    │  "stop now"                 │
                 │    └──────────┬──────────────────┘
                 │               │
                 │          +2 turns, still stuck?
                 │               │
                 │          yes──┴── diverse calls? ──yes──► RESET
                 │               │
                 │               ▼
                 │    ┌─────────────────────────────┐
                 │    │  STAGE 3: FORCE RETURN      │
                 │    │  terminate agent            │
                 │    │  return last response       │
                 │    └─────────────────────────────┘
                 │
                 ▼
            (healthy loop continues)
            detector resets when agent makes
            diverse (non-repeating) calls
```

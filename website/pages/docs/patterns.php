<?php
$docTitle = 'Advanced Patterns';
$docSlug = 'patterns';
ob_start();
?>
<p class="lead">
    Real-world recipes for getting the most out of KosmoKrator. Each pattern
    includes a brief description, a concrete example, and tips about when to
    use it. Adapt these to your own workflow and project.
</p>

<!-- ================================================================== -->
<h2 id="cicd-integration">CI/CD Integration</h2>

<p>
    KosmoKrator can be integrated into CI pipelines to automate code fixes,
    test-driven development loops, and release preparation. Start an
    interactive session with your prompt and let the agent work through the
    task. This is best suited for workflows where a human can trigger the
    run and review the results.
</p>

<h3>Basic CI invocation</h3>

<pre><code># Run KosmoKrator with a prompt directly
kosmokrator "Fix all failing tests"</code></pre>

<p>
    Pass your task as a command-line argument. KosmoKrator starts an
    interactive session with the given prompt. For CI pipelines, use the
    <code>/prometheus</code> slash command inside the session to auto-approve
    all tool calls so the agent can work unattended. Exit code <code>0</code>
    means the task completed successfully; non-zero indicates an error or
    that the agent could not finish within its turn budget.
</p>

<h3>GitHub Actions workflow</h3>

<pre><code>name: Auto-fix failing tests

on:
  workflow_dispatch:
  issue_comment:
    types: [created]

jobs:
  fix:
    runs-on: ubuntu-latest
    if: |
      github.event_name == 'workflow_dispatch' ||
      (github.event.issue.pull_request &&
       startsWith(github.event.comment.body, '/fix'))

    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Run KosmoKrator
        env:
          ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}
        run: |
          kosmokrator "Fix all failing tests"

      - name: Commit and push fixes
        run: |
          git config user.name "kosmokrator[bot]"
          git config user.email "bot@example.com"
          git diff --quiet || (git add -A && git commit -m "fix: resolve failing tests" && git push)</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> For CI runs, use the <code>/prometheus</code>
        slash command at the start of the session to auto-approve all tool
        calls so the agent can work unattended. This is the equivalent of a
        fully permissive permission mode. See
        <a href="/docs/permissions">Permissions</a> for details.
    </p>
</div>

<h3>Exit codes and output parsing</h3>

<table>
    <thead>
        <tr>
            <th>Exit Code</th>
            <th>Meaning</th>
            <th>Typical Action</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>0</code></td>
            <td>Task completed successfully</td>
            <td>Continue pipeline</td>
        </tr>
        <tr>
            <td><code>1</code></td>
            <td>Agent error or task failure</td>
            <td>Log output, notify team</td>
        </tr>
        <tr>
            <td><code>2</code></td>
            <td>Configuration or startup error</td>
            <td>Check config, API keys</td>
        </tr>
    </tbody>
</table>

<!-- ================================================================== -->
<h2 id="multi-model-cost-optimization">Multi-Model Cost Optimization</h2>

<p>
    Not every agent turn needs the most expensive model. KosmoKrator supports
    <strong>per-depth model overrides</strong> so you can assign powerful models
    to the main agent and cheaper, faster models to subagents. This can reduce
    costs dramatically with minimal impact on quality.
</p>

<h3>Per-depth cascade</h3>

<pre><code># .kosmokrator.yaml

# Main agent — most capable model for complex reasoning
default_provider: anthropic
default_model: claude-opus-4-5-20250415

# Depth-1 subagents — fast and affordable
subagent_provider: anthropic
subagent_model: claude-haiku-4-5-20250415

# Depth-2+ subagents — lightest tier for bulk work
subagent_depth2_provider: anthropic
subagent_depth2_model: claude-haiku-4-5-20250415</code></pre>

<h3>Mixed-provider strategy</h3>

<p>
    You can also mix providers across depths. For example, use Claude for the
    main agent and GPT for subagents:
</p>

<pre><code># Main agent — Claude for deep reasoning
default_provider: anthropic
default_model: claude-opus-4-5-20250415

# Subagents — GPT for fast exploration
subagent_provider: openai
subagent_model: gpt-4.1-mini

# Depth-2+ — smallest model for trivial tasks
subagent_depth2_provider: openai
subagent_depth2_model: gpt-4.1-mini</code></pre>

<h3>Approximate cost comparison</h3>

<table>
    <thead>
        <tr>
            <th>Tier</th>
            <th>Agent</th>
            <th>Example Model</th>
            <th>Relative Cost</th>
            <th>Best For</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Premium</strong></td>
            <td>Main agent (depth 0)</td>
            <td>Claude Opus, GPT-4.1</td>
            <td>~15&ndash;75 &cent;/1K tokens</td>
            <td>Complex reasoning, architecture</td>
        </tr>
        <tr>
            <td><strong>Standard</strong></td>
            <td>Subagents (depth 1)</td>
            <td>Claude Sonnet, GPT-4.1-mini</td>
            <td>~0.6&ndash;3 &cent;/1K tokens</td>
            <td>Coding, research, file edits</td>
        </tr>
        <tr>
            <td><strong>Economy</strong></td>
            <td>Sub-subagents (depth 2+)</td>
            <td>Claude Haiku, GPT-4.1-mini</td>
            <td>~0.1&ndash;0.8 &cent;/1K tokens</td>
            <td>Bulk grep, simple transforms</td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p>
        <strong>Tip:</strong> For routine coding tasks, a Standard-tier model at
        depth 0 is often sufficient. Reserve Premium models for architecture
        decisions and complex debugging sessions. See
        <a href="/docs/providers">Providers</a> for the full per-depth override
        reference.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="large-codebase-exploration">Large Codebase Exploration</h2>

<p>
    Working with a big monorepo or unfamiliar project? Use KosmoKrator's
    exploration features to build understanding before making changes. The key
    is to start broad, then fan out into targeted investigation.
</p>

<h3>Step-by-step workflow</h3>

<ol>
    <li>
        <strong>Onboard the project</strong> &mdash; Run <code>:deepinit</code>
        to generate a comprehensive project summary. This gives the agent a
        high-level map of the codebase structure, conventions, and key files.
    </li>
    <li>
        <strong>Fan out exploration</strong> &mdash; Use <code>:team</code> to
        run a 5-stage sequential pipeline (Planner, Architect, Executor,
        Verifier, Fixer) for structured, thorough investigation of each
        subsystem.
    </li>
    <li>
        <strong>Analyze without changes</strong> &mdash; Switch to
        <code>/plan</code> mode so the agent can read and search freely but
        cannot modify files. This is safer for initial reconnaissance.
    </li>
    <li>
        <strong>Reduce concurrency</strong> &mdash; For very large repos,
        lower the <code>subagent_concurrency</code> setting to avoid
        overwhelming system resources.
    </li>
</ol>

<pre><code># .kosmokrator.yaml — tuned for a large monorepo

subagent_concurrency: 3
mode: plan          # start in plan mode for safety

# Use a fast model for exploration agents
subagent_provider: anthropic
subagent_model: claude-haiku-4-5-20250415</code></pre>

<pre><code># In-session workflow

# 1. Build the project map
:deepinit

# 2. Run a structured pipeline to understand each module
:team Explore the authentication module and summarize its public API
:team Explore the database layer and document all migration files

# 3. Switch to plan mode for safe analysis
/plan</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> The <code>:deepinit</code> command writes its
        output to an <code>AGENTS.md</code> file in your project root. This
        file is automatically loaded in future sessions, giving the agent a
        persistent project map. You can also commit it to version control to
        share with your team.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="code-review-workflow">Code Review Workflow</h2>

<p>
    KosmoKrator's <a href="/docs/commands">power commands</a> provide several
    review strategies ranging from quick feedback to aggressive auto-fix.
    Choose the level of autonomy that matches your team's workflow.
</p>

<h3>Review strategies</h3>

<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Behavior</th>
            <th>When to Use</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>:review</code></td>
            <td>Standard code review with suggestions</td>
            <td>Quick feedback, PR ready for review</td>
        </tr>
        <tr>
            <td><code>:unleash :review</code></td>
            <td>Chained: unleash swarm + review prompts combined</td>
            <td>Deep multi-angle review with maximum coverage</td>
        </tr>
        <tr>
            <td><code>:team :review</code></td>
            <td>Chained: team pipeline + review prompts combined</td>
            <td>Structured pipeline review with verification</td>
        </tr>
    </tbody>
</table>

<h3>Safe review with Plan mode</h3>

<pre><code># Review without any risk of changes
/plan
:review src/Module/NewFeature.php

# The agent will read and analyze but cannot write.
# When you are happy with the suggestions, switch back:
/edit</code></pre>

<h3>Parallel multi-file review</h3>

<pre><code># Review changed files in parallel
:team :review src/Auth/LoginHandler.php src/Auth/SessionManager.php src/Auth/Middleware.php</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> Use <code>/plan</code> mode when reviewing code
        you don't want modified. The agent can still read, search, and analyze
        freely but cannot write any files. Switch to <code>/edit</code> when
        you are ready to apply fixes.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="migration-refactoring">Migration &amp; Refactoring</h2>

<p>
    Large-scale migrations and refactors benefit from a phased approach:
    understand first, plan second, implement third. KosmoKrator's agent types
    and power commands map naturally to this workflow.
</p>

<h3>Phase 1: Understand the current architecture</h3>

<pre><code># Deep dive into the codebase to map dependencies and conventions
:research :deepdive

# Example prompt:
# "Map every class that implements the old Repository interface.
#  For each one, list the file path, the methods it provides,
#  and every caller in the codebase."</code></pre>

<h3>Phase 2: Create a migration plan</h3>

<pre><code># Switch to plan mode so nothing is changed yet
/plan

# Ask the agent to design the migration strategy:
# "Based on the research above, create a step-by-step migration plan
#  that converts all Repository implementations to the new Store interface.
#  Group changes into independent modules that can be migrated separately.
#  Identify risky changes that need human review."</code></pre>

<h3>Phase 3: Implement module by module</h3>

<pre><code># Switch to edit mode for implementation
/edit

# Use a sequential group to implement changes in order
# (each agent waits for the previous one to finish)
"Spawn a sequential group called 'migration'.
 Module 1: Convert UserRepository to implement Store interface.
 Module 2: Convert OrderRepository to implement Store interface.
 Module 3: Update all callers to use the new Store methods."</code></pre>

<h3>Critical decisions with consensus</h3>

<pre><code># Use :consensus for architectural choices that matter
:consensus Should we keep backward-compatible aliases or do a clean break?

# The agent spawns multiple perspectives and weighs trade-offs
# before recommending a path forward.</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> For migrations that span many files, use
        <a href="/docs/agents">sequential groups</a> to enforce ordering.
        This prevents race conditions where a later module depends on changes
        from an earlier one. The <code>:consensus</code> power command is
        valuable for any decision with significant trade-offs.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="swarm-orchestration">Swarm Orchestration</h2>

<p>
    KosmoKrator's <a href="/docs/agents">subagent system</a> supports
    dependency graphs, parallel execution, sequential groups, and background
    mode. Combining these primitives lets you build sophisticated multi-agent
    workflows.
</p>

<h3>Dependency DAG: explore &rarr; plan &rarr; implement &rarr; verify</h3>

<pre><code># A four-phase pipeline where each phase depends on the previous

"Spawn the following agents:

 1. 'explore' (type: explore, mode: await)
    Task: Investigate how payment processing works and list all edge cases.

 2. 'plan' (type: plan, mode: await, depends_on: ['explore'])
    Task: Based on the exploration results, design a retry mechanism for failed payments.

 3. 'implement' (type: general, mode: await, depends_on: ['plan'])
    Task: Implement the retry mechanism as designed.

 4. 'verify' (type: explore, mode: await, depends_on: ['implement'])
    Task: Review the implementation for correctness and edge cases."</code></pre>

<h3>Background exploration</h3>

<pre><code># Fire off research agents in parallel without blocking the main agent

"Spawn these agents in background mode:

 - 'auth-research' (type: explore, mode: background)
   Task: Map the authentication flow end-to-end.

 - 'db-research' (type: explore, mode: background)
   Task: Document the database schema and all migration files.

 - 'api-research' (type: explore, mode: background)
   Task: List every public API endpoint and its authentication requirements."

# The main agent continues working while these run.
# Check status with /agents.</code></pre>

<h3>Sequential group for ordered pipelines</h3>

<pre><code># Agents in the same group run one at a time, in order

"Spawn a group called 'refactor-queue':

 1. (type: general) Extract validation logic into a shared module.
 2. (type: general) Update all endpoints to use the new validation module.
 3. (type: general) Add tests for the shared validation module."</code></pre>

<h3>Monitoring the swarm</h3>

<pre><code># Open the swarm dashboard to see all active agents
/agents

# This shows each agent's status, type, depth, dependencies,
# and whether it is running, waiting, or completed.</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> Use <strong>background mode</strong> for
        exploration and research tasks that don't need to block the main
        agent. Use <strong>await mode</strong> when the main agent needs the
        results before continuing. Use <strong>groups</strong> when ordering
        matters between subagents.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="session-continuity">Session Continuity</h2>

<p>
    Complex tasks often span multiple sessions. KosmoKrator provides several
    mechanisms to preserve context and resume work seamlessly.
</p>

<h3>Resuming a session</h3>

<pre><code># List previous sessions
/sessions

# Resume the most recent session
/resume

# Resume a specific session by name or ID
/resume auth-migration</code></pre>

<p>
    The <code>/resume</code> command restores the full conversation history,
    loaded files, and agent state. You pick up exactly where you left off.
</p>

<h3>Memory across sessions</h3>

<p>
    KosmoKrator's <a href="/docs/context">memory system</a> persists key facts
    across sessions automatically. The agent saves project architecture,
    conventions, and decisions so it doesn't need to re-learn them each time.
</p>

<pre><code># Memories are saved automatically, but you can also manage them:

# View all saved memories
/memories

# The agent will recall relevant memories at the start of each session,
# including project facts, user preferences, and past decisions.</code></pre>

<h3>Managing context window pressure</h3>

<pre><code># When the conversation gets long, compress it:
/compact

# Rename a session for easy identification later:
/rename payment-refactor-phase2</code></pre>

<p>
    The <code>/compact</code> command summarizes the conversation so far and
    replaces it with a condensed version, freeing token budget for continued
    work. Use it when you notice the agent's responses slowing down or when
    working on long sessions.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Get into the habit of using <code>/rename</code>
        to give sessions descriptive names. This makes <code>/resume</code>
        much easier when you have dozens of past sessions. Combine with
        <code>/compact</code> periodically during long sessions to keep
        performance snappy.
    </p>
</div>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';

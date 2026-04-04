<?php
$docTitle = 'Agents';
$docSlug = 'agents';
ob_start();
?>
<p class="lead">
    KosmoKrator's agent system is built around a hierarchy of autonomous agents
    that can read, write, search, and execute code. The main interactive agent
    operates in one of three modes, and it can spawn child agents (subagents)
    that run independently with scoped capabilities. Subagents can form
    dependency graphs, run in parallel or sequentially, and are monitored by
    stuck detection and watchdog systems to ensure they converge.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="interactive-agent-modes">Interactive Agent Modes</h2>

<p>
    The main agent operates in one of three modes that control which tools are
    available and what actions the agent is permitted to take. The mode is set
    at session start and can be changed at any time during a conversation using
    slash commands.
</p>

<h3 id="mode-edit">Edit Mode (Default)</h3>

<p>
    Edit mode gives the agent full access to every tool in the toolbox. It can
    read files, write new files, edit existing files, execute shell commands,
    save memories, and spawn subagents. This is the default mode and the one
    you will use for most coding tasks.
</p>

<h3 id="mode-plan">Plan Mode</h3>

<p>
    Plan mode restricts the agent to read-only operations. It can read files,
    search the codebase, and execute bash commands that do not modify the
    filesystem, but it cannot write or edit any files. This mode is designed
    for analyzing a codebase and proposing changes without making them. The
    agent can still spawn subagents to distribute the analysis work.
</p>

<h3 id="mode-ask">Ask Mode</h3>

<p>
    Ask mode is the most restricted interactive mode. Like Plan mode, the agent
    can read files and run read-only bash commands, but it cannot spawn
    subagents. This mode is intended for quick question-and-answer interactions
    where you want the agent to reference files for context but not take any
    autonomous action.
</p>

<h3 id="mode-comparison">Mode Comparison</h3>

<table>
    <thead>
        <tr>
            <th>Mode</th>
            <th>Can Read</th>
            <th>Can Write</th>
            <th>Can Bash</th>
            <th>Can Subagent</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Edit</strong></td>
            <td>Yes</td>
            <td>Yes</td>
            <td>Yes</td>
            <td>Yes</td>
        </tr>
        <tr>
            <td><strong>Plan</strong></td>
            <td>Yes</td>
            <td>No</td>
            <td>Read-only bash</td>
            <td>Yes</td>
        </tr>
        <tr>
            <td><strong>Ask</strong></td>
            <td>Yes</td>
            <td>No</td>
            <td>Read-only bash</td>
            <td>No</td>
        </tr>
    </tbody>
</table>

<p>
    Switch between modes at any time during a session using the slash commands
    <code>/edit</code>, <code>/plan</code>, and <code>/ask</code>. The mode
    change takes effect immediately for the next agent turn.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Plan mode is useful when you want the agent to
        study a large codebase and produce a detailed implementation plan
        before you switch to Edit mode to execute it. This two-phase workflow
        reduces the risk of the agent making changes you did not expect.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="subagent-types">Subagent Types</h2>

<p>
    When the main agent (or another subagent) needs to delegate work, it spawns
    a child agent called a <em>subagent</em>. Every subagent has a type that
    determines its capabilities, which tools it can access, and what kinds of
    children it can spawn in turn. The type system enforces a strict principle:
    <strong>a child can never escalate capabilities beyond its parent.</strong>
</p>

<table>
    <thead>
        <tr>
            <th>Type</th>
            <th>Capabilities</th>
            <th>Can Spawn</th>
            <th>Use Case</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>General</strong></td>
            <td>Full: read, write, edit, bash, subagent</td>
            <td>General, Explore, Plan</td>
            <td>Autonomous coding tasks</td>
        </tr>
        <tr>
            <td><strong>Explore</strong></td>
            <td>Read-only: file_read, glob, grep, bash</td>
            <td>Explore only</td>
            <td>Research and investigation</td>
        </tr>
        <tr>
            <td><strong>Plan</strong></td>
            <td>Read-only: file_read, glob, grep, bash</td>
            <td>Explore only</td>
            <td>Planning and architecture</td>
        </tr>
    </tbody>
</table>

<p>
    A <strong>General</strong> subagent has the full tool set and can spawn any
    type of child, making it the most powerful and flexible option. Use it when
    the delegated task requires making changes to the codebase.
</p>

<p>
    An <strong>Explore</strong> subagent is restricted to read-only tools. It
    can read files, search with glob and grep, and run bash commands, but it
    cannot write or edit anything. Its children are also restricted to Explore
    type. Use it for research tasks like "find all usages of this function" or
    "investigate how the caching layer works."
</p>

<p>
    A <strong>Plan</strong> subagent has the same tool access as Explore but is
    semantically intended for architecture and planning tasks. It can spawn
    Explore children to gather information but cannot spawn General children.
    Use it for tasks like "design a migration strategy" or "propose an API
    for this feature."
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Prefer the most restrictive subagent type that
        can accomplish the task. Using Explore subagents for research and Plan
        subagents for analysis reduces the blast radius if something goes wrong
        and makes it clear to the LLM that it should not attempt writes.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="spawning-subagents">Spawning Subagents</h2>

<p>
    The LLM spawns subagents by calling the <code>subagent</code> tool. This
    tool accepts several parameters that control the subagent's identity, type,
    execution mode, and relationship to other agents.
</p>

<table>
    <thead>
        <tr>
            <th>Parameter</th>
            <th>Type</th>
            <th>Required</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>task</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>
                A description of what the subagent should do. This becomes
                the subagent's system prompt and should be specific and
                actionable.
            </td>
        </tr>
        <tr>
            <td><code>type</code></td>
            <td>string</td>
            <td>No</td>
            <td>
                One of <code>general</code>, <code>explore</code>, or
                <code>plan</code>. Defaults to <code>general</code>.
            </td>
        </tr>
        <tr>
            <td><code>mode</code></td>
            <td>string</td>
            <td>No</td>
            <td>
                One of <code>await</code> or <code>background</code>.
                Defaults to <code>await</code>.
            </td>
        </tr>
        <tr>
            <td><code>id</code></td>
            <td>string</td>
            <td>No</td>
            <td>
                A custom agent ID that other agents can reference in their
                <code>depends_on</code> field. If omitted, the system
                generates an ID automatically.
            </td>
        </tr>
        <tr>
            <td><code>depends_on</code></td>
            <td>array</td>
            <td>No</td>
            <td>
                A list of agent IDs that this subagent depends on. The
                subagent will not start until all dependencies have completed.
            </td>
        </tr>
        <tr>
            <td><code>group</code></td>
            <td>string</td>
            <td>No</td>
            <td>
                A sequential group name. Agents in the same group run one at
                a time in the order they were spawned.
            </td>
        </tr>
    </tbody>
</table>

<!-- ------------------------------------------------------------------ -->
<h2 id="execution-modes">Execution Modes</h2>

<p>
    Every subagent runs in one of two execution modes that control whether the
    parent blocks while waiting for the result.
</p>

<h3 id="await-mode">Await Mode</h3>

<p>
    In await mode (<code>mode: "await"</code>), the parent agent blocks until
    the subagent completes. The subagent's result is returned directly as the
    tool call response, and the parent can immediately use it in its next
    reasoning step. This is the default execution mode.
</p>

<p>
    Use await mode when the parent needs the result before it can continue.
    For example, if the parent asks a subagent to analyze a module's API and
    then wants to use that analysis to write an integration, the subagent
    should run in await mode so the analysis is available immediately.
</p>

<h3 id="background-mode">Background Mode</h3>

<p>
    In background mode (<code>mode: "background"</code>), the parent agent
    continues immediately after spawning the subagent. The subagent runs in
    parallel, and its results are injected into the parent's context on the
    next LLM turn after the subagent completes.
</p>

<p>
    Use background mode when the parent can make progress on other work while
    the subagent runs. For example, spawning three subagents to research
    different parts of the codebase in parallel, then synthesizing the results.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Background mode is essential for parallelism.
        If you spawn multiple await-mode subagents, they run sequentially
        because each one blocks the parent. To run them in parallel, use
        background mode and let the results arrive asynchronously.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="dependency-dags">Dependency DAGs</h2>

<p>
    Subagents can declare dependencies on other subagents, forming a directed
    acyclic graph (DAG) of execution. When a subagent specifies
    <code>depends_on</code>, it will not start until every agent in that list
    has completed successfully. The results of completed dependencies are
    automatically injected into the dependent agent's task prompt, giving it
    access to the information it needs.
</p>

<pre><code># Spawned by the LLM as three separate subagent tool calls:

subagent(id: "analyze-api", task: "Analyze the REST API endpoints", type: "explore", mode: "background")

subagent(id: "analyze-db", task: "Analyze the database schema", type: "explore", mode: "background")

subagent(id: "write-integration", task: "Write the integration layer",
         depends_on: ["analyze-api", "analyze-db"], mode: "background")</code></pre>

<p>
    In this example, <code>write-integration</code> will not start until both
    <code>analyze-api</code> and <code>analyze-db</code> have finished. When
    it starts, the results from both analysis agents are included in its
    prompt so it can use them to inform the integration code.
</p>

<p>
    Before any subagent is spawned, the system performs a depth-first search
    (DFS) on the dependency graph to detect circular dependencies. If a cycle
    is found, the spawn is rejected with an error rather than risking a
    deadlock.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Dependency DAGs are particularly powerful for
        multi-step workflows. The LLM can declare the entire graph up front
        in a single turn, and the orchestrator handles scheduling, waiting,
        and result injection automatically.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="sequential-groups">Sequential Groups</h2>

<p>
    Sequential groups provide a simpler alternative to dependency DAGs when you
    need ordered execution without explicit dependency wiring. By assigning the
    same <code>group</code> name to multiple subagents, you ensure they run one
    at a time in the order they were spawned. Different groups run in parallel
    with each other.
</p>

<pre><code># Pipeline group: runs sequentially in spawn order
subagent(task: "Analyze the test failures", type: "explore", group: "pipeline", mode: "background")
subagent(task: "Fix the failing tests", type: "general", group: "pipeline", mode: "background")
subagent(task: "Run the test suite to verify", type: "general", group: "pipeline", mode: "background")

# Docs group: runs in parallel with the pipeline group, but sequential within
subagent(task: "Update the API docs", type: "general", group: "docs", mode: "background")
subagent(task: "Update the changelog", type: "general", group: "docs", mode: "background")</code></pre>

<p>
    In this example, the three "pipeline" agents run one after another:
    first analyze, then fix, then verify. The two "docs" agents also run
    sequentially relative to each other. But the pipeline and docs groups run
    in parallel &mdash; the docs work does not wait for the pipeline to finish.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Use sequential groups for ordered pipelines
        where each step builds on the previous one but you do not need
        explicit result injection. If you need the output of one agent passed
        into the next, use dependency DAGs instead.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="concurrency-control">Concurrency Control</h2>

<p>
    KosmoKrator enforces multiple layers of concurrency control to prevent
    resource exhaustion and ensure predictable behavior.
</p>

<h3 id="global-semaphore">Global Semaphore</h3>

<p>
    A global semaphore limits the total number of concurrently running agents.
    The default limit is <strong>10</strong> concurrent agents, configurable
    via the <code>max_concurrent</code> setting. When the limit is reached,
    newly spawned agents are queued and start as soon as a slot becomes
    available.
</p>

<h3 id="group-semaphores">Per-Group Semaphores</h3>

<p>
    Each sequential group has its own semaphore with a concurrency of 1,
    ensuring that agents within the same group run strictly one at a time in
    spawn order. This is enforced independently of the global semaphore.
</p>

<h3 id="slot-yielding">Slot Yielding</h3>

<p>
    When a parent agent spawns a child, it yields its concurrency slot to the
    child. After the child completes, the parent reclaims its slot and
    continues. This mechanism prevents a common deadlock scenario: without
    slot yielding, a parent could hold a slot while waiting for a child that
    is itself waiting for a slot.
</p>

<h3 id="max-depth">Max Depth</h3>

<p>
    The agent hierarchy has a maximum depth of <strong>3 levels</strong> by
    default (main agent at depth 0, its children at depth 1, grandchildren at
    depth 2). This limit is configurable via the <code>max_depth</code>
    setting. Attempts to spawn subagents beyond the maximum depth are rejected
    with an error.
</p>

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
            <td><code>max_concurrent</code></td>
            <td>10</td>
            <td>Maximum number of agents running at the same time</td>
        </tr>
        <tr>
            <td><code>max_depth</code></td>
            <td>3</td>
            <td>Maximum nesting depth of the agent hierarchy</td>
        </tr>
    </tbody>
</table>

<!-- ------------------------------------------------------------------ -->
<h2 id="stuck-detection">Stuck Detection</h2>

<p>
    Subagents run autonomously without human oversight, which means they can
    get stuck in repetitive loops &mdash; calling the same tool with the same
    arguments over and over without making progress. KosmoKrator's stuck
    detector monitors every headless subagent for this pattern and intervenes
    with a three-stage escalation process.
</p>

<h3 id="stuck-how-it-works">How It Works</h3>

<p>
    The stuck detector maintains a rolling window of the last
    <strong>8 tool call signatures</strong> for each subagent. A signature is
    derived from the tool name and its arguments. After each tool call, the
    detector checks whether any single signature appears
    <strong>3 or more times</strong> within the window. If it does, escalation
    begins.
</p>

<h3 id="stuck-escalation">Escalation Stages</h3>

<ul>
    <li>
        <strong>Stage 1 &mdash; Nudge:</strong> A gentle system message is
        injected into the subagent's context, prompting it to try a different
        approach. The message explains that the agent appears to be repeating
        itself and suggests alternative strategies.
    </li>
    <li>
        <strong>Stage 2 &mdash; Final Notice:</strong> A firmer system message
        warns the subagent that it will be terminated if it does not change
        course. This gives the LLM one last chance to break out of the loop.
    </li>
    <li>
        <strong>Stage 3 &mdash; Force Return:</strong> The subagent is
        terminated immediately. Any partial results it has produced so far are
        collected and returned to the parent agent with a note explaining that
        the subagent was terminated due to repetitive behavior.
    </li>
</ul>

<p>
    The escalation counter resets whenever the tool call pattern changes &mdash;
    that is, when the subagent starts calling different tools or using different
    arguments. This means a brief repetition followed by a change in approach
    will not trigger escalation.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Stuck detection is only active for headless
        subagents. The main interactive agent is not subject to stuck
        detection because you, the user, can intervene manually at any time.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="watchdog-timers">Watchdog Timers</h2>

<p>
    In addition to stuck detection, every agent has a configurable idle
    timeout that acts as a safety net against agents that stall entirely
    &mdash; for example, waiting indefinitely for an API response that will
    never come, or entering a state where no tool calls are made at all.
</p>

<table>
    <thead>
        <tr>
            <th>Agent Type</th>
            <th>Default Timeout</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Main (interactive) agent</td>
            <td>900 seconds (15 minutes)</td>
        </tr>
        <tr>
            <td>Subagents</td>
            <td>600 seconds (10 minutes)</td>
        </tr>
    </tbody>
</table>

<p>
    If an agent makes no progress (no tool calls, no LLM responses) within
    its timeout window, it is automatically cancelled. For subagents, any
    partial results are returned to the parent along with a timeout notice.
    This prevents resource waste from agents that are truly stuck rather than
    merely slow.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="auto-retry">Auto-Retry</h2>

<p>
    When a subagent fails due to a transient error, KosmoKrator can
    automatically retry it with exponential backoff and jitter. This is
    particularly useful for handling temporary LLM API issues without
    requiring human intervention.
</p>

<h3 id="retry-behavior">Retry Behavior</h3>

<ul>
    <li>
        <strong>Max retries:</strong> Configurable, with a default of
        <strong>3</strong> attempts. After the final retry fails, the error
        is returned to the parent agent.
    </li>
    <li>
        <strong>Backoff:</strong> Each retry waits longer than the last, using
        exponential backoff with random jitter to avoid thundering herd
        problems when many agents fail simultaneously.
    </li>
    <li>
        <strong>Fresh context:</strong> Each retry starts with a fresh context
        window, so accumulated errors from previous attempts do not pollute
        the new attempt.
    </li>
</ul>

<h3 id="retry-classification">Error Classification</h3>

<table>
    <thead>
        <tr>
            <th>Error Type</th>
            <th>Retried?</th>
            <th>Reason</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Rate limit (429)</td>
            <td>Yes</td>
            <td>Temporary; waiting usually resolves it</td>
        </tr>
        <tr>
            <td>Server error (5xx)</td>
            <td>Yes</td>
            <td>Transient server-side failures</td>
        </tr>
        <tr>
            <td>Network/timeout errors</td>
            <td>Yes</td>
            <td>Temporary connectivity issues</td>
        </tr>
        <tr>
            <td>Auth errors (401/403)</td>
            <td>No</td>
            <td>Invalid credentials will not self-resolve</td>
        </tr>
        <tr>
            <td>Client errors (4xx)</td>
            <td>No</td>
            <td>Bad requests indicate a logic problem</td>
        </tr>
    </tbody>
</table>

<!-- ------------------------------------------------------------------ -->
<h2 id="swarm-dashboard">Swarm Dashboard</h2>

<p>
    When subagents are active, the swarm dashboard provides a real-time
    overview of all running, queued, completed, and failed agents. It is the
    primary interface for monitoring complex multi-agent workflows.
</p>

<h3 id="dashboard-access">Accessing the Dashboard</h3>

<p>
    Open the swarm dashboard with either of these methods:
</p>

<ul>
    <li>
        Press <code>Ctrl+A</code> at any time during a session
    </li>
    <li>
        Type the <code>/agents</code> slash command
    </li>
</ul>

<p>
    The dashboard opens as an overlay and auto-refreshes every 2 seconds while
    it is visible.
</p>

<h3 id="dashboard-contents">What the Dashboard Shows</h3>

<p>
    Each agent in the swarm is displayed with the following information:
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
            <td>Status</td>
            <td>
                Current state: <strong>running</strong>,
                <strong>done</strong>, <strong>queued</strong>,
                <strong>failed</strong>, or <strong>waiting</strong>
                (blocked on dependencies)
            </td>
        </tr>
        <tr>
            <td>Progress</td>
            <td>
                A live progress bar showing estimated completion percentage
            </td>
        </tr>
        <tr>
            <td>Tokens In / Out</td>
            <td>
                Input and output token counts for the agent's LLM calls
            </td>
        </tr>
        <tr>
            <td>Cost</td>
            <td>
                Estimated cost of the agent's LLM usage so far
            </td>
        </tr>
        <tr>
            <td>Elapsed Time</td>
            <td>
                Wall-clock time since the agent started executing
            </td>
        </tr>
        <tr>
            <td>Throughput</td>
            <td>
                Tokens per second for the agent's LLM calls
            </td>
        </tr>
    </tbody>
</table>

<p>
    The dashboard also shows the overall swarm topology &mdash; parent-child
    relationships, dependency edges, and group memberships &mdash; giving you
    a clear picture of how the agents relate to each other.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> The swarm dashboard is available in both the TUI
        and ANSI renderers. In TUI mode it renders as an interactive overlay
        widget; in ANSI mode it prints a formatted table to the terminal.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="putting-it-together">Putting It All Together</h2>

<p>
    The agent system's components work together to enable complex, autonomous
    coding workflows. Here is a typical example of how they interact:
</p>

<ol>
    <li>
        You start a session in <strong>Edit mode</strong> and describe a
        feature that spans several modules.
    </li>
    <li>
        The main agent spawns an <strong>Explore</strong> subagent in
        background mode to research the relevant parts of the codebase.
    </li>
    <li>
        Simultaneously, it spawns a <strong>Plan</strong> subagent to design
        the architecture, with a <code>depends_on</code> reference to the
        Explore agent so it gets the research results.
    </li>
    <li>
        Once both complete, the main agent reads their results and spawns
        multiple <strong>General</strong> subagents in a sequential group to
        implement the changes module by module.
    </li>
    <li>
        The <strong>concurrency controls</strong> ensure no more than 10
        agents run at once. The <strong>stuck detector</strong> monitors each
        subagent for repetitive loops. The <strong>watchdog timer</strong>
        catches any agent that stalls completely.
    </li>
    <li>
        You watch the progress in the <strong>swarm dashboard</strong>,
        seeing each agent's status, token usage, and cost in real time.
    </li>
    <li>
        If a subagent hits a rate limit, <strong>auto-retry</strong> handles
        the transient failure transparently.
    </li>
</ol>

<p>
    This combination of typed agents, execution modes, dependency management,
    concurrency control, and monitoring makes it possible to tackle large
    coding tasks that would be impractical for a single agent working alone.
</p>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';

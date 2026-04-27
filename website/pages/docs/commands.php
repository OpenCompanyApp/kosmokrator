<?php
$docTitle = 'Commands';
$docSlug = 'commands';
ob_start();
?>

<p class="lead">
    KosmoKrator provides three command systems for controlling the agent: <strong>slash commands</strong>
    typed at the input prompt with a <code>/</code> prefix, <strong>power commands</strong>
    prefixed with <code>:</code> that activate specialized agent behaviors, and <strong>skill
    commands</strong> prefixed with <code>$</code> that invoke reusable skill templates. This page
    covers the interactive command surface plus the main shell-level commands you are expected to
    run directly.
</p>

<div class="tip">
    All command types support Tab autocompletion in the input prompt. Start typing <code>/</code>,
    <code>:</code>, or <code>$</code> and press Tab to see matching options.
</div>


<!-- ================================================================== -->
<h2 id="slash-commands">Slash Commands</h2>
<!-- ================================================================== -->

<p>
    Slash commands are typed directly into the input prompt, prefixed with <code>/</code>. They
    control session management, agent modes, permissions, context, and various utilities. Most
    slash commands take effect immediately and do not consume LLM tokens.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="session-management">Session Management</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-new"><code>/new</code></h4>
<p>
    Clear the current conversation and start a fresh session. Cancels any running subagents, clears
    conversation history, resets the session cost, resets permission grants, and sets the permission
    mode back to <strong>Guardian</strong>. The system prompt is regenerated for the new session.
</p>

<h4 id="cmd-resume"><code>/resume</code></h4>
<p>
    Open the session picker to resume a previous conversation. Displays a list of recent sessions
    with dates, model information, and a preview of the last message. Select a session to restore
    the full conversation history and continue where you left off.
</p>

<h4 id="cmd-sessions"><code>/sessions</code></h4>
<p>
    List all recent sessions. Shows session IDs, timestamps, models used, and message counts.
    Useful for reviewing past work without committing to resuming a specific session.
</p>

<h4 id="cmd-rename"><code>/rename [name]</code></h4>
<p>
    Rename the current session for easier identification later. If no name is provided, shows the
    usage message. Named sessions are easier to find when using <code>/resume</code> or
    <code>/sessions</code>.
</p>
<pre><code>/rename Refactor payment module</code></pre>

<h4 id="cmd-quit"><code>/quit</code> <small class="text-muted">(aliases: <code>/exit</code>, <code>/q</code>)</small></h4>
<p>
    Save the current session and exit KosmoKrator. The session is persisted to SQLite and can
    be resumed in a future invocation. Equivalent to pressing <code>Ctrl+C</code> at an idle prompt.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="mode-switching">Mode Switching</h3>
<!-- ------------------------------------------------------------------ -->

<p>
    KosmoKrator operates in one of three modes that determine what the agent is allowed to do.
    Switching modes takes effect immediately for the next agent turn.
</p>

<h4 id="cmd-edit"><code>/edit</code></h4>
<p>
    Switch to <strong>Edit mode</strong> (the default). The agent has full read and write access to
    the project. It can create files, modify code, run shell commands, and make changes
    autonomously within the constraints of the active
    <a href="/docs/permissions">permission mode</a>.
</p>

<h4 id="cmd-plan"><code>/plan</code></h4>
<p>
    Switch to <strong>Plan mode</strong>. The agent operates in read-only mode: it can read files,
    search the codebase, and run non-destructive commands, but cannot write files or execute
    modifying shell commands. Use this mode when you want the agent to analyze and propose
    changes without actually making them.
</p>

<h4 id="cmd-ask"><code>/ask</code></h4>
<p>
    Switch to <strong>Ask mode</strong>. A read-only Q&amp;A mode where the agent can read files for
    context but focuses on answering questions and explaining code rather than proposing changes.
    Ideal for learning about an unfamiliar codebase or getting explanations of complex logic.
</p>

<div class="tip">
    You can also set the default mode in your <a href="/docs/configuration">configuration file</a>.
    The slash command overrides the configured default for the current session only.
</div>


<!-- ------------------------------------------------------------------ -->
<h3 id="model-switching">Model Switching</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-models"><code>/models</code> <small class="text-muted">(alias: <code>/model</code>)</small></h4>
<p>
    Open the curated model switcher for day-to-day use. Instead of dumping the
    full provider catalog, it shows the most likely choices in this order:
    recent models you used, likely models from the current provider, and likely
    models from your most recently used non-current provider.
</p>
<p>
    You can also jump directly with arguments:
</p>
<pre><code>/models
/models openai
/models gpt-5.4
/models anthropic:claude-sonnet-4-20250514</code></pre>
<p>
    Switching updates the live runtime immediately when the active LLM backend
    supports it, and also updates the default provider/model used for future
    sessions. Full provider and model inventory remains in <code>/settings</code>.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="permission-control">Permission Control</h3>
<!-- ------------------------------------------------------------------ -->

<p>
    Permission modes control how the agent handles tool approval. Switching permission modes takes
    effect immediately. See the <a href="/docs/permissions">Permissions</a> page for full details
    on what each mode allows.
</p>

<h4 id="cmd-guardian"><code>/guardian</code></h4>
<p>
    Switch to <strong>Guardian</strong> permission mode. The agent uses smart auto-approve logic:
    read-only operations are approved automatically, while writes and shell commands require
    explicit user approval unless they match safe patterns. This is the default mode.
</p>

<h4 id="cmd-argus"><code>/argus</code></h4>
<p>
    Switch to <strong>Argus</strong> permission mode. Every tool call requires explicit approval.
    Nothing runs without your confirmation. Use this when working on sensitive code or when you
    want to review every single action the agent takes.
</p>

<h4 id="cmd-prometheus"><code>/prometheus</code></h4>
<p>
    Switch to <strong>Prometheus</strong> permission mode. Full autonomy &mdash; all tool calls
    are approved automatically without prompts. The agent can read, write, and execute freely.
    Use this when you trust the agent to work independently on well-understood tasks.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="context-and-memory">Context &amp; Memory</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-compact"><code>/compact</code></h4>
<p>
    Manually trigger context compaction. The agent summarizes the conversation so far into a
    condensed form, freeing up context window space. Useful when the context feels bloated, when
    you are about to start a large task and want maximum room, or when the agent starts forgetting
    earlier parts of the conversation.
</p>

<h4 id="cmd-memories"><code>/memories</code></h4>
<p>
    List all stored persistent memories. Displays each memory's ID, type (project, user, or
    decision), title, and creation date. Memories persist across sessions and are automatically
    included in the agent's system prompt.
</p>

<h4 id="cmd-forget"><code>/forget &lt;id&gt;</code></h4>
<p>
    Delete a specific memory by its numeric ID. Use <code>/memories</code> first to find the ID of the
    memory you want to remove. This is permanent &mdash; the memory will no longer be included
    in future sessions.
</p>
<pre><code>/forget 42</code></pre>


<!-- ------------------------------------------------------------------ -->
<h3 id="monitoring">Monitoring</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-agents"><code>/agents</code> <small class="text-muted">(alias: <code>/swarm</code>)</small></h4>
<p>
    Open the live swarm dashboard showing all active and completed subagents. Displays each
    agent's status, progress, resource usage, and task description. The dashboard updates in
    real time in TUI mode. Also accessible via the <code>Ctrl+A</code> keyboard shortcut during
    agent activity.
</p>

<h4 id="cmd-settings"><code>/settings</code></h4>
<p>
    Open the interactive settings workspace. Navigate through categories (LLM, permissions, UI,
    tools, gateway, integrations, etc.) and change configuration values in real time. Changes are
    persisted to your user-level configuration and take effect immediately when supported.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="utilities">Utilities</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-clear"><code>/clear</code></h4>
<p>
    Clear the terminal display. This only clears the visual output &mdash; it does not affect the
    conversation history or context window. The agent retains full memory of the session.
</p>

<h4 id="cmd-update"><code>kosmokrator update</code></h4>
<p>
    Check for new KosmoKrator versions from the shell. Static binary and PHAR
    installs update in place. Source installs are detected and you get the
    exact manual update commands to run.
</p>

<!-- ================================================================== -->
<h2 id="shell-commands">Shell Commands</h2>
<!-- ================================================================== -->

<p>
    These commands are run directly from your terminal rather than inside the
    interactive REPL.
</p>

<h4 id="cmd-shell-update"><code>kosmokrator update</code></h4>
<p>
    Check GitHub Releases for a newer version. Static binary and PHAR installs
    can replace themselves in place. Source installs print the exact
    <code>git pull</code> and <code>composer install</code> commands to run.
</p>

<h4 id="cmd-shell-setup"><code>kosmokrator setup</code></h4>
<p>
    Run the interactive first-time configuration flow. It selects a provider,
    stores credentials, and writes the default provider/model settings used by
    future sessions.
</p>

<h4 id="cmd-shell-config"><code>kosmokrator config</code></h4>
<p>
    Inspect and edit resolved settings from the shell. Supports
    <code>show</code>, <code>get</code>, <code>set</code>, <code>unset</code>,
    and <code>edit</code>, with <code>--global</code> and <code>--project</code>
    to choose where overrides are written.
</p>
<pre><code>kosmokrator config show
kosmokrator config get llm.default_model
kosmokrator config set llm.default_model GLM-5.1 --global
kosmokrator config edit --project</code></pre>

<h4 id="cmd-shell-auth"><code>kosmokrator auth</code></h4>
<p>
    Manage provider authentication. Use <code>status</code> to inspect providers,
    <code>login</code> to store an API key or run OAuth, and <code>logout</code>
    to remove stored credentials.
</p>
<pre><code>kosmokrator auth status
kosmokrator auth login openai --api-key="$OPENAI_API_KEY"
kosmokrator auth login codex --device
kosmokrator auth logout openai</code></pre>

<h4 id="cmd-shell-codex"><code>kosmokrator codex:*</code></h4>
<p>
    Convenience commands for the Codex provider's ChatGPT OAuth flow. These map
    to the same credential store used by <code>auth login codex</code>.
</p>
<pre><code>kosmokrator codex:login
kosmokrator codex:login --device
kosmokrator codex:status
kosmokrator codex:logout</code></pre>

<h4 id="cmd-shell-integrations"><code>kosmokrator integrations:*</code></h4>
<p>
    Run OpenCompany integrations directly from the shell without starting an
    agent session. This surface is intended for scripts, CI jobs, and other
    coding CLIs that need a unified local integration layer.
</p>
<pre><code>kosmokrator integrations:list --json
kosmokrator integrations:status --json
kosmokrator integrations:doctor plane --json
kosmokrator integrations:fields plane --json
kosmokrator integrations:configure plane --set api_key="$PLANE_API_KEY" --enable --json
kosmokrator integrations:search "plane issue" --json
kosmokrator integrations:docs plane.create_issue
kosmokrator integrations:schema plane.create_issue
kosmokrator integrations:call plane.create_issue --project-id=PROJECT_UUID --name="Check" --dry-run --json
kosmokrator integrations:call plane.list_issues --project-id=PROJECT_UUID --json
kosmokrator integrations:plane list_issues --project-id=PROJECT_UUID --json
kosmokrator integrations:lua workflow.lua --force --json</code></pre>
<p>
    See <a href="/docs/integrations">Integrations CLI</a> for the complete
    command reference, argument passing rules, Lua endpoint, JSON result
    envelopes, headless configuration, permissions, account aliases, and
    external coding CLI patterns.
</p>

<h4 id="cmd-shell-gateway"><code>kosmokrator gateway:telegram</code></h4>
<p>
    Start the Telegram gateway worker. This turns KosmoKrator into a Telegram
    bot surface backed by the normal agent/session runtime. The gateway keeps a
    linked session per configured chat route, supports typing indicators,
    streamed replies, queued follow-up messages, and inline approval buttons.
</p>
<p>
    Typical flow:
</p>
<pre><code>/settings → Gateway
enable Telegram, store bot token, set allowlist

kosmokrator gateway:telegram</code></pre>
<p>
    The internal worker command <code>kosmokrator gateway:telegram:worker</code>
    is process-managed by the main gateway and is not intended for normal manual
    use.
</p>

<!-- ================================================================== -->
<h2 id="telegram-gateway-commands">Telegram Gateway Commands</h2>
<!-- ================================================================== -->

<p>
    When you talk to KosmoKrator through Telegram, the bot exposes a transport
    layer of Telegram-native commands in addition to the normal Kosmo slash
    commands.
</p>

<h4><code>/help</code></h4>
<p>Show Telegram gateway help, available controls, and the supported slash-command bridge.</p>

<h4><code>/status</code></h4>
<p>Show the linked session, active run state, queued input count, and current mode details for that chat route.</p>

<h4><code>/new</code></h4>
<p>Start a fresh linked session for the current Telegram route.</p>

<h4><code>/resume</code></h4>
<p>Reuse the existing linked session for the current Telegram route if one exists.</p>

<h4><code>/cancel</code></h4>
<p>Cancel the active run for the current Telegram route.</p>

<h4><code>/approve</code> / <code>/deny</code></h4>
<p>
    Resolve the latest pending approval request. Approval also supports scoped
    variants:
</p>
<pre><code>/approve
/approve always
/approve guardian
/approve prometheus
/deny</code></pre>
<p>
    The Telegram gateway also provides inline approval buttons, so typing these
    commands is optional in the normal case.
</p>

<h4 id="cmd-feedback"><code>/feedback &lt;text&gt;</code> <small class="text-muted">(aliases: <code>/bug</code>, <code>/issue</code>)</small></h4>
<p>
    Submit feedback or a bug report. Injects a prompt into the LLM conversation instructing the
    agent to create a GitHub issue on the KosmoKrator repository via <code>gh issue create</code>.
    System information (version, OS, PHP version, provider/model) is automatically appended to the
    issue body. Requires the <code>gh</code> CLI to be installed and authenticated.
</p>
<pre><code>/feedback The glob tool should support brace expansion for multi-pattern matching</code></pre>

<h4 id="cmd-seed"><code>/seed</code></h4>
<p>
    Development tool that plays a scripted mock session. Used for testing and debugging the
    UI and agent interaction flow. Not intended for normal use.
</p>

<h4 id="cmd-tasks-clear"><code>/tasks-clear</code></h4>
<p>
    Clear all tracked tasks from the current session. Removes every task regardless of status
    (pending, in progress, or completed). The task list in the context bar is emptied.
</p>

<h4 id="cmd-theogony"><code>/theogony</code> <small class="text-muted">(alias: <code>/cosmogony</code>)</small></h4>
<p>
    Replay the animated intro sequence. The theogony is the mythological creation narrative that
    plays when KosmoKrator first launches (unless started with <code>--no-animation</code>).
</p>


<!-- ================================================================== -->
<h2 id="slash-command-reference">Slash Command Reference</h2>
<!-- ================================================================== -->

<p><strong>Need a quick reminder?</strong> Use <code>/help</code> <small class="text-muted">(aliases: <code>/?</code>, <code>/commands</code>)</small> to list all available commands directly in the terminal.</p>

<p>
    Complete reference table of all slash commands, their arguments, and descriptions.
</p>

<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Arguments</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>/help</code></td>
            <td>None</td>
            <td>List available commands. <small class="text-muted">(aliases: <code>/?</code>, <code>/commands</code>)</small></td>
        </tr>
        <tr>
            <td><code>/new</code></td>
            <td>None</td>
            <td>Clear conversation and start a fresh session.</td>
        </tr>
        <tr>
            <td><code>/resume</code></td>
            <td>None</td>
            <td>Open session picker to resume a previous conversation.</td>
        </tr>
        <tr>
            <td><code>/sessions</code></td>
            <td>None</td>
            <td>List all recent sessions.</td>
        </tr>
        <tr>
            <td><code>/rename</code></td>
            <td><code>[name]</code></td>
            <td>Rename the current session.</td>
        </tr>
        <tr>
            <td><code>/quit</code></td>
            <td>None</td>
            <td>Save and exit KosmoKrator. <small class="text-muted">(aliases: <code>/exit</code>, <code>/q</code>)</small></td>
        </tr>
        <tr>
            <td><code>/edit</code></td>
            <td>None</td>
            <td>Switch to Edit mode (full read/write access).</td>
        </tr>
        <tr>
            <td><code>/plan</code></td>
            <td>None</td>
            <td>Switch to Plan mode (read-only analysis).</td>
        </tr>
        <tr>
            <td><code>/models</code></td>
            <td><code>[provider|model|provider:model]</code></td>
            <td>Open the curated model switcher or jump directly to a likely provider/model.</td>
        </tr>
        <tr>
            <td><code>/ask</code></td>
            <td>None</td>
            <td>Switch to Ask mode (read-only Q&amp;A).</td>
        </tr>
        <tr>
            <td><code>/guardian</code></td>
            <td>None</td>
            <td>Switch to Guardian permission mode (smart auto-approve).</td>
        </tr>
        <tr>
            <td><code>/argus</code></td>
            <td>None</td>
            <td>Switch to Argus permission mode (ask for every governed tool call).</td>
        </tr>
        <tr>
            <td><code>/prometheus</code></td>
            <td>None</td>
            <td>Switch to Prometheus permission mode (full autonomy).</td>
        </tr>
        <tr>
            <td><code>/compact</code></td>
            <td>None</td>
            <td>Manually trigger context compaction.</td>
        </tr>
        <tr>
            <td><code>/memories</code></td>
            <td>None</td>
            <td>List all stored persistent memories.</td>
        </tr>
        <tr>
            <td><code>/forget</code></td>
            <td><code>&lt;id&gt;</code></td>
            <td>Delete a specific memory by numeric ID.</td>
        </tr>
        <tr>
            <td><code>/agents</code></td>
            <td>None</td>
            <td>Open the live swarm dashboard. <small class="text-muted">(alias: <code>/swarm</code>)</small></td>
        </tr>
        <tr>
            <td><code>/settings</code></td>
            <td>None</td>
            <td>Open the interactive settings workspace.</td>
        </tr>
        <tr>
            <td><code>/clear</code></td>
            <td>None</td>
            <td>Clear the terminal display.</td>
        </tr>
        <tr>
            <td><code>/feedback</code></td>
            <td><code>&lt;text&gt;</code></td>
            <td>Submit feedback or a bug report as a GitHub issue. <small class="text-muted">(aliases: <code>/bug</code>, <code>/issue</code>)</small></td>
        </tr>
        <tr>
            <td><code>/seed</code></td>
            <td>None</td>
            <td>Play a scripted mock session (dev tool).</td>
        </tr>
        <tr>
            <td><code>/tasks-clear</code></td>
            <td>None</td>
            <td>Clear all tracked tasks.</td>
        </tr>
        <tr>
            <td><code>/theogony</code></td>
            <td>None</td>
            <td>Replay the animated intro sequence. <small class="text-muted">(alias: <code>/cosmogony</code>)</small></td>
        </tr>
    </tbody>
</table>

<p>
    Shell-level commands are documented separately above. The main operational
    ones are <code>kosmokrator update</code> and
    <code>kosmokrator gateway:telegram</code>.
</p>


<!-- ================================================================== -->
<h2 id="power-commands">Power Commands</h2>
<!-- ================================================================== -->

<p>
    Power commands are prefixed with <code>:</code> and activate specialized agent behaviors.
    Each power command comes with unique animations and a tailored system prompt injection that
    shapes how the agent approaches your task. Type the power command followed by your
    instructions.
</p>

<pre><code>:unleash Refactor the entire authentication module to use JWT tokens</code></pre>

<div class="tip">
    Power commands can be combined. When you use multiple power commands together, the agent
    receives behavioral instructions from all of them. See
    <a href="#command-combinability">Command Combinability</a> for details.
</div>


<!-- ------------------------------------------------------------------ -->
<h3 id="coding-power-commands">Coding</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-unleash"><code>:unleash</code> <small class="text-muted">(aliases: <code>:swarm</code>, <code>:nuke</code>)</small></h4>
<p>
    Aggressive autonomous coding mode. The agent works with maximum speed and output, making
    decisions independently without hand-holding. Best for well-defined tasks where you want
    the agent to just get it done. Minimizes clarification questions and maximizes throughput.
</p>

<h4 id="cmd-autopilot"><code>:autopilot</code> <small class="text-muted">(aliases: <code>:pilot</code>, <code>:auto</code>)</small></h4>
<p>
    Sustained autonomous work with periodic check-ins. The agent works independently for extended
    stretches but pauses at natural breakpoints to report progress and confirm direction. A good
    middle ground between <code>:unleash</code> and <code>:babysit</code>.
</p>

<h4 id="cmd-babysit"><code>:babysit</code> <small class="text-muted">(aliases: <code>:watch</code>, <code>:shepherd</code>)</small></h4>
<p>
    Monitor a pull request until it is merged. The agent watches CI status, handles failing checks,
    addresses review comments, and applies fixes as needed. Checks every few minutes for up to
    60 minutes. Pass a PR number or URL as the argument.
</p>

<h4 id="cmd-review"><code>:review</code> <small class="text-muted">(alias: <code>:cr</code>)</small></h4>
<p>
    Deep code review mode. The agent reads through the specified code (or recent changes) and
    provides actionable feedback covering correctness, performance, security, readability, and
    best practices. Does not make changes unless explicitly asked.
</p>

<h4 id="cmd-deepdive"><code>:deepdive</code> <small class="text-muted">(alias: <code>:dive</code>)</small></h4>
<p>
    Thorough codebase investigation. The agent reads files extensively, traces code paths across
    modules, follows call chains, and builds a comprehensive understanding of how a feature or
    subsystem works. Results in a detailed written analysis.
</p>

<h4 id="cmd-deepinit"><code>:deepinit</code> <small class="text-muted">(aliases: <code>:init</code>, <code>:map</code>)</small></h4>
<p>
    Comprehensive project initialization and onboarding. The agent explores the entire project
    structure, reads configuration files, examines dependencies, understands the architecture,
    and produces a thorough onboarding summary. Use this when starting work on an unfamiliar
    codebase.
</p>

<h4 id="cmd-research"><code>:research</code> <small class="text-muted">(alias: <code>:sci</code>)</small></h4>
<p>
    In-depth research mode. The agent gathers information from the codebase, documentation,
    and any available sources before acting. It reads broadly, cross-references findings, and
    builds a knowledge base before proposing solutions. Use this for tasks that require
    understanding complex existing systems.
</p>

<h4 id="cmd-deslop"><code>:deslop</code> <small class="text-muted">(alias: <code>:clean</code>)</small></h4>
<p>
    Clean up sloppy code. The agent systematically identifies and fixes dead code, poor naming,
    unnecessary duplication, inconsistent formatting, overly complex logic, and other code
    quality issues. Focuses on making the code cleaner without changing behavior.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="quality-testing">Quality &amp; Testing</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-ultraqa"><code>:ultraqa</code> <small class="text-muted">(alias: <code>:qa</code>)</small></h4>
<p>
    Exhaustive quality assurance pass. The agent acts as a thorough QA engineer: it looks for bugs,
    edge cases, race conditions, missing error handling, inconsistencies between related components,
    and potential regressions. Produces a prioritized list of findings with suggested fixes.
</p>

<h4 id="cmd-doctor"><code>:doctor</code> <small class="text-muted">(alias: <code>:diag</code>)</small></h4>
<p>
    Diagnose and fix project issues. The agent checks configuration files, dependency versions,
    environment setup, common misconfigurations, and known problem patterns. Acts like a
    troubleshooting wizard that methodically works through potential causes of whatever issue
    you describe.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="documentation">Documentation</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-docs"><code>:docs</code> <small class="text-muted">(alias: <code>:doc</code>)</small></h4>
<p>
    Generate or improve documentation. The agent reads the code and produces clear, accurate
    documentation &mdash; whether that is inline PHPDoc, README sections, API documentation,
    architecture guides, or usage examples. Follows the project's existing documentation style.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="collaboration">Collaboration</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-interview"><code>:interview</code> <small class="text-muted">(alias: <code>:socrates</code>)</small></h4>
<p>
    Interactive Q&amp;A mode to understand requirements. The agent asks targeted questions to
    clarify what you need before writing any code. Useful for complex features where the
    requirements are not fully defined yet. The agent gathers enough information to produce
    a solid implementation plan.
</p>

<h4 id="cmd-learner"><code>:learner</code> <small class="text-muted">(alias: <code>:learn</code>)</small></h4>
<p>
    Extract a reusable pattern from the current conversation. The agent analyzes the conversation
    for generalizable, non-obvious, actionable patterns with clear triggers, applies a quality
    gate, and saves the result to persistent memory. If no pattern passes the quality gate, the
    agent says so honestly.
</p>

<h4 id="cmd-trace"><code>:trace</code> <small class="text-muted">(alias: <code>:debug</code>)</small></h4>
<p>
    Trace execution paths and data flow through the code. The agent follows a request, event, or
    data structure from entry point to final output, documenting every transformation, method
    call, and branching decision along the way. Produces a step-by-step execution narrative.
</p>

<h4 id="cmd-ralph"><code>:ralph</code> <small class="text-muted">(aliases: <code>:sisyphus</code>, <code>:persist</code>)</small></h4>
<p>
    Persistent retry loop &mdash; the boulder never stops. The agent attempts the task repeatedly
    (up to 5 attempts), trying a different approach each time. Includes a mandatory self-review
    after all checks pass. After 3 failures, it reconsiders the entire approach; after 5, it asks
    for guidance.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="release-ci">Release &amp; CI</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-release"><code>:release</code> <small class="text-muted">(alias: <code>:ship</code>)</small></h4>
<p>
    Prepare a release. The agent handles the full release workflow: version bump, changelog
    generation from recent commits, running the test suite, verifying CI status, and creating
    a git tag. Walks you through each step and asks for confirmation before finalizing.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="multi-agent">Multi-Agent</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-legion"><code>:legion</code> <small class="text-muted">(alias: <code>:perspectives</code>)</small></h4>
<p>
    Five perspective agents deliberate, Moirai synthesizes the decree. Spawns five specialized
    perspective agents &mdash; each evaluating from a different angle (correctness, simplicity,
    performance, security, integration) &mdash; then a synthesis agent merges their findings into
    a single coherent recommendation.
</p>

<h4 id="cmd-replay"><code>:replay</code> <small class="text-muted">(alias: <code>:redo</code>)</small></h4>
<p>
    Re-run a failed or incomplete multi-agent task. The agent reviews what the previous subagent
    run accomplished, identifies where it went wrong, and re-attempts the failed portions. Useful
    for recovering from transient errors or picking up where a cancelled operation left off.
</p>

<h4 id="cmd-team"><code>:team</code> <small class="text-muted">(alias: <code>:squad</code>)</small></h4>
<p>
    Spawn a team of specialized subagents for a complex task. The agent breaks the task into
    subtasks, assigns each to an appropriate subagent type (general, explore, or plan), manages
    dependencies between them, and synthesizes their results. Best for large tasks that benefit
    from parallel execution.
</p>

<h4 id="cmd-consensus"><code>:consensus</code> <small class="text-muted">(alias: <code>:council</code>)</small></h4>
<p>
    Run multiple agents on the same task and compare results. The agent spawns several independent
    subagents that each tackle the task separately, then evaluates and merges their outputs. Useful
    for critical decisions where you want multiple perspectives or for validating that a solution
    is robust.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="control">Control</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-cancel"><code>:cancel</code> <small class="text-muted">(alias: <code>:stop</code>)</small></h4>
<p>
    Cancel all running subagents immediately. Any background subagents are terminated and their
    partial results are discarded. The main agent remains active and ready for new instructions.
</p>


<!-- ------------------------------------------------------------------ -->
<h3 id="knowledge">Knowledge</h3>
<!-- ------------------------------------------------------------------ -->

<h4 id="cmd-wiki"><code>:wiki</code> <small class="text-muted">(alias: <code>:w</code>)</small></h4>
<p>
    Build and maintain a persistent, interlinked markdown knowledge base. Supports four subcommands:
    <code>init</code> to create a new wiki with schema and structure, <code>ingest</code> to capture
    a source and write wiki pages, <code>lint</code> to health-check for contradictions and orphans,
    and <code>query</code> (default) to search the wiki and synthesize answers with citations.
</p>


<!-- ================================================================== -->
<h2 id="power-command-reference">Power Command Reference</h2>
<!-- ================================================================== -->

<p>
    Complete reference table of all power commands.
</p>

<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Category</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>:unleash</code></td>
            <td>Coding</td>
            <td>Aggressive autonomous coding with maximum output. <small class="text-muted">(aliases: <code>:swarm</code>, <code>:nuke</code>)</small></td>
        </tr>
        <tr>
            <td><code>:autopilot</code></td>
            <td>Coding</td>
            <td>Sustained autonomous work with periodic check-ins. <small class="text-muted">(aliases: <code>:pilot</code>, <code>:auto</code>)</small></td>
        </tr>
        <tr>
            <td><code>:babysit</code></td>
            <td>Coding</td>
            <td>Monitor a PR until merged — handle CI, reviews, and fixes. <small class="text-muted">(aliases: <code>:watch</code>, <code>:shepherd</code>)</small></td>
        </tr>
        <tr>
            <td><code>:review</code></td>
            <td>Coding</td>
            <td>Deep code review with actionable feedback. <small class="text-muted">(alias: <code>:cr</code>)</small></td>
        </tr>
        <tr>
            <td><code>:deepdive</code></td>
            <td>Coding</td>
            <td>Thorough codebase investigation and analysis. <small class="text-muted">(alias: <code>:dive</code>)</small></td>
        </tr>
        <tr>
            <td><code>:deepinit</code></td>
            <td>Coding</td>
            <td>Comprehensive project onboarding and exploration. <small class="text-muted">(aliases: <code>:init</code>, <code>:map</code>)</small></td>
        </tr>
        <tr>
            <td><code>:research</code></td>
            <td>Coding</td>
            <td>In-depth research before taking action. <small class="text-muted">(alias: <code>:sci</code>)</small></td>
        </tr>
        <tr>
            <td><code>:deslop</code></td>
            <td>Coding</td>
            <td>Clean up dead code, naming, and duplication. <small class="text-muted">(alias: <code>:clean</code>)</small></td>
        </tr>
        <tr>
            <td><code>:ultraqa</code></td>
            <td>Quality</td>
            <td>Exhaustive QA: bugs, edge cases, inconsistencies. <small class="text-muted">(alias: <code>:qa</code>)</small></td>
        </tr>
        <tr>
            <td><code>:doctor</code></td>
            <td>Quality</td>
            <td>Diagnose and fix project configuration issues. <small class="text-muted">(alias: <code>:diag</code>)</small></td>
        </tr>
        <tr>
            <td><code>:docs</code></td>
            <td>Documentation</td>
            <td>Generate or improve documentation. <small class="text-muted">(alias: <code>:doc</code>)</small></td>
        </tr>
        <tr>
            <td><code>:interview</code></td>
            <td>Collaboration</td>
            <td>Interactive Q&amp;A to clarify requirements. <small class="text-muted">(alias: <code>:socrates</code>)</small></td>
        </tr>
        <tr>
            <td><code>:learner</code></td>
            <td>Collaboration</td>
            <td>Extract reusable patterns from the conversation. <small class="text-muted">(alias: <code>:learn</code>)</small></td>
        </tr>
        <tr>
            <td><code>:trace</code></td>
            <td>Collaboration</td>
            <td>Trace execution paths and data flow. <small class="text-muted">(alias: <code>:debug</code>)</small></td>
        </tr>
        <tr>
            <td><code>:ralph</code></td>
            <td>Collaboration</td>
            <td>Persistent retry loop — the boulder never stops. <small class="text-muted">(aliases: <code>:sisyphus</code>, <code>:persist</code>)</small></td>
        </tr>
        <tr>
            <td><code>:release</code></td>
            <td>Release</td>
            <td>Version bump, changelog, tests, and tagging. <small class="text-muted">(alias: <code>:ship</code>)</small></td>
        </tr>
        <tr>
            <td><code>:legion</code></td>
            <td>Multi-Agent</td>
            <td>Five perspective agents deliberate, Moirai synthesizes. <small class="text-muted">(alias: <code>:perspectives</code>)</small></td>
        </tr>
        <tr>
            <td><code>:replay</code></td>
            <td>Multi-Agent</td>
            <td>Re-run a failed multi-agent task. <small class="text-muted">(alias: <code>:redo</code>)</small></td>
        </tr>
        <tr>
            <td><code>:team</code></td>
            <td>Multi-Agent</td>
            <td>Spawn a specialized subagent team. <small class="text-muted">(alias: <code>:squad</code>)</small></td>
        </tr>
        <tr>
            <td><code>:consensus</code></td>
            <td>Multi-Agent</td>
            <td>Run multiple agents, compare results. <small class="text-muted">(alias: <code>:council</code>)</small></td>
        </tr>
        <tr>
            <td><code>:cancel</code></td>
            <td>Control</td>
            <td>Cancel all running subagents. <small class="text-muted">(alias: <code>:stop</code>)</small></td>
        </tr>
        <tr>
            <td><code>:wiki</code></td>
            <td>Knowledge</td>
            <td>Build and maintain a persistent markdown knowledge base. <small class="text-muted">(alias: <code>:w</code>)</small></td>
        </tr>
    </tbody>
</table>


<!-- ================================================================== -->
<h2 id="skill-commands">Skill Commands</h2>
<!-- ================================================================== -->

<p>
    Skill commands are prefixed with <code>$</code> and invoke reusable, customizable skill
    templates. Skills are auto-discovered from several directories at runtime:
</p>

<ul>
    <li><code>.kosmokrator/skills/</code> &mdash; project-level skills</li>
    <li><code>.agents/skills/</code> &mdash; alternative project-level location</li>
    <li><code>~/.kosmokrator/skills/</code> &mdash; user-level skills (available in all projects)</li>
</ul>

<p>
    Each skill is a directory containing a <code>SKILL.md</code> file that defines the prompt
    template and metadata. Invoking a skill injects its prompt into the LLM conversation with
    your arguments filled in.
</p>

<h3 id="built-in-skills">Built-in Skill Management</h3>

<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>$list</code> or <code>$skills</code></td>
            <td>List all discovered skills with name, scope (project/user), and description.</td>
        </tr>
        <tr>
            <td><code>$create &lt;name&gt;</code></td>
            <td>Create a new skill template (SKILL.md) in the project skills directory. The agent helps you define the skill content.</td>
        </tr>
        <tr>
            <td><code>$show &lt;name&gt;</code></td>
            <td>Display a skill's content, scope, and file path.</td>
        </tr>
        <tr>
            <td><code>$edit &lt;name&gt;</code></td>
            <td>Inject a prompt for the agent to read and help modify the skill file.</td>
        </tr>
        <tr>
            <td><code>$delete &lt;name&gt;</code></td>
            <td>Remove a skill directory recursively.</td>
        </tr>
    </tbody>
</table>

<h3 id="invoking-skills">Invoking Skills</h3>

<p>
    To invoke a skill, type <code>$</code> followed by the skill name and any arguments:
</p>

<pre><code>$my-custom-skill Refactor the authentication module</code></pre>

<div class="tip">
    Skills are reloaded from disk on every invocation, so you can edit a skill's <code>SKILL.md</code>
    file and immediately use the updated version without restarting.
</div>


<!-- ================================================================== -->
<h2 id="keyboard-shortcuts">Keyboard Shortcuts</h2>
<!-- ================================================================== -->

<p>
    Keyboard shortcuts provide quick access to common actions without typing commands. Some
    shortcuts are context-dependent and only available in specific situations.
</p>

<table>
    <thead>
        <tr>
            <th>Shortcut</th>
            <th>Action</th>
            <th>Context</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>Ctrl+A</code></td>
            <td>Open swarm dashboard</td>
            <td>During agent activity</td>
        </tr>
        <tr>
            <td><code>Ctrl+O</code></td>
            <td>Toggle collapsed output</td>
            <td>When viewing tool results</td>
        </tr>
        <tr>
            <td><code>Shift+Enter</code> or <code>Alt+Enter</code></td>
            <td>New line in input</td>
            <td>TUI mode</td>
        </tr>
        <tr>
            <td><code>Tab</code></td>
            <td>Autocomplete slash/power/skill commands</td>
            <td>Input prompt</td>
        </tr>
        <tr>
            <td><code>Shift+Tab</code></td>
            <td>Cycle through modes (Edit → Plan → Ask)</td>
            <td>Input prompt</td>
        </tr>
        <tr>
            <td><code>Ctrl+L</code></td>
            <td>Force refresh the TUI display</td>
            <td>TUI mode</td>
        </tr>
        <tr>
            <td><code>Up</code> / <code>Down</code></td>
            <td>Navigate command history</td>
            <td>Input prompt</td>
        </tr>
        <tr>
            <td><code>Page Up</code> / <code>Page Down</code></td>
            <td>Scroll conversation history</td>
            <td>When viewing conversation</td>
        </tr>
        <tr>
            <td><code>End</code></td>
            <td>Jump to live output (bottom of conversation)</td>
            <td>When scrolled up in conversation</td>
        </tr>
        <tr>
            <td><code>Esc</code></td>
            <td>Close overlay or dialog</td>
            <td>TUI mode</td>
        </tr>
        <tr>
            <td><code>Ctrl+C</code></td>
            <td>Cancel current operation</td>
            <td>Any time</td>
        </tr>
    </tbody>
</table>

<div class="tip">
    In ANSI mode, multi-line input is not available via keyboard shortcuts. Switch to TUI mode for
    full multi-line editing support via the EditorWidget.
</div>


<!-- ================================================================== -->
<h2 id="command-combinability">Command Combinability</h2>
<!-- ================================================================== -->

<p>
    Power commands can be combined in a single input line. When multiple power commands are used
    together, the agent receives the behavioral instructions from all of them and applies the
    combined approach to your task.
</p>

<pre><code>:unleash :review Fix all lint warnings and review the changes</code></pre>

<p>
    In this example, the agent applies both the aggressive autonomous approach from
    <code>:unleash</code> and the thorough review methodology from <code>:review</code>. It will
    fix the lint warnings autonomously and then perform a deep review of its own changes.
</p>

<p>Some effective combinations:</p>

<ul>
    <li>
        <code>:unleash :deslop</code> &mdash; Aggressively clean up code quality issues across
        the entire codebase without stopping for confirmations.
    </li>
    <li>
        <code>:babysit :review</code> &mdash; Perform a code review while monitoring a PR
        with step-by-step explanations.
    </li>
    <li>
        <code>:research :deepdive</code> &mdash; Combine broad research with thorough code tracing
        for maximum understanding before acting.
    </li>
    <li>
        <code>:team :ultraqa</code> &mdash; Spawn a multi-agent QA team that covers different
        aspects of quality in parallel.
    </li>
    <li>
        <code>:autopilot :docs</code> &mdash; Autonomously generate documentation across the
        project with periodic progress check-ins.
    </li>
</ul>

<div class="tip">
    Slash commands cannot be combined with each other or with power commands. Each slash command
    must be entered on its own line and takes effect immediately.
</div>

<?php
$docContent = ob_get_clean();
include __DIR__.'/../_docs-layout.php';

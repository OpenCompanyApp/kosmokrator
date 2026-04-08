<?php
$docTitle = 'Permissions';
$docSlug = 'permissions';
ob_start();
?>
<p class="lead">
    KosmoKrator's permission system controls what the agent can do on your machine.
    Three permission modes balance safety and autonomy, while a configurable
    evaluation chain ensures every tool call is checked against blocked paths,
    deny patterns, session grants, project boundaries, custom rules, and
    mode-specific heuristics before it executes.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="permission-modes">Permission Modes</h2>

<p>
    Every KosmoKrator session operates in one of three permission modes. The mode
    determines how the agent handles tool calls that require approval &mdash;
    whether it asks the user, auto-approves via heuristics, or runs unrestricted.
</p>

<h3 id="guardian">Guardian (Default)</h3>

<p>
    Guardian is the default mode and the recommended choice for everyday
    development work. It uses heuristic evaluation to classify tool calls as
    safe or risky. Safe operations &mdash; reads, searches, and known-safe
    shell commands &mdash; are auto-approved silently. Risky operations such
    as file writes, edits, and unrecognized bash commands prompt the user for
    approval before executing.
</p>

<ul>
    <li><strong>Symbol:</strong> <code>&#x25C8;</code> (diamond)</li>
    <li>Smart auto-approve for known-safe operations</li>
    <li>Asks for writes, edits, and unknown or risky commands</li>
    <li>Uses static heuristic analysis &mdash; no LLM calls for permission decisions</li>
    <li>File writes and edits inside the project directory are auto-approved</li>
    <li>Best for daily development use</li>
</ul>

<h3 id="argus">Argus</h3>

<p>
    Argus mode requires explicit user approval for every tool call that has an
    Ask rule (tools in the <code>approval_required</code> list). Tools in the
    <code>safe_tools</code> list (such as <code>file_read</code>,
    <code>glob</code>, and <code>grep</code>) are still auto-approved via their
    Allow rules. This provides a detailed audit trail of every action the agent
    takes, making it ideal for security-sensitive work, exploring unfamiliar
    codebases, or learning how the agent operates.
</p>

<ul>
    <li><strong>Symbol:</strong> <code>&#x25C9;</code> (target)</li>
    <li>Every Ask-ruled tool call requires explicit approval</li>
    <li>Full visibility and audit trail for governed tools</li>
    <li>Safe tools still bypass the chain via Allow rules</li>
    <li>Best for learning, exploring new codebases, or security-sensitive work</li>
</ul>

<h3 id="prometheus">Prometheus</h3>

<p>
    Prometheus mode auto-approves everything. The agent executes all tool calls
    without pausing for user confirmation, providing maximum speed and autonomy.
    Explicit deny rules and blocked paths are still enforced &mdash; Prometheus
    removes the "ask" step, not the safety rails.
</p>

<ul>
    <li><strong>Symbol:</strong> <code>&#x26A1;</code> (lightning)</li>
    <li>Unrestricted execution, no approval prompts</li>
    <li>Maximum speed and autonomy</li>
    <li>Blocked paths and explicit deny rules still enforced</li>
    <li>Project boundary check is bypassed</li>
    <li>Best for trusted CI/CD pipelines, <a href="/docs/headless">headless mode</a>, or known-safe tasks</li>
</ul>

<h3 id="mode-comparison">Mode Comparison</h3>

<table>
    <thead>
        <tr>
            <th>Mode</th>
            <th>Auto-Approve Reads</th>
            <th>Auto-Approve Writes</th>
            <th>Auto-Approve Bash</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Guardian</td>
            <td>Yes</td>
            <td>In-project only</td>
            <td>Heuristic</td>
            <td>Default mode</td>
        </tr>
        <tr>
            <td>Argus</td>
            <td>Yes (safe_tools)</td>
            <td>No (asks)</td>
            <td>No (asks)</td>
            <td>Full audit for Ask-ruled tools</td>
        </tr>
        <tr>
            <td>Prometheus</td>
            <td>Yes</td>
            <td>Yes</td>
            <td>Yes</td>
            <td>No prompts, boundary bypassed</td>
        </tr>
    </tbody>
</table>

<h3 id="switching-modes">Switching Modes</h3>

<p>
    Switch between permission modes at any time during a session using
    slash commands. The change takes effect immediately for all subsequent
    tool calls.
</p>

<pre><code>/guardian      # Switch to Guardian mode (default)
/argus         # Switch to Argus mode
/prometheus    # Switch to Prometheus mode</code></pre>

<p>
    You can also set the default mode in your configuration file so every new
    session starts in your preferred mode:
</p>

<pre><code>tools:
  default_permission_mode: guardian   # guardian | argus | prometheus</code></pre>

<!-- ------------------------------------------------------------------ -->
<h2 id="evaluation-chain">Evaluation Chain</h2>

<p>
    Every tool call passes through a chain of six permission checks before
    execution. The checks run in a fixed order, and the first check that
    returns a definitive result halts the chain. If no check halts the chain,
    the call is <strong>denied by default</strong> (fail-closed).
</p>

<h3 id="chain-overview">Chain Order</h3>

<ol>
    <li>
        <strong>Blocked Path Check</strong> &mdash; Matches the tool call's
        <code>path</code> argument against a list of glob patterns for
        unconditionally denied paths (e.g., <code>*.env</code>,
        <code>.git/*</code>, <code>*.pem</code>). Both the raw path and
        the resolved (symlink-followed) path are checked. If either matches,
        the call is denied immediately. This check overrides everything else
        in the chain.
    </li>
    <li>
        <strong>Deny Pattern Check</strong> &mdash; Evaluates the tool call
        against explicit deny rules from the configuration. For bash and
        shell tools, this checks command arguments against blocked command
        patterns (e.g., <code>rm -rf /</code>). Also checks tools listed in
        <code>denied_tools</code> (see below). A match produces an
        unconditional deny that overrides even Prometheus mode.
    </li>
    <li>
        <strong>Session Grant Check</strong> &mdash; Checks whether the user
        has previously approved this tool for the current session. If so,
        the call is allowed without prompting. Session grants are per-tool
        (not per-path or per-command).
    </li>
    <li>
        <strong>Project Boundary Check</strong> &mdash; For file tools
        (<code>file_write</code>, <code>file_edit</code>, <code>file_read</code>,
        <code>glob</code>, <code>grep</code>), checks whether the target path
        is outside the project root. If the path is outside the project and
        not in <code>allowed_paths</code>, the call triggers an Ask prompt.
        Prometheus mode is exempt from boundary enforcement. This check applies
        to both read and write tools &mdash; even <code>file_read</code>,
        <code>glob</code>, and <code>grep</code> trigger a prompt when
        accessing files outside the project root.
    </li>
    <li>
        <strong>Rule Check</strong> &mdash; Matches against the permission
        rules defined in the configuration (<code>safe_tools</code>,
        <code>approval_required</code>, and <code>denied_tools</code>).
        If a rule matches with an Allow action, the call is allowed.
        If a rule matches with a Deny action, the call is denied.
        If a rule matches with an Ask action, the check returns
        <code>null</code> and passes through to the remaining checks
        in the chain.
    </li>
    <li>
        <strong>Mode Override Check</strong> &mdash; Applies the active
        permission mode's logic to any tool that has an Ask rule.
        Only tools with Ask rules reach this stage. In Prometheus mode,
        the Ask is upgraded to Allow. In Guardian mode, the Guardian
        heuristic evaluator decides whether to auto-approve or ask the
        user. In Argus mode, the Ask stands and the user is prompted.
    </li>
</ol>

<h3 id="chain-result">Result</h3>

<p>
    Every evaluation produces one of three outcomes:
</p>

<ul>
    <li><strong>Allow</strong> &mdash; The tool call executes immediately, with no user interaction.</li>
    <li><strong>Ask</strong> &mdash; The user is prompted to approve, grant for session, or deny the call.</li>
    <li><strong>Deny</strong> &mdash; The tool call is blocked. The agent receives an error message explaining why.</li>
</ul>

<div class="tip">
    <p>
        <strong>Tip:</strong> The evaluation chain runs synchronously in the
        tool-call hot path. It is pure static analysis with no LLM calls,
        so permission decisions add negligible latency.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="guardian-heuristics">Guardian Heuristics</h2>

<p>
    When Guardian mode encounters a tool call that has an Ask rule and reaches
    the Mode Override Check, it delegates to the
    <code>GuardianEvaluator</code> for static heuristic analysis. The
    evaluator classifies the call as safe or risky without making any LLM
    calls. Safe calls are auto-approved silently; risky calls prompt the
    user.
</p>

<h3 id="always-safe-tools">Always-Safe Tools</h3>

<p>
    The following tools are always auto-approved in Guardian mode, regardless
    of their arguments:
</p>

<ul>
    <li><code>file_read</code> &mdash; Reading files</li>
    <li><code>glob</code> &mdash; File pattern matching</li>
    <li><code>grep</code> &mdash; Content search</li>
    <li><code>task_create</code>, <code>task_update</code>, <code>task_list</code>, <code>task_get</code> &mdash; Task management</li>
    <li><code>shell_read</code>, <code>shell_kill</code> &mdash; Reading shell output and killing sessions</li>
    <li><code>memory_save</code>, <code>memory_search</code> &mdash; Persistent memory operations</li>
    <li><code>lua_list_docs</code>, <code>lua_search_docs</code>, <code>lua_read_doc</code> &mdash; Lua API documentation</li>
    <li><code>execute_lua</code> &mdash; Lua script execution (inner integration permissions enforce per-tool granularity)</li>
</ul>

<h3 id="file-operation-heuristics">File Operation Heuristics</h3>

<p>
    For <code>file_write</code> and <code>file_edit</code>, Guardian mode
    checks whether the target path resolves inside the current project
    directory. Writes within the project are auto-approved; writes outside
    the project require user confirmation.
</p>

<ul>
    <li><code>file_write</code> &mdash; Auto-approved if the path is inside the project root</li>
    <li><code>file_edit</code> &mdash; Auto-approved if the path is inside the project root</li>
    <li>Paths outside the project &mdash; Always ask, regardless of mode</li>
</ul>

<h3 id="safe-bash-commands">Safe Bash Commands</h3>

<p>
    Guardian mode evaluates bash commands in two stages: first it checks for
    shell metacharacters, then it matches against a configurable list of
    safe command patterns. This same logic applies to <code>shell_start</code>
    (the startup command) and <code>shell_write</code> (the input text sent
    to an existing session).
</p>

<p>
    <strong>Shell metacharacter check:</strong> If a command contains any of
    the following characters, it is immediately classified as risky,
    regardless of the command itself: <code>;</code> <code>&amp;</code>
    <code>|</code> <code>`</code> <code>$</code> <code>&gt;</code>
    <code>&lt;</code> and newlines. This prevents bypassing the safe
    list via command chaining, piping, or redirection.
</p>

<p>
    <strong>Safe command patterns:</strong> If the command passes the
    metacharacter check, it is matched against glob patterns defined in
    the <code>guardian_safe_commands</code> config. The default safe
    commands include:
</p>

<table>
    <thead>
        <tr>
            <th>Category</th>
            <th>Commands</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Version control</td>
            <td><code>git *</code> (status, log, diff, branch, etc.)</td>
        </tr>
        <tr>
            <td>File inspection</td>
            <td><code>ls *</code>, <code>cat *</code>, <code>head *</code>, <code>tail *</code>, <code>wc *</code></td>
        </tr>
        <tr>
            <td>Navigation</td>
            <td><code>pwd</code>, <code>which *</code>, <code>find *</code></td>
        </tr>
        <tr>
            <td>Output</td>
            <td><code>echo *</code>, <code>diff *</code></td>
        </tr>
        <tr>
            <td>PHP tooling</td>
            <td><code>php vendor/bin/phpunit*</code>, <code>php vendor/bin/pint*</code>, <code>composer *</code></td>
        </tr>
        <tr>
            <td>JavaScript</td>
            <td><code>npm *</code>, <code>npx *</code>, <code>node *</code></td>
        </tr>
        <tr>
            <td>Other languages</td>
            <td><code>python *</code>, <code>cargo *</code>, <code>go *</code>, <code>make *</code></td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p>
        <strong>Tip:</strong> The safe command list uses glob-style matching.
        <code>git *</code> matches <code>git status</code>,
        <code>git log --oneline</code>, and any other git command &mdash; but
        only when the command contains no shell operators. A command like
        <code>git log | head</code> would still require approval because of
        the pipe character.
    </p>
</div>

<h3 id="risky-commands">Risky Commands (Require Approval)</h3>

<p>
    Any bash command that does not match a safe pattern, or that contains
    shell metacharacters, requires user approval in Guardian mode. Common
    examples include:
</p>

<ul>
    <li><code>rm</code>, <code>mv</code>, <code>cp</code> &mdash; Destructive or mutative file operations</li>
    <li><code>git push</code>, <code>git reset --hard</code> &mdash; Destructive git operations</li>
    <li><code>curl</code>, <code>wget</code> &mdash; Network access</li>
    <li>Commands with <code>|</code>, <code>&gt;</code>, <code>&gt;&gt;</code> &mdash; Pipe and redirect operators</li>
    <li><code>sudo</code>, <code>chmod</code>, <code>chown</code> &mdash; Privilege escalation and permission changes</li>
    <li><code>docker</code>, <code>kubectl</code> &mdash; Container orchestration</li>
    <li>Any command not in the safe list</li>
</ul>

<h3 id="mutative-detection">Mutative Command Detection</h3>

<p>
    Separately from the safe command analysis, Guardian mode maintains a list
    of mutative command patterns used to enforce read-only bash in Ask mode.
    Mutative commands include file deletion, package installation, git
    operations that modify history, container commands, and process
    management. Commands containing shell operators are also treated as
    mutative.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="session-grants">Session Grants</h2>

<p>
    When the user approves a tool call, they can choose the scope of the
    approval:
</p>

<ul>
    <li>
        <strong>Allow once</strong> &mdash; Approves this specific call only.
        The next call to the same tool will prompt again.
    </li>
    <li>
        <strong>Allow for session</strong> &mdash; Grants blanket approval
        for the tool for the remainder of the session. All subsequent calls
        to that tool are auto-approved without prompting.
    </li>
    <li>
        <strong>Deny</strong> &mdash; Blocks this specific call. The agent
        receives an error and must find an alternative approach.
    </li>
</ul>

<p>
    Session grants are tracked per tool name &mdash; not per path, command,
    or argument combination. Granting session approval for <code>bash</code>
    means all bash commands are approved for the rest of the session.
</p>

<p>
    Grants are cleared automatically when:
</p>

<ul>
    <li>The session ends</li>
    <li>The conversation is reset</li>
    <li>The user explicitly resets grants</li>
</ul>

<div class="tip">
    <p>
        <strong>Tip:</strong> Session grants sit at position 3 in the
        evaluation chain, after blocked paths and deny patterns but before
        project boundary checks, rule checks, and mode overrides. This means
        blocked paths and explicit denies always take precedence, even if
        you granted session approval.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="blocked-paths">Blocked Paths</h2>

<p>
    Blocked paths define files and directories that are unconditionally denied
    access, regardless of the active permission mode. Even Prometheus mode
    cannot override a blocked path. This is the strongest safety mechanism
    in the permission system.
</p>

<p>
    Blocked paths use glob-style patterns and are checked against both the
    raw path and the resolved (symlink-followed) path, so symlink tricks
    cannot bypass the block. Both the full path and the basename are tested
    against each pattern.
</p>

<h3 id="default-blocked-paths">Default Blocked Paths</h3>

<p>The default configuration blocks these patterns:</p>

<pre><code>tools:
  blocked_paths:
    - "*.env"           # Environment files with secrets
    - ".git/*"          # Git internal directory
    - "*.pem"           # SSL/TLS certificates
    - "*id_rsa*"        # RSA private keys
    - "*id_ed25519*"    # Ed25519 private keys
    - "*.key"           # Generic key files</code></pre>

<h3 id="custom-blocked-paths">Adding Custom Blocked Paths</h3>

<p>
    Add your own blocked path patterns in the configuration file. Patterns
    use glob syntax where <code>*</code> matches any sequence of characters
    and <code>?</code> matches any single character.
</p>

<pre><code>tools:
  blocked_paths:
    - "*.env"
    - ".git/*"
    - "*.pem"
    - "*id_rsa*"
    - "*id_ed25519*"
    - "*.key"
    - "/etc/shadow"           # System password file
    - "/etc/passwd"           # User database
    - "*.credentials"         # Credential files
    - "~/.aws/*"              # AWS configuration</code></pre>

<p>
    Blocked paths apply to all tools that accept a <code>path</code>
    argument: <code>file_read</code>, <code>file_write</code>,
    <code>file_edit</code>, and <code>apply_patch</code>.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="approval-flow">Approval Flow UI</h2>

<p>
    When a tool call requires approval (the evaluation chain returned Ask),
    KosmoKrator presents the user with the details and waits for a decision.
    The presentation varies by renderer, but the information and options are
    the same.
</p>

<h3 id="approval-tui">TUI Mode</h3>

<p>
    In TUI mode, an overlay dialog appears showing the tool name, a summary
    of the arguments (file path, command text, etc.), and three action
    buttons. The dialog is rendered by the <code>PermissionPromptWidget</code>
    and supports keyboard navigation.
</p>

<h3 id="approval-ansi">ANSI Mode</h3>

<p>
    In ANSI mode, the tool call details are printed inline, followed by a
    prompt line showing the available choices. Input is read from the
    terminal via readline.
</p>

<h3 id="approval-options">Options</h3>

<table>
    <thead>
        <tr>
            <th>Key</th>
            <th>Action</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>y</code></td>
            <td>Allow once</td>
            <td>Execute this call, prompt again for the next one</td>
        </tr>
        <tr>
            <td><code>s</code></td>
            <td>Allow for session</td>
            <td>Execute this call and auto-approve all future calls to this tool</td>
        </tr>
        <tr>
            <td><code>n</code></td>
            <td>Deny</td>
            <td>Block this call, return an error to the agent</td>
        </tr>
    </tbody>
</table>

<!-- ------------------------------------------------------------------ -->
<h2 id="permission-rules">Permission Rules</h2>

<p>
    Permission rules are defined in the configuration and control which tools
    are allowed, denied, or require approval. Three configuration options
    define these rules:
</p>

<h3 id="safe-tools">Safe Tools (<code>safe_tools</code>)</h3>

<p>
    The <code>safe_tools</code> list creates unconditional Allow rules for the
    specified tools. These tools are auto-approved without any permission check
    or user prompt, regardless of the active mode (including Argus). Tools in
    this list skip the Mode Override Check entirely.
</p>

<pre><code>tools:
  safe_tools:
    - file_read
    - glob
    - grep
    - task_create
    - task_update
    - task_list
    - task_get
    - shell_read
    - shell_kill
    - memory_save
    - memory_search
    - ask_user
    - ask_choice
    - subagent
    - lua_list_docs
    - lua_search_docs
    - lua_read_doc</code></pre>

<h3 id="denied-tools">Denied Tools (<code>denied_tools</code>)</h3>

<p>
    The <code>denied_tools</code> list creates unconditional Deny rules for the
    specified tools. This is the strongest form of denial &mdash; it overrides
    everything, including Prometheus mode and session grants. Use it to
    hard-disable specific tools in a project or CI environment.
</p>

<pre><code>tools:
  # Completely disable specific tools, overriding all modes
  denied_tools:
    - bash           # No shell access
    - file_write     # Read-only project</code></pre>

<h3 id="default-rules">Approval Required (<code>approval_required</code>)</h3>

<p>
    The <code>approval_required</code> list creates Ask rules for the specified
    tools. These tools pass through the full evaluation chain, where the
    outcome depends on the active permission mode, session grants, project
    boundaries, and heuristics.
</p>

<pre><code>tools:
  approval_required:
    - file_write
    - file_edit
    - apply_patch
    - bash
    - shell_start
    - shell_write
    - execute_lua</code></pre>

<p>
    Note: <code>execute_lua</code> is in the approval list, but Guardian mode
    auto-approves it because the inner integration permissions enforce per-tool
    granularity within Lua scripts.
</p>

<h3 id="blocked-commands">Blocked Commands</h3>

<p>
    For bash and shell tools, you can define command patterns that are always
    denied, regardless of mode. These are unconditional &mdash; even
    Prometheus mode cannot override them.
</p>

<pre><code>tools:
  bash:
    blocked_commands:
      - "rm -rf /"               # Catastrophic deletion
      - "dd if=/dev/zero*"       # Disk overwrite
      - "mkfs*"                  # Filesystem formatting</code></pre>

<h3 id="safe-commands-config">Safe Command Configuration</h3>

<p>
    The Guardian safe command list is fully configurable. Add or remove
    patterns to match your workflow:
</p>

<pre><code>tools:
  guardian_safe_commands:
    - "git *"
    - "ls *"
    - "pwd"
    - "cat *"
    - "head *"
    - "tail *"
    - "wc *"
    - "find *"
    - "which *"
    - "echo *"
    - "diff *"
    - "php vendor/bin/phpunit*"
    - "php vendor/bin/pint*"
    - "composer *"
    - "npm *"
    - "npx *"
    - "node *"
    - "python *"
    - "cargo *"
    - "go *"
    - "make *"
    - "terraform plan*"          # Custom: allow terraform plan
    - "kubectl get *"            # Custom: allow read-only kubectl</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> Safe command patterns only apply in Guardian
        mode. In Argus mode, every Ask-ruled tool call asks regardless of
        the safe list. In Prometheus mode, everything is auto-approved
        regardless of the safe list.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="project-boundary">Project Boundary</h2>

<p>
    The Project Boundary Check (stage 4 in the evaluation chain) enforces
    that file tools operate within the project root. When a file tool targets
    a path outside the project directory, an Ask prompt is triggered so the
    user can approve or deny the access. This applies to both read and write
    tools &mdash; even <code>file_read</code>, <code>glob</code>, and
    <code>grep</code> trigger a prompt when accessing files outside the
    project root.
</p>

<p>
    Prometheus mode is exempt from boundary enforcement. Session grants (if
    previously approved) also bypass this check since they sit earlier in
    the chain.
</p>

<h3 id="allowed-paths">Allowed Paths</h3>

<p>
    The <code>allowed_paths</code> configuration defines additional path
    prefixes that are treated as if they were inside the project root. Paths
    matching these prefixes bypass the project boundary check entirely.
</p>

<pre><code>tools:
  allowed_paths:
    - "~/.kosmokrator"    # KosmoKrator config directory
    - "/tmp"              # Temporary files</code></pre>

<p>
    Paths in <code>allowed_paths</code> are resolved at startup (including
    <code>~</code> expansion and symlink resolution), so they work correctly
    across different environments.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="agent-mode-interaction">Interaction with Agent Modes</h2>

<p>
    Permission modes and agent modes are orthogonal systems. Permission
    modes control <em>whether</em> the agent can execute tool calls; agent
    modes control <em>what</em> the agent is allowed to do at a higher
    level. Their effects combine:
</p>

<table>
    <thead>
        <tr>
            <th>Agent Mode</th>
            <th>Permission Mode</th>
            <th>Behavior</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Edit</td>
            <td>Guardian</td>
            <td>Default safe development &mdash; reads auto-approved, writes prompt, safe bash auto-approved</td>
        </tr>
        <tr>
            <td>Edit</td>
            <td>Argus</td>
            <td>Full audit &mdash; every Ask-ruled tool call prompts; safe tools still auto-approved</td>
        </tr>
        <tr>
            <td>Edit</td>
            <td>Prometheus</td>
            <td>Fully autonomous &mdash; agent reads, writes, and executes without prompting</td>
        </tr>
        <tr>
            <td>Plan</td>
            <td>Any</td>
            <td>Read-only &mdash; the agent can read and search but cannot write or execute. Permission mode is irrelevant for blocked operations.</td>
        </tr>
        <tr>
            <td>Ask</td>
            <td>Any</td>
            <td>Read-only &mdash; same as Plan mode. Mutative bash commands are blocked regardless of permission mode.</td>
        </tr>
    </tbody>
</table>

<p>
    In Ask and Plan modes, the agent mode restriction takes precedence.
    Even Prometheus mode cannot write files or execute destructive commands
    when the agent mode forbids it. The permission system only governs
    tool calls that the agent mode has already allowed.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="subagent-permissions">Subagent Permissions</h2>

<p>
    Subagents inherit the parent agent's permission mode and evaluation
    chain. The same blocked paths, deny patterns, rules, and mode settings
    apply to all subagents in the hierarchy.
</p>

<ul>
    <li>
        <strong>Explore subagents</strong> &mdash; Limited to read-only
        tools. Permission prompts are rare since reads are auto-approved
        in Guardian and Prometheus modes.
    </li>
    <li>
        <strong>Plan subagents</strong> &mdash; Read-only, same as Explore.
    </li>
    <li>
        <strong>General subagents</strong> &mdash; Full tool access, subject
        to the same permission evaluation as the parent agent. In
        Prometheus or <a href="/docs/headless">headless mode</a>, these run without prompts.
    </li>
</ul>

<div class="tip">
    <p>
        <strong>Tip:</strong> In <a href="/docs/headless">headless mode</a> (CI/CD pipelines), the
        permission mode is typically set to Prometheus so subagents can
        execute without blocking on approval prompts. Blocked paths and
        explicit denies still apply as a safety net.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="configuration-reference">Configuration Reference</h2>

<p>
    All permission-related settings live under the <code>tools</code> key
    in the configuration file. Settings can be defined in the global config
    (<code>config/kosmokrator.yaml</code>), user config
    (<code>~/.kosmokrator/config.yaml</code>), or project-local config
    (<code>.kosmokrator.yaml</code> in the working directory).
    Project-local settings override user settings, which override the
    global defaults.
</p>

<pre><code>tools:
  # Tools that are always denied, overriding all modes including Prometheus
  denied_tools: []
  # Example: denied_tools: [file_write, bash]

  # Tools that are always allowed without prompting (Allow rules)
  safe_tools:
    - file_read
    - glob
    - grep
    - task_create
    - task_update
    - task_list
    - task_get
    - shell_read
    - shell_kill
    - memory_save
    - memory_search
    - ask_user
    - ask_choice
    - subagent
    - lua_list_docs
    - lua_search_docs
    - lua_read_doc

  # Tools that require approval (Ask rules)
  approval_required:
    - file_write
    - file_edit
    - apply_patch
    - bash
    - shell_start
    - shell_write
    - execute_lua

  # Default permission mode for new sessions
  default_permission_mode: guardian   # guardian | argus | prometheus

  # Paths that are always denied access (glob patterns)
  blocked_paths:
    - "*.env"
    - ".git/*"
    - "*.pem"
    - "*id_rsa*"
    - "*id_ed25519*"
    - "*.key"

  # Paths that bypass the project boundary check
  allowed_paths:
    - "~/.kosmokrator"
    - "/tmp"

  # Bash commands blocked unconditionally (glob patterns)
  bash:
    blocked_commands:
      - "rm -rf /"

  # Commands auto-approved in Guardian mode (glob patterns)
  guardian_safe_commands:
    - "git *"
    - "ls *"
    - "pwd"
    - "cat *"
    - "composer *"
    # ... add your own patterns</code></pre>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';

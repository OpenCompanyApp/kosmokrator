<?php
$docTitle = 'Tools';
$docSlug = 'tools';
ob_start();
?>

<p class="lead">
    KosmoKrator ships with a full suite of built-in tools that the agent uses to read, write, search,
    and execute code. This page is the complete reference for every tool, its parameters, and typical
    usage patterns.
</p>

<div class="tip">
    Tools requiring approval (file_write, file_edit, bash) depend on your
    <a href="/docs/permissions">permission mode</a>. In <strong>Prometheus</strong> mode all tools
    run without prompts; in <strong>Guardian</strong> mode most writes require explicit approval.
</div>

<!-- ================================================================== -->
<h2 id="file-operations">File Operations</h2>
<!-- ================================================================== -->

<h3 id="file_read">file_read</h3>

<p>
    Read a file from the project with line numbers. Supports partial reads via offset/limit and
    includes an automatic caching layer: if a file has not changed since the last read, the tool
    returns <code>"unchanged since last read"</code> instead of re-sending the full contents. Files
    larger than 10 MB are streamed line-by-line to avoid memory pressure.
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
            <td><code>path</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Absolute or project-relative path to the file.</td>
        </tr>
        <tr>
            <td><code>offset</code></td>
            <td>int</td>
            <td>No</td>
            <td>Line number to start reading from (1-based). Omit to start from the beginning.</td>
        </tr>
        <tr>
            <td><code>limit</code></td>
            <td>int</td>
            <td>No</td>
            <td>Maximum number of lines to return. Omit to read the entire file.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Read lines 50&ndash;80 of a controller:</p>
<pre><code>file_read path="src/Controller/UserController.php" offset=50 limit=30</code></pre>

<div class="tip">
    When the agent is exploring a large file it will often use <code>offset</code> and
    <code>limit</code> to read only the relevant section, keeping the context window lean.
</div>

<!-- ------------------------------------------------------------------ -->

<h3 id="file_write">file_write</h3>

<p>
    Create a new file or completely overwrite an existing one. Any missing parent directories are
    created automatically. Returns the total line count of the written file.
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
            <td><code>path</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Absolute or project-relative path for the file.</td>
        </tr>
        <tr>
            <td><code>content</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The full content to write.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Create a new configuration file:</p>
<pre><code>file_write path="config/cache.yaml" content="cache:\n  driver: redis\n  ttl: 3600\n"</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="file_edit">file_edit</h3>

<p>
    Perform a targeted find-and-replace within a file. The <code>old_string</code> must appear
    exactly once in the file; if it matches zero or more than one location the edit is rejected.
    Returns the line delta (positive if lines were added, negative if removed, zero if the
    replacement has the same line count).
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
            <td><code>path</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Path to the file to edit.</td>
        </tr>
        <tr>
            <td><code>old_string</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The exact text to find. Must match exactly once.</td>
        </tr>
        <tr>
            <td><code>new_string</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The replacement text.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Rename a method call:</p>
<pre><code>file_edit path="src/Service/Mailer.php" \
  old_string="$this->sendLegacy($message)" \
  new_string="$this->send($message)"</code></pre>

<div class="tip">
    The agent prefers <code>file_edit</code> over <code>file_write</code> for surgical changes
    because it only transmits the diff, keeping token usage low and making approval review easier.
</div>

<!-- ------------------------------------------------------------------ -->

<h3 id="apply_patch">apply_patch</h3>

<p>
    Apply a unified diff patch to one or more files. Supports multi-file, multi-hunk patches in
    standard unified diff format. The parser streams the patch content for memory efficiency, making
    it suitable for large changesets.
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
            <td><code>patch</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The unified diff content to apply.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Apply a multi-file patch:</p>
<pre><code>apply_patch patch="--- a/src/Model/User.php
+++ b/src/Model/User.php
@@ -12,6 +12,7 @@ class User
     public string $name;
     public string $email;
+    public ?string $avatar = null;

     public function __construct(string $name)
--- a/tests/UserTest.php
+++ b/tests/UserTest.php
@@ -8,4 +8,10 @@ class UserTest extends TestCase
     {
         $this->assertInstanceOf(User::class, new User('Ada'));
     }
+
+    public function test_avatar_defaults_to_null(): void
+    {
+        $user = new User('Ada');
+        $this->assertNull($user->avatar);
+    }
 }"</code></pre>


<!-- ================================================================== -->
<h2 id="search">Search</h2>
<!-- ================================================================== -->

<h3 id="glob">glob</h3>

<p>
    Fast file-name pattern matching across the project tree. Automatically skips common
    non-project directories (<code>.git</code>, <code>vendor</code>, <code>node_modules</code>).
    Returns up to 200 matching file paths, sorted by name.
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
            <td><code>pattern</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Glob pattern to match (e.g. <code>**/*.php</code>, <code>src/Model/*.php</code>).</td>
        </tr>
        <tr>
            <td><code>path</code></td>
            <td>string</td>
            <td>No</td>
            <td>Base directory to search from. Defaults to the project root.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Find all migration files:</p>
<pre><code>glob pattern="database/migrations/*.php"</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="grep">grep</h3>

<p>
    Regex-powered content search. Uses <strong>ripgrep</strong> when available on the system for
    maximum speed; falls back to GNU grep otherwise. Returns up to 100 matches, each with file
    path and line number.
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
            <td><code>pattern</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Regular expression pattern to search for.</td>
        </tr>
        <tr>
            <td><code>path</code></td>
            <td>string</td>
            <td>No</td>
            <td>File or directory to search in. Defaults to the project root.</td>
        </tr>
        <tr>
            <td><code>glob</code></td>
            <td>string</td>
            <td>No</td>
            <td>File name filter (e.g. <code>*.php</code>, <code>*.ts</code>).</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Find all usages of a deprecated method in PHP files:</p>
<pre><code>grep pattern="->sendLegacy\(" glob="*.php"</code></pre>

<div class="tip">
    The agent typically combines <code>glob</code> and <code>grep</code> in sequence: first
    <code>glob</code> to discover which files exist, then <code>grep</code> to find specific
    patterns inside them.
</div>


<!-- ================================================================== -->
<h2 id="shell">Shell</h2>
<!-- ================================================================== -->

<h3 id="bash">bash</h3>

<p>
    Execute a shell command in the project directory. Output (combined stdout and stderr) is
    streamed via a progress callback so the UI can display it in real time. The result includes the
    full output text and the process exit code.
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
            <td><code>command</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The shell command to execute.</td>
        </tr>
        <tr>
            <td><code>timeout</code></td>
            <td>int</td>
            <td>No</td>
            <td>Maximum execution time in seconds. Default: 120. Maximum: 7200 (2 hours).</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Run the test suite with a generous timeout:</p>
<pre><code>bash command="php vendor/bin/phpunit --testdox" timeout=300</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="shell-sessions">Shell Sessions</h3>

<p>
    Interactive persistent shell sessions allow the agent to start long-running processes (dev
    servers, watchers, REPLs) and interact with them over time. Each session gets a unique ID and
    persists until explicitly killed or the agent session ends.
</p>

<h4 id="shell_start">shell_start</h4>

<p>Start a new interactive shell session.</p>

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
            <td><code>command</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The command to start (e.g. <code>php artisan tinker</code>, <code>npm run dev</code>).</td>
        </tr>
        <tr>
            <td><code>cwd</code></td>
            <td>string</td>
            <td>No</td>
            <td>Working directory for the session. Defaults to the project root.</td>
        </tr>
        <tr>
            <td><code>timeout</code></td>
            <td>int</td>
            <td>No</td>
            <td>Inactivity timeout in seconds before the session is auto-terminated.</td>
        </tr>
        <tr>
            <td><code>wait_ms</code></td>
            <td>int</td>
            <td>No</td>
            <td>Milliseconds to wait for initial output after starting.</td>
        </tr>
    </tbody>
</table>

<h4 id="shell_write">shell_write</h4>

<p>Send input to a running shell session.</p>

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
            <td><code>id</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The session ID returned by <code>shell_start</code>.</td>
        </tr>
        <tr>
            <td><code>input</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The text to send to the session's stdin.</td>
        </tr>
    </tbody>
</table>

<h4 id="shell_read">shell_read</h4>

<p>Read buffered output from a running shell session.</p>

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
            <td><code>id</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The session ID.</td>
        </tr>
        <tr>
            <td><code>wait_ms</code></td>
            <td>int</td>
            <td>No</td>
            <td>Milliseconds to wait for new output before returning.</td>
        </tr>
    </tbody>
</table>

<h4 id="shell_kill">shell_kill</h4>

<p>Terminate a running shell session.</p>

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
            <td><code>id</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The session ID to terminate.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Start a dev server, verify it boots, then tear it down:</p>
<pre><code># Start the server
shell_start command="php artisan serve --port=8080" wait_ms=2000
# Returns: { id: "sess_abc123", output: "Starting development server..." }

# Check that it is responding
shell_read id="sess_abc123" wait_ms=1000

# Shut it down when done
shell_kill id="sess_abc123"</code></pre>


<!-- ================================================================== -->
<h2 id="agent-coordination">Agent Coordination</h2>
<!-- ================================================================== -->

<h3 id="subagent">subagent</h3>

<p>
    Spawn a child agent that runs in its own context window. Subagents inherit the project's tool
    set and permission mode but operate independently. They can run in the foreground (await) or
    background, form dependency chains, and be grouped for batch execution.
    See <a href="/docs/agents">Agents</a> for the full subagent architecture guide.
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
            <td>Natural-language description of what the subagent should accomplish.</td>
        </tr>
        <tr>
            <td><code>type</code></td>
            <td>string</td>
            <td>No</td>
            <td>
                Agent type: <code>general</code> (full tool access, default),
                <code>explore</code> (read-only tools), or
                <code>plan</code> (no tools, planning only).
            </td>
        </tr>
        <tr>
            <td><code>mode</code></td>
            <td>string</td>
            <td>No</td>
            <td>
                <code>await</code> (block until complete, default) or
                <code>background</code> (run concurrently).
            </td>
        </tr>
        <tr>
            <td><code>id</code></td>
            <td>string</td>
            <td>No</td>
            <td>Custom identifier for referencing in dependency chains.</td>
        </tr>
        <tr>
            <td><code>depends_on</code></td>
            <td>array</td>
            <td>No</td>
            <td>List of subagent IDs that must complete before this one starts.</td>
        </tr>
        <tr>
            <td><code>group</code></td>
            <td>string</td>
            <td>No</td>
            <td>Group name for batch spawning multiple agents together.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Fan out three explore agents, then merge their findings:</p>
<pre><code># Phase 1: parallel exploration
subagent task="Map the authentication flow" type="explore" mode="background" id="auth"
subagent task="Map the payment flow" type="explore" mode="background" id="pay"
subagent task="Map the notification flow" type="explore" mode="background" id="notify"

# Phase 2: plan based on all three
subagent task="Design a unified event system based on the auth, payment, and notification flows" \
  type="plan" mode="await" depends_on=["auth","pay","notify"]</code></pre>


<!-- ================================================================== -->
<h2 id="memory">Memory</h2>
<!-- ================================================================== -->

<h3 id="memory_save">memory_save</h3>

<p>
    Persist a piece of knowledge to the memory store so it survives across sessions. Memories are
    stored as Markdown files and can be scoped to the project, the user, or tagged as architectural
    decisions.
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
            <td><code>type</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>
                Memory scope: <code>project</code> (shared across all users),
                <code>user</code> (personal preferences), or
                <code>decision</code> (architectural decision record).
            </td>
        </tr>
        <tr>
            <td><code>title</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Short title used as the memory's identifier and filename.</td>
        </tr>
        <tr>
            <td><code>content</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Markdown content of the memory.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Record an architectural decision:</p>
<pre><code>memory_save type="decision" \
  title="Use event sourcing for orders" \
  content="## Context\nOrder history is critical for auditing.\n\n## Decision\nAll order mutations go through an event store."</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="memory_search">memory_search</h3>

<p>
    Search previously saved memories by keyword relevance. Returns ranked results with title,
    type, and content preview.
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
            <td><code>query</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Free-text search query.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Recall a previous decision about caching:</p>
<pre><code>memory_search query="caching strategy"</code></pre>


<!-- ================================================================== -->
<h2 id="tasks">Tasks</h2>
<!-- ================================================================== -->

<p>
    The task system lets the agent track multi-step work items. Tasks persist in the session and
    appear in the UI's context bar so both the agent and the user can see progress at a glance.
</p>

<h3 id="task_create">task_create</h3>

<p>Create a new task.</p>

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
            <td><code>title</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Short title describing the task.</td>
        </tr>
        <tr>
            <td><code>description</code></td>
            <td>string</td>
            <td>No</td>
            <td>Longer description with details or acceptance criteria.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong></p>
<pre><code>task_create title="Migrate user table to UUIDs" description="Replace auto-increment IDs with UUIDv7 primary keys."</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="task_update">task_update</h3>

<p>Update the status of an existing task.</p>

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
            <td><code>id</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The task ID.</td>
        </tr>
        <tr>
            <td><code>status</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>
                New status: <code>in_progress</code>, <code>completed</code>, or
                <code>cancelled</code>.
            </td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong></p>
<pre><code>task_update id="task_001" status="completed"</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="task_get">task_get</h3>

<p>Retrieve full details of a single task.</p>

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
            <td><code>id</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The task ID to retrieve.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong></p>
<pre><code>task_get id="task_001"</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="task_list">task_list</h3>

<p>
    List all tasks in the current session. Returns every task with its ID, title, status, and
    creation timestamp. Takes no parameters.
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
            <td colspan="4"><em>No parameters.</em></td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong></p>
<pre><code>task_list</code></pre>


<!-- ================================================================== -->
<h2 id="interactive">Interactive</h2>
<!-- ================================================================== -->

<p>
    Interactive tools let the agent ask the user for input mid-task. This is useful when the agent
    encounters an ambiguous situation and needs clarification rather than guessing.
</p>

<h3 id="ask_user">ask_user</h3>

<p>
    Pose a free-form question to the user. The agent pauses and waits for the user's typed
    response before continuing.
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
            <td><code>question</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The question to display to the user.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong></p>
<pre><code>ask_user question="The tests reference a 'staging' database. Should I use the local SQLite DB or set up a MySQL container?"</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="ask_choice">ask_choice</h3>

<p>
    Present a set of discrete choices to the user. The UI renders them as a numbered list (ANSI
    mode) or selectable options (TUI mode). An optional <code>mockup</code> parameter lets the
    agent include an ASCII-art preview of a proposed change.
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
            <td><code>question</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The question or prompt text.</td>
        </tr>
        <tr>
            <td><code>choices</code></td>
            <td>array</td>
            <td>Yes</td>
            <td>List of choice strings to present.</td>
        </tr>
        <tr>
            <td><code>mockup</code></td>
            <td>string</td>
            <td>No</td>
            <td>ASCII art or text mockup to display alongside the choices for visual context.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Let the user pick a database driver:</p>
<pre><code>ask_choice \
  question="Which database driver should this project use?" \
  choices=["SQLite (lightweight, zero config)", "MySQL (production-grade)", "PostgreSQL (advanced features)"]</code></pre>

<div class="tip">
    The <code>mockup</code> parameter is particularly useful when the agent wants to show a
    proposed UI layout, directory structure, or architecture diagram before the user commits to a
    choice.
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="tool-internals">Tool Internals</h2>

<p>
    This section describes how KosmoKrator executes tool calls under the hood.
    Understanding the pipeline is helpful when debugging unexpected behaviour,
    tuning performance, or writing custom tool integrations.
</p>

<h3 id="tool-execution-pipeline">Tool Execution Pipeline</h3>

<p>
    When the LLM response contains one or more tool calls, KosmoKrator processes
    them through a multi-stage pipeline:
</p>

<ol>
    <li>
        <strong>ToolExecutor receives tool calls</strong> &mdash; the executor
        collects all tool calls from the parsed LLM response. Each call includes
        the tool name and a map of parameters.
    </li>
    <li>
        <strong>Permission check</strong> &mdash; before any tool runs, the
        <a href="/docs/permissions">Permissions</a> system validates the call
        against the active policy. Calls that require approval are queued for
        user confirmation (or rejected outright in strict mode).
    </li>
    <li>
        <strong>Concurrent partitioning</strong> &mdash; independent tool calls
        are grouped and executed in parallel (see
        <a href="#concurrent-execution">Concurrent Execution</a> below).
    </li>
    <li>
        <strong>Results streamed back</strong> &mdash; tool results are returned
        to the LLM as part of the conversation, enabling the next reasoning step.
    </li>
</ol>

<div class="tip">
    <p>
        <strong>Tip:</strong> The entire pipeline is visible in the TUI's
        <strong>Tools</strong> panel. You can watch each stage &mdash; permission
        check, execution, and result &mdash; in real time as the agent works.
    </p>
</div>

<h3 id="concurrent-execution">Concurrent Execution</h3>

<p>
    The executor partitions tool calls into groups based on their dependencies
    and runs independent groups concurrently:
</p>

<table>
    <thead>
        <tr>
            <th>Partition</th>
            <th>Behaviour</th>
            <th>Examples</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Independent calls</strong></td>
            <td>Run in parallel when no file paths overlap.</td>
            <td>Reading <code>src/A.php</code> and <code>src/B.php</code> simultaneously.</td>
        </tr>
        <tr>
            <td><strong>Overlapping file calls</strong></td>
            <td>Serialized to prevent race conditions.</td>
            <td>A <code>file_read</code> followed by <code>file_edit</code> on the same file.</td>
        </tr>
        <tr>
            <td><strong>Subagent spawns</strong></td>
            <td>Handled on a separate worker pool; do not block regular tools.</td>
            <td><code>subagent</code> calls for parallel research or delegated work.</td>
        </tr>
    </tbody>
</table>

<p>
    Maximum parallelism is configurable via the
    <code>--max-parallel-tools</code> CLI flag (default: 4). Increasing this
    value can speed up large refactoring tasks but consumes more memory and API
    tokens.
</p>

<h3 id="output-management">Output Management</h3>

<p>
    Every tool result passes through a series of post-processors before being
    added to the conversation context:
</p>

<table>
    <thead>
        <tr>
            <th>Stage</th>
            <th>Limits</th>
            <th>Purpose</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>OutputTruncator</strong></td>
            <td>2,000 lines / 50 KB cap</td>
            <td>
                Prevents oversized results from flooding the context window.
                The full output is saved to a temporary file on disk and a
                summary is injected in place.
            </td>
        </tr>
        <tr>
            <td><strong>ToolResultDeduplicator</strong></td>
            <td>&mdash;</td>
            <td>
                Removes results that have been superseded by a later call to
                the same tool with the same parameters (e.g. re-reading a file
                after editing it).
            </td>
        </tr>
        <tr>
            <td><strong>ContextPruner</strong></td>
            <td>Configurable budget</td>
            <td>
                Replaces old, low-value tool results with short placeholders
                to free up context window space for new information.
            </td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p>
        <strong>Tip:</strong> For a deep dive into how context is managed, see
        the <a href="/docs/context-and-memory">Context &amp; Memory</a> documentation.
        It covers the pruning strategy, memory persistence, and how to tune
        the context budget for your workflow.
    </p>
</div>

<h3 id="file-change-detection">File Change Detection</h3>

<p>
    The <code>file_read</code> tool maintains a lightweight cache of previously
    read files. On subsequent reads:
</p>

<ul>
    <li>
        If the file has not been modified since the last read, the tool returns
        <strong>"unchanged since last read"</strong> instead of re-sending the
        full content &mdash; saving context window tokens and reducing latency.
    </li>
    <li>
        If the file has been modified (by <code>file_edit</code>,
        <code>file_write</code>, or an external process), the full updated
        content is returned.
    </li>
    <li>
        Large files exceeding <strong>10 MB</strong> are streamed line-by-line
        to avoid memory spikes and to respect the OutputTruncator limits.
    </li>
</ul>

<p>
    This caching behaviour is automatic and transparent to the LLM. The agent
    always sees the correct file state, but avoids wasting context on redundant
    re-reads.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> If you need to force a fresh read (for example,
        after an external build tool modifies a generated file), the agent can
        use <code>file_read</code> with <code>offset=1</code> to bypass the
        cache.
    </p>
</div>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';

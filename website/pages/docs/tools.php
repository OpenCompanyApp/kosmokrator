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
    Tools requiring approval (<code>file_write</code>, <code>file_edit</code>,
    <code>apply_patch</code>, <code>bash</code>, <code>shell_start</code>,
    <code>shell_write</code>, and <code>execute_lua</code>) depend on your
    <a href="/docs/permissions">permission mode</a>. In <strong>Prometheus</strong>
    mode governed prompts are auto-approved, but blocked paths and explicit deny
    rules are still enforced.
</div>

<!-- ================================================================== -->
<h2 id="file-operations">File Operations</h2>
<!-- ================================================================== -->

<h3 id="file_read">file_read</h3>

<p>
    Read a file from the project with line numbers. Supports partial reads via offset/limit and
    includes an automatic caching layer: if a file has not changed since the last read, the tool
    returns <code>[Unchanged since last file_read of path (lines X-Y); content omitted to save tokens]</code>
    instead of re-sending the full contents. Files larger than 10 MB are streamed line-by-line to
    avoid memory pressure.
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
    Returns the edit summary with separate removed and added line counts (e.g.,
    <code>"Edited path (-5, +3 lines)"</code>).
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
    Apply a structured patch to one or more files. Uses KosmoKrator's custom patch format with
    <code>*** Begin Patch</code> / <code>*** End Patch</code> delimiters and file-level markers:
    <code>*** Add File:</code>, <code>*** Update File:</code>, and <code>*** Delete File:</code>.
    Supports multi-file, multi-hunk patches. The parser streams the patch content for memory
    efficiency, making it suitable for large changesets.
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
            <td>The patch content using <code>*** Begin Patch</code> / <code>*** End Patch</code> format.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Apply a multi-file patch:</p>
<pre><code>apply_patch patch="*** Begin Patch
*** Update File: src/Model/User.php
@@ -12,6 +12,7 @@ class User
     public string $name;
     public string $email;
+    public ?string $avatar = null;

     public function __construct(string $name)
*** Update File: tests/UserTest.php
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
 }
*** End Patch"</code></pre>


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
    maximum speed; falls back to GNU grep otherwise. Returns up to 50 matches per file, capped at
    100 output lines, each with file path and line number.
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
            <td><code>session_id</code></td>
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
        <tr>
            <td><code>submit</code></td>
            <td>boolean</td>
            <td>No</td>
            <td>Whether to append a newline after the input. Defaults to <code>true</code>.</td>
        </tr>
        <tr>
            <td><code>wait_ms</code></td>
            <td>int</td>
            <td>No</td>
            <td>Milliseconds to wait for output after writing.</td>
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
            <td><code>session_id</code></td>
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
            <td><code>session_id</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The session ID to terminate.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Start a dev server, verify it boots, then tear it down:</p>
<pre><code># Start the server
shell_start command="php artisan serve --port=8080" wait_ms=2000
# Returns: { session_id: "sess_abc123", output: "Starting development server..." }

# Check that it is responding
shell_read session_id="sess_abc123" wait_ms=1000

# Shut it down when done
shell_kill session_id="sess_abc123"</code></pre>


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
            <td>No</td>
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
            <td>Sequential execution group &mdash; agents in the same group run one at a time.</td>
        </tr>
        <tr>
            <td><code>agents</code></td>
            <td>array</td>
            <td>No</td>
            <td>
                Batch mode: array of agent specs to run concurrently. Each spec is an object with
                <code>task</code> (required), <code>type</code>, <code>id</code>,
                <code>depends_on</code>, and <code>group</code>. When set, the top-level
                <code>task</code>, <code>type</code>, <code>id</code>, <code>depends_on</code>,
                and <code>group</code> parameters are ignored.
            </td>
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
                Memory scope: <code>project</code> (codebase facts, architecture),
                <code>user</code> (preferences, workflow), or
                <code>decision</code> (architectural choices, trade-offs).
            </td>
        </tr>
        <tr>
            <td><code>title</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Short descriptive title for the memory.</td>
        </tr>
        <tr>
            <td><code>content</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Markdown content &mdash; the knowledge to persist.</td>
        </tr>
        <tr>
            <td><code>class</code></td>
            <td>string</td>
            <td>No</td>
            <td>
                Memory class: <code>priority</code>, <code>working</code>, or
                <code>durable</code> (default).
            </td>
        </tr>
        <tr>
            <td><code>pinned</code></td>
            <td>boolean</td>
            <td>No</td>
            <td>Whether the memory should be favored during recall.</td>
        </tr>
        <tr>
            <td><code>expires_days</code></td>
            <td>number</td>
            <td>No</td>
            <td>Optional expiry in days &mdash; useful for working memory.</td>
        </tr>
        <tr>
            <td><code>id</code></td>
            <td>string</td>
            <td>No</td>
            <td>Existing memory ID to update. Omit to create a new memory.</td>
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
    Search and list saved memories. Use to recall project facts, user preferences, or past
    decisions before asking the user. Returns ranked results with title, type, and content preview.
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
            <td>No</td>
            <td>Text to search for in memory titles and content.</td>
        </tr>
        <tr>
            <td><code>type</code></td>
            <td>string</td>
            <td>No</td>
            <td>Filter by memory type: <code>project</code>, <code>user</code>, <code>decision</code>, or <code>compaction</code>.</td>
        </tr>
        <tr>
            <td><code>class</code></td>
            <td>string</td>
            <td>No</td>
            <td>Filter by memory class: <code>priority</code>, <code>working</code>, or <code>durable</code>.</td>
        </tr>
        <tr>
            <td><code>scope</code></td>
            <td>string</td>
            <td>No</td>
            <td>Search scope: <code>memories</code> (default), <code>history</code>, or <code>both</code>.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Recall a previous decision about caching:</p>
<pre><code>memory_search query="caching strategy"</code></pre>


<!-- ================================================================== -->
<h2 id="session-history">Session History</h2>
<!-- ================================================================== -->

<p>
    Session tools let the agent browse and search prior KosmoKrator conversations
    for the current project. They are useful when a user refers to earlier work,
    asks what happened last time, or wants the agent to reuse decisions from a
    previous session.
</p>

<h3 id="session_search">session_search</h3>

<p>
    Browse recent sessions or search across saved session history. With no query,
    it returns recent sessions with IDs, titles, dates, message counts, and a
    preview. With a query, it performs keyword search and groups matches by
    session.
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
            <td>No</td>
            <td>Search terms, exact phrases in quotes, or file paths. Omit to browse recent sessions.</td>
        </tr>
        <tr>
            <td><code>limit</code></td>
            <td>integer</td>
            <td>No</td>
            <td>Maximum number of sessions or search groups to return. Defaults to 5, max 10.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Find previous work on authentication:</p>
<pre><code>session_search query="authentication middleware" limit=5</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="session_read">session_read</h3>

<p>
    Load a prior session transcript by full ID or unique prefix. Use this after
    <code>session_search</code> when the agent needs the detailed context from a
    specific past conversation.
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
            <td><code>session_id</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>Session ID or unique prefix. <code>session_search</code> shows short prefixes in brackets.</td>
        </tr>
        <tr>
            <td><code>limit</code></td>
            <td>integer</td>
            <td>No</td>
            <td>Maximum messages to return. Defaults to 50, max 200.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Read a matching session:</p>
<pre><code>session_read session_id="a1b2c3d4" limit=80</code></pre>


<!-- ================================================================== -->
<h2 id="tasks">Tasks</h2>
<!-- ================================================================== -->

<p>
    The task system lets the agent track multi-step work items. Tasks persist in the session and
    appear in the UI's context bar so both the agent and the user can see progress at a glance.
</p>

<h3 id="task_create">task_create</h3>

<p>
    Create one or more tasks to track work progress. Use <code>subject</code> for a single task, or
    <code>tasks</code> (JSON array) to create multiple at once. Each task can optionally be nested
    under a parent. Provide either <code>subject</code> or <code>tasks</code>, but not both.
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
            <td><code>subject</code></td>
            <td>string</td>
            <td>No*</td>
            <td>Task title (for creating a single task).</td>
        </tr>
        <tr>
            <td><code>description</code></td>
            <td>string</td>
            <td>No</td>
            <td>Task details or acceptance criteria (single task mode).</td>
        </tr>
        <tr>
            <td><code>active_form</code></td>
            <td>string</td>
            <td>No</td>
            <td>Present-continuous label for the spinner, e.g. <code>"Running tests"</code> (single task mode).</td>
        </tr>
        <tr>
            <td><code>parent_id</code></td>
            <td>string</td>
            <td>No</td>
            <td>Parent task ID for nesting (single task mode).</td>
        </tr>
        <tr>
            <td><code>tasks</code></td>
            <td>string</td>
            <td>No*</td>
            <td>
                JSON array of task objects for batch creation. Each object:
                <code>{"subject": "...", "description": "...", "active_form": "...", "parent_id": "..."}</code>.
                Only <code>subject</code> is required per object.
            </td>
        </tr>
    </tbody>
</table>

<p class="small text-muted">* Must provide either <code>subject</code> (single) or <code>tasks</code> (batch), but not both.</p>

<p><strong>Example:</strong></p>
<pre><code>task_create subject="Migrate user table to UUIDs" description="Replace auto-increment IDs with UUIDv7 primary keys."</code></pre>

<p><strong>Example:</strong> Batch-create multiple tasks:</p>
<pre><code>task_create tasks='[{"subject":"Add avatar column","active_form":"Adding avatar column"},{"subject":"Update user factory","active_form":"Updating user factory"}]'</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="task_update">task_update</h3>

<p>
    Update a task's status, subject, description, or dependencies. Status flow:
    <code>pending</code> &rarr; <code>in_progress</code> &rarr; <code>completed</code> |
    <code>cancelled</code>.
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
            <td><code>id</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>The task ID.</td>
        </tr>
        <tr>
            <td><code>status</code></td>
            <td>string</td>
            <td>No</td>
            <td>
                New status: <code>pending</code>, <code>in_progress</code>,
                <code>completed</code>, or <code>cancelled</code>.
            </td>
        </tr>
        <tr>
            <td><code>subject</code></td>
            <td>string</td>
            <td>No</td>
            <td>Updated task title.</td>
        </tr>
        <tr>
            <td><code>description</code></td>
            <td>string</td>
            <td>No</td>
            <td>Updated task details.</td>
        </tr>
        <tr>
            <td><code>active_form</code></td>
            <td>string</td>
            <td>No</td>
            <td>Updated spinner label.</td>
        </tr>
        <tr>
            <td><code>add_blocked_by</code></td>
            <td>string</td>
            <td>No</td>
            <td>JSON array of task IDs that block this task.</td>
        </tr>
        <tr>
            <td><code>add_blocks</code></td>
            <td>string</td>
            <td>No</td>
            <td>JSON array of task IDs this task blocks.</td>
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
<h2 id="lua-scripting">Lua Scripting</h2>
<!-- ================================================================== -->

<p>
    The Lua scripting tools let the agent execute Lua code inside a sandboxed environment with
    access to integration APIs and native tools. Always start by discovering available namespaces
    with <code>lua_list_docs</code>, then read detailed docs with <code>lua_read_doc</code> before
    writing code.
</p>

<div class="tip">
    For the full Lua model &mdash; host-side Lua tools, <code>app.integrations.*</code>,
    <code>app.tools.*</code>, multi-account namespaces, and discovery workflow &mdash; see
    <a href="/docs/lua">Lua</a>.
</div>

<h3 id="execute_lua">execute_lua</h3>

<p>
    Execute Lua code with <code>app.*</code> namespace access. Use <code>app.integrations.*</code>
    for API calls, <code>app.tools.*</code> for native tools (file_read, glob, grep, bash, subagent,
    etc.). Always use <code>lua_read_doc</code> first to look up function names and parameters. Use
    <code>print()</code> or <code>dump()</code> for output.
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
            <td><code>code</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>
                Lua code to execute. Use <code>print()</code>/<code>dump()</code> for output.
                Access integrations via <code>app.integrations.{name}.{function}()</code>.
            </td>
        </tr>
        <tr>
            <td><code>memoryLimit</code></td>
            <td>integer</td>
            <td>No</td>
            <td>Memory limit in bytes. Default: 33554432 (32 MB).</td>
        </tr>
        <tr>
            <td><code>cpuLimit</code></td>
            <td>number</td>
            <td>No</td>
            <td>CPU time limit in seconds. Default: 30.0.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Query an integration and print the result:</p>
<pre><code>execute_lua code="local stats = app.integrations.plausible.query_stats({site_id='example.com'})
print(stats.visitors)"</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="lua_list_docs">lua_list_docs</h3>

<p>
    List available Lua API namespaces and functions. Each namespace maps to an integration
    (plausible, coingecko, celestial, etc.). Shows function signatures with parameter names.
    Use this first to discover what integrations are available.
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
            <td><code>namespace</code></td>
            <td>string</td>
            <td>No</td>
            <td>Filter to a specific namespace (e.g. <code>"integrations.plausible"</code>). Omit to list all.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> List all available namespaces:</p>
<pre><code>lua_list_docs</code></pre>

<p><strong>Example:</strong> Show functions in a specific integration:</p>
<pre><code>lua_list_docs namespace="integrations.plausible"</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="lua_search_docs">lua_search_docs</h3>

<p>
    Search the Lua scripting API documentation by keyword. Searches function names, descriptions,
    and parameter info across all available namespaces and supplementary docs.
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
            <td>The search query (e.g. <code>"send message"</code>, <code>"analytics"</code>, <code>"query stats"</code>).</td>
        </tr>
        <tr>
            <td><code>limit</code></td>
            <td>integer</td>
            <td>No</td>
            <td>Maximum number of results. Default: 10.</td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong></p>
<pre><code>lua_search_docs query="analytics" limit=5</code></pre>

<!-- ------------------------------------------------------------------ -->

<h3 id="lua_read_doc">lua_read_doc</h3>

<p>
    Read Lua API documentation for a namespace, function, or guide.
</p>

<ul>
    <li><strong>Namespace</strong> (e.g. <code>"integrations.plausible"</code>) &rarr; full API reference with all functions and parameters</li>
    <li><strong>Function</strong> (e.g. <code>"integrations.plausible.query_stats"</code>) &rarr; detailed single-function docs</li>
    <li><strong>Guide</strong> (e.g. <code>"overview"</code>, <code>"examples"</code>) &rarr; supplementary documentation</li>
</ul>

<p>
    Always use <code>lua_read_doc</code> before writing Lua code to confirm function names and parameters.
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
            <td><code>page</code></td>
            <td>string</td>
            <td>Yes</td>
            <td>
                Page to read: namespace (e.g. <code>"integrations.plausible"</code>), function path
                (e.g. <code>"integrations.plausible.query_stats"</code>), or guide name
                (e.g. <code>"overview"</code>, <code>"examples"</code>).
            </td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong></p>
<pre><code>lua_read_doc page="integrations.plausible.query_stats"</code></pre>


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
    mode) or selectable options (TUI mode). Each choice is a JSON object with
    <code>label</code> (required), <code>detail</code> (optional description), and
    <code>recommended</code> (optional boolean to highlight the suggested option).
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
            <td>string</td>
            <td>Yes</td>
            <td>
                JSON array of choice objects. Each object:
                <code>{"label": "...", "detail": "...", "recommended": true/false}</code>.
                Only <code>label</code> is required per object.
            </td>
        </tr>
    </tbody>
</table>

<p><strong>Example:</strong> Let the user pick a database driver:</p>
<pre><code>ask_choice \
  question="Which database driver should this project use?" \
  choices='[{"label":"SQLite","detail":"Lightweight, zero config","recommended":true},{"label":"MySQL","detail":"Production-grade"},{"label":"PostgreSQL","detail":"Advanced features"}]'</code></pre>

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
        <strong><code>[Unchanged since last file_read of path (lines X-Y); content omitted to save tokens]</code></strong>
        instead of re-sending the full content &mdash; saving context window tokens and reducing latency.
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
include __DIR__.'/../_docs-layout.php';

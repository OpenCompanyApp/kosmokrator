<?php
$docTitle = 'Lua';
$docSlug = 'lua';
ob_start();
?>

<p class="lead">
    KosmoKrator exposes a sandboxed Lua runtime for multi-step integration work. Lua sits between
    plain tool calls and a full agent turn: you can discover namespaces with docs tools, then run
    deterministic scripts against <code>app.integrations.*</code> and <code>app.tools.*</code>.
</p>

<div class="tip">
    Use the discovery flow in this order: <code>lua_list_docs</code> to see what exists,
    <code>lua_read_doc</code> to confirm the exact namespace or function contract, then
    <code>execute_lua</code> to run code. Do not guess function names or response shapes.
</div>

<div class="tip">
    For scripts and other coding CLIs, use <code>kosmokrator integrations:lua</code>. That
    headless endpoint exposes integration namespaces and <code>docs.list</code>,
    <code>docs.search</code>, and <code>docs.read</code> helpers without starting an agent
    session. See <a href="/docs/integrations">Integrations CLI</a> for the full reference.
</div>

<!-- ================================================================== -->
<h2 id="how-it-works">How Lua Works</h2>
<!-- ================================================================== -->

<p>
    Lua scripts run inside a restricted sandbox. They do not get direct filesystem, shell, or
    network access. Instead, KosmoKrator exposes controlled entry points under the <code>app</code>
    namespace.
</p>

<p>
    There are two main surfaces:
</p>

<ul>
    <li><code>app.integrations.*</code> &mdash; enabled integrations exposed as callable Lua namespaces</li>
    <li><code>app.tools.*</code> &mdash; KosmoKrator's built-in native tools, callable from Lua</li>
</ul>

<p>
    In the standalone <code>integrations:lua</code> command, the runtime exposes
    <code>app.integrations.*</code> plus docs helpers. The native coding tools under
    <code>app.tools.*</code> are only available when Lua is executed from inside a KosmoKrator
    agent session.
</p>

<p>
    Lua also exposes helper namespaces:
</p>

<ul>
    <li><code>json.decode(...)</code> and <code>json.encode(...)</code> for JSON parsing/serialization</li>
    <li><code>regex.match(...)</code>, <code>regex.match_all(...)</code>, and <code>regex.gsub(...)</code> for PCRE regex work</li>
</ul>

<!-- ================================================================== -->
<h2 id="host-tools">Lua Host Tools</h2>
<!-- ================================================================== -->

<p>
    These are the KosmoKrator tools you call from the agent side to discover or run Lua. They are
    not part of <code>app.tools</code>; they are top-level agent tools.
</p>

<table>
    <thead>
        <tr>
            <th>Tool</th>
            <th>Role</th>
            <th>When to use it</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>lua_list_docs</code></td>
            <td>Discovery catalog</td>
            <td>See which namespaces exist without dumping full function references.</td>
        </tr>
        <tr>
            <td><code>lua_search_docs</code></td>
            <td>Keyword search</td>
            <td>Find likely namespaces or functions by term when you do not know the exact path.</td>
        </tr>
        <tr>
            <td><code>lua_read_doc</code></td>
            <td>Detailed reference</td>
            <td>Read one namespace, one function, or one guide page before writing code.</td>
        </tr>
        <tr>
            <td><code>execute_lua</code></td>
            <td>Runtime execution</td>
            <td>Run a Lua script after you have confirmed the contract with the docs tools.</td>
        </tr>
    </tbody>
</table>

<p>
    Typical workflow:
</p>

<pre><code>lua_list_docs
lua_read_doc page="integrations.coingecko"
lua_read_doc page="integrations.coingecko.price"
execute_lua code="local prices = app.integrations.coingecko.price({ids={'bitcoin'}, vs_currencies={'usd'}})
print(prices.bitcoin.usd)"</code></pre>

<!-- ================================================================== -->
<h2 id="namespaces">Namespace Model</h2>
<!-- ================================================================== -->

<p>
    Integration namespaces follow a stable account-aware shape:
</p>

<pre><code>app.integrations.{name}.*          -- default account path
app.integrations.{name}.default.*  -- explicit default alias
app.integrations.{name}.{account}.* -- named account/credential alias
app.tools.{tool_name}(args)        -- native KosmoKrator tools</code></pre>

<p>
    Important rules:
</p>

<ul>
    <li>Only integrations that are enabled and configured are callable in a session.</li>
    <li><code>lua_list_docs</code> and <code>lua_read_doc</code> can still describe installed-but-inactive integrations.</li>
    <li>The root integration namespace usually means "use the default account".</li>
    <li><code>.default</code> is an explicit alias for the default account.</li>
    <li><code>.{account}</code> is only present when you have multiple named credentials/accounts saved for the same integration.</li>
</ul>

<h3 id="single-vs-multi-account">Single vs Multi-Account</h3>

<p>
    Single-account integration:
</p>

<pre><code>app.integrations.github.search_repositories({...})
app.integrations.github.default.search_repositories({...})  -- explicit alias</code></pre>

<p>
    Multi-account integration:
</p>

<pre><code>app.integrations.github.default.search_repositories({...})
app.integrations.github.work.search_repositories({...})
app.integrations.github.personal.search_repositories({...})</code></pre>

<!-- ================================================================== -->
<h2 id="native-tools">Native Lua Tools: app.tools.*</h2>
<!-- ================================================================== -->

<p>
    Inside <code>execute_lua</code>, KosmoKrator's built-in tools are available through
    <code>app.tools.*</code>. These are the internal Lua tools you can compose inside scripts.
</p>

<p>
    Current built-in namespaces:
</p>

<table>
    <thead>
        <tr>
            <th>Tool</th>
            <th>Purpose</th>
        </tr>
    </thead>
    <tbody>
        <tr><td><code>app.tools.file_read</code></td><td>Read file content from the project.</td></tr>
        <tr><td><code>app.tools.file_write</code></td><td>Create or fully overwrite a file.</td></tr>
        <tr><td><code>app.tools.file_edit</code></td><td>Apply a targeted find/replace edit.</td></tr>
        <tr><td><code>app.tools.apply_patch</code></td><td>Apply structured patch hunks to files.</td></tr>
        <tr><td><code>app.tools.glob</code></td><td>List files by pattern.</td></tr>
        <tr><td><code>app.tools.grep</code></td><td>Search file contents by pattern.</td></tr>
        <tr><td><code>app.tools.bash</code></td><td>Run a shell command through the normal permission system.</td></tr>
        <tr><td><code>app.tools.shell_start</code></td><td>Start a persistent shell session.</td></tr>
        <tr><td><code>app.tools.shell_write</code></td><td>Write to an existing shell session.</td></tr>
        <tr><td><code>app.tools.shell_read</code></td><td>Read output from an existing shell session.</td></tr>
        <tr><td><code>app.tools.shell_kill</code></td><td>Terminate a shell session.</td></tr>
        <tr><td><code>app.tools.task_create</code></td><td>Create a task in the current session task store.</td></tr>
        <tr><td><code>app.tools.task_update</code></td><td>Update task status or fields.</td></tr>
        <tr><td><code>app.tools.task_list</code></td><td>List tasks in the current session.</td></tr>
        <tr><td><code>app.tools.task_get</code></td><td>Read one task by id.</td></tr>
        <tr><td><code>app.tools.memory_save</code></td><td>Persist a memory item.</td></tr>
        <tr><td><code>app.tools.memory_search</code></td><td>Search stored memories.</td></tr>
        <tr><td><code>app.tools.session_search</code></td><td>Search prior sessions.</td></tr>
        <tr><td><code>app.tools.session_read</code></td><td>Read one prior session in detail.</td></tr>
        <tr><td><code>app.tools.subagent</code></td><td>Spawn one or more child agents from Lua.</td></tr>
    </tbody>
</table>

<p>
    Not exposed inside <code>app.tools</code>:
</p>

<ul>
    <li><code>execute_lua</code> &mdash; Lua does not recursively call itself</li>
    <li><code>lua_list_docs</code>, <code>lua_search_docs</code>, <code>lua_read_doc</code> &mdash; these stay at the host-tool level</li>
    <li><code>ask_user</code>, <code>ask_choice</code> &mdash; interactive prompt tools are not bridged into Lua</li>
</ul>

<h3 id="native-tool-results">Return Shape</h3>

<p>
    Native tools return structured tables rather than plain strings. Every result includes at least:
</p>

<ul>
    <li><code>output</code> &mdash; human-readable combined result string</li>
    <li><code>success</code> &mdash; boolean success flag</li>
</ul>

<p>
    Some tools expose more metadata. For example, <code>bash</code> also returns
    <code>stdout</code>, <code>stderr</code>, and <code>exit_code</code>.
</p>

<pre><code>local result = app.tools.bash({command = "git status --short"})
if result.success then
    print(result.stdout)
else
    print(result.stderr)
end</code></pre>

<!-- ================================================================== -->
<h2 id="discovery">Discovery and Docs Flow</h2>
<!-- ================================================================== -->

<p>
    <code>lua_list_docs</code> is intentionally short. It is a namespace catalog, not a partial
    reference. That is by design: short discovery output reduces guessing and pushes the agent to
    read the real docs before calling anything.
</p>

<p>
    Use the tools like this:
</p>

<ol>
    <li><code>lua_list_docs</code> &mdash; see what namespaces exist</li>
    <li><code>lua_search_docs</code> &mdash; search by concept when needed</li>
    <li><code>lua_read_doc page="integrations.NAME"</code> &mdash; inspect the namespace</li>
    <li><code>lua_read_doc page="integrations.NAME.function"</code> &mdash; inspect one function</li>
    <li><code>execute_lua</code> &mdash; run the script</li>
</ol>

<p>
    Guide pages currently include:
</p>

<ul>
    <li><code>overview</code></li>
    <li><code>context</code></li>
    <li><code>errors</code></li>
    <li><code>examples</code></li>
    <li><code>tools</code> &mdash; generated docs for <code>app.tools.*</code></li>
</ul>

<!-- ================================================================== -->
<h2 id="examples">Examples</h2>
<!-- ================================================================== -->

<h3 id="example-integration-call">Single Integration Call</h3>

<pre><code>execute_lua code="local result = app.integrations.coingecko.price({
  ids = {'bitcoin', 'ethereum'},
  vs_currencies = {'usd'}
})
dump(result)"</code></pre>

<h3 id="example-native-tools">Using Native Tools from Lua</h3>

<pre><code>execute_lua code="local files = app.tools.glob({pattern = 'src/**/*.php'})
print(files.output)

local status = app.tools.bash({command = 'git status --short'})
print(status.stdout)"</code></pre>

<h3 id="example-subagents">Spawning Subagents from Lua</h3>

<pre><code>execute_lua code="local result = app.tools.subagent({
  agents = {
    {task = 'Inspect routing', id = 'routing'},
    {task = 'Inspect auth', id = 'auth'},
    {task = 'Inspect db', id = 'db'},
  }
})
dump(result)"</code></pre>

<!-- ================================================================== -->
<h2 id="practical-rules">Practical Rules</h2>
<!-- ================================================================== -->

<ul>
    <li>Read the docs before writing Lua. The docs-first flow is part of the system design.</li>
    <li>Do not assume raw upstream API response shapes. Integrations may normalize fields and structure.</li>
    <li>If the docs do not describe the return shape clearly, inspect with a minimal call first.</li>
    <li>Use Lua when you need deterministic multi-step orchestration; use normal tools when one direct tool call is enough.</li>
    <li>All normal permission and integration access rules still apply inside Lua.</li>
</ul>

<div class="tip">
    For per-tool parameter reference, see <a href="/docs/tools">Tools</a>. For integration setup and
    activation, see <a href="/docs/configuration">Configuration</a>.
</div>

<?php
$docContent = ob_get_clean();
include __DIR__.'/../_docs-layout.php';

<?php
$docTitle = 'MCP';
$docSlug = 'mcp';
ob_start();
?>

<p class="lead">
    KosmoKrator can use Model Context Protocol servers from the same project-level
    <code>.mcp.json</code> files used by other coding agents. MCP servers are exposed through
    headless CLI commands and through Lua as <code>app.mcp.*</code>; they are not registered as
    native model tools.
</p>

<div class="tip">
    Recommended agent flow: <code>mcp:list --json</code>, review the server command,
    <code>mcp:trust SERVER --project --json</code>, <code>mcp:tools SERVER --json</code>,
    <code>mcp:schema SERVER.TOOL --json</code>, then <code>mcp:call SERVER.TOOL --json</code>
    or <code>mcp:lua</code>. In agent Lua code mode, use
    <code>lua_read_doc page="mcp.SERVER"</code> before calling
    <code>app.mcp.SERVER.TOOL(...)</code>.
</div>

<p>
    PHP applications can use the same runtime through the <a href="/docs/sdk">Agent SDK</a>:
    <code>$agent-&gt;mcp()-&gt;call(...)</code>, <code>$agent-&gt;mcp()-&gt;lua(...)</code>, and
    runtime-only <code>-&gt;withMcpServer(...)</code> overlays.
</p>

<h2 id="config">Portable Config</h2>

<p>
    Project MCP servers live in <code>.mcp.json</code> using the common <code>mcpServers</code>
    shape:
</p>

<pre><code>{
  "mcpServers": {
    "github": {
      "command": "github-mcp-server",
      "args": [],
      "env": {
        "GITHUB_TOKEN": "${GITHUB_TOKEN}"
      }
    }
  }
}</code></pre>

<p>
    KosmoKrator also reads VS Code style <code>.vscode/mcp.json</code> and Cursor style
    <code>.cursor/mcp.json</code> files with a top-level <code>servers</code> object. Effective
    precedence is global config first, then project compatibility files, then project
    <code>.mcp.json</code>.
</p>

<pre><code># Write project .mcp.json
kosmokrator mcp:add github --project --type=stdio \
  --command=github-mcp-server --env GITHUB_TOKEN --json

# Write ~/.kosmokrator/mcp.json
kosmokrator mcp:add context7 --global --type=stdio \
  --command=npx --arg=-y --arg=@upstash/context7-mcp --json

# Import a VS Code or Cursor file into .mcp.json
kosmokrator mcp:import .vscode/mcp.json --project --json

# Export effective config in either shape
kosmokrator mcp:export --format=mcpServers --json
kosmokrator mcp:export --format=vscode --path=.vscode/mcp.json --json</code></pre>

<h2 id="presets">Web Provider Presets</h2>

<p>
    For common web-search MCP servers, <code>mcp:preset</code> writes a portable server entry
    without making you remember package names.
</p>

<pre><code># List available presets
kosmokrator mcp:preset list --json

# Add a project-level preset
kosmokrator mcp:preset add tavily --project --json
kosmokrator mcp:preset add firecrawl --project --json
kosmokrator mcp:preset add exa --project --json
kosmokrator mcp:preset add fetch --project --json
kosmokrator mcp:preset add parallel --project --json</code></pre>

<p>
    Presets use environment placeholders such as <code>${TAVILY_API_KEY}</code>. Store shared
    project config in <code>.mcp.json</code> and keep actual secrets in the shell environment or
    with <code>mcp:secret:set</code>.
</p>

<h2 id="headless">Headless Calls</h2>

<pre><code># Discovery
kosmokrator mcp:list --json
kosmokrator mcp:status --json
kosmokrator mcp:trust github --project --json
kosmokrator mcp:tools github --json
kosmokrator mcp:schema github.search_repositories --json

# Generic call
kosmokrator mcp:call github.search_repositories \
  --query="kosmokrator language:php" --json

# Server shortcut
kosmokrator mcp:github search_repositories \
  --query="kosmokrator language:php" --json

# JSON payload
printf '%s\n' '{"query":"kosmokrator"}' | \
  kosmokrator mcp:call github.search_repositories --json</code></pre>

<p>
    All command JSON envelopes include <code>success</code>. Runtime calls return non-zero exit
    codes when config, trust, permission, transport, schema, or server execution fails.
</p>

<h2 id="lua">Lua Mode</h2>

<pre><code># Dedicated MCP Lua endpoint
kosmokrator mcp:lua --eval 'dump(app.mcp.github.search_repositories({
  query = "kosmokrator"
}))' --json

# Shared integration Lua endpoint also exposes app.mcp.*
kosmokrator integrations:lua workflow.lua --json</code></pre>

<p>
    Agent-side <code>execute_lua</code> sees the same <code>app.mcp.*</code> namespace alongside
    <code>app.integrations.*</code> and <code>app.tools.*</code>.
</p>

<pre><code>local repos = app.mcp.github.search_repositories({
  query = "kosmokrator language:php"
})
dump(repos)</code></pre>

<p>
    Lua helper functions are available for discovery and non-tool MCP surfaces:
</p>

<pre><code>dump(mcp.servers())
dump(mcp.tools("github"))
dump(mcp.schema("github.search_repositories"))
dump(mcp.resources("github"))
dump(mcp.read_resource("github", "resource://..."))
dump(mcp.prompts("github"))
dump(mcp.get_prompt("github", "summarize", { text = "..." }))</code></pre>

<h2 id="secrets">Secrets</h2>

<p>
    Do not commit tokens into <code>.mcp.json</code>. Use environment variables for shared files
    or KosmoKrator's MCP secret store for headless machines.
</p>

<pre><code>printf %s "$GITHUB_TOKEN" | \
  kosmokrator mcp:secret:set github env.GITHUB_TOKEN --stdin --json

kosmokrator mcp:add github --project --type=stdio \
  --command=github-mcp-server \
  --env GITHUB_TOKEN='${KOSMO_SECRET:mcp.github.env.GITHUB_TOKEN}' \
  --json

kosmokrator mcp:secret:list github --json
kosmokrator mcp:secret:unset github env.GITHUB_TOKEN --json</code></pre>

<h2 id="permissions">Trust And Permissions</h2>

<p>
    Project MCP config can spawn arbitrary commands. KosmoKrator requires project MCP servers to be
    trusted before normal headless discovery or execution. Review <code>.mcp.json</code>, then run:
</p>

<pre><code>kosmokrator mcp:trust github --project --json</code></pre>

<p>
    Read/write policy is separate from trust. Unknown MCP tools default to <code>write</code>
    unless the server marks them with MCP <code>readOnlyHint</code>.
</p>

<pre><code>kosmokrator mcp:add github --project --read=allow --write=ask --json

# Headless ask cannot show a modal, so this fails unless write is allowed.
kosmokrator mcp:call github.create_issue --title="..." --json

# Trusted automation can bypass MCP trust and read/write policy for one call.
kosmokrator mcp:call github.create_issue --title="..." --force --json</code></pre>

<h2 id="commands">Command Reference</h2>

<table>
    <thead><tr><th>Command</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>mcp:list</code></td><td>List effective MCP servers and source paths.</td></tr>
        <tr><td><code>mcp:status</code></td><td>Show configured/disabled status without starting servers.</td></tr>
        <tr><td><code>mcp:add</code>, <code>mcp:remove</code>, <code>mcp:enable</code>, <code>mcp:disable</code></td><td>Manage portable MCP config.</td></tr>
        <tr><td><code>mcp:trust</code></td><td>Trust the current project server fingerprint.</td></tr>
        <tr><td><code>mcp:tools</code>, <code>mcp:schema</code></td><td>Discover callable MCP tools.</td></tr>
        <tr><td><code>mcp:call</code>, <code>mcp:&lt;server&gt;</code></td><td>Call MCP tools headlessly.</td></tr>
        <tr><td><code>mcp:lua</code></td><td>Run Lua with <code>app.mcp.*</code>.</td></tr>
        <tr><td><code>mcp:resources</code>, <code>mcp:resource</code></td><td>List/read MCP resources.</td></tr>
        <tr><td><code>mcp:prompts</code>, <code>mcp:prompt</code></td><td>List/get MCP prompts.</td></tr>
        <tr><td><code>mcp:secret:*</code></td><td>Set/list/unset MCP secrets without echoing values.</td></tr>
        <tr><td><code>mcp:import</code>, <code>mcp:export</code></td><td>Move between <code>mcpServers</code> and VS Code <code>servers</code> shapes.</td></tr>
        <tr><td><code>mcp:doctor</code>, <code>mcp:examples</code></td><td>Agent-friendly diagnostics and examples.</td></tr>
    </tbody>
</table>

<?php
$docContent = ob_get_clean();
include __DIR__.'/../_docs-layout.php';

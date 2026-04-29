<?php
$docTitle = 'ACP';
$docSlug = 'acp';
ob_start();
?>

<p>
    KosmoKrator can run as an <strong>Agent Client Protocol</strong> server for
    editors and IDEs that speak ACP:
</p>

<pre><code>kosmokrator acp</code></pre>

<p>
    ACP mode is a protocol surface over the same runtime used by the terminal UI
    and headless CLI. It reuses provider credentials, sessions, permissions,
    built-in tools, Lua, integrations, MCP, memory, tasks, and subagents.
</p>

<h2 id="quick-start">Quick Start</h2>

<pre><code>kosmokrator setup
kosmokrator acp
kosmokrator acp --cwd /path/to/project --mode edit --permission-mode guardian
kosmokrator acp --yolo</code></pre>

<p>
    ACP uses newline-delimited JSON-RPC over stdin/stdout. Stdout is reserved
    for protocol frames; logs and diagnostics go to stderr.
</p>

<h2 id="editor-config">Editor Configuration</h2>

<h3>Zed</h3>

<pre><code>{
  "agent_servers": {
    "kosmokrator": {
      "command": "kosmokrator",
      "args": ["acp"]
    }
  }
}</code></pre>

<h3>JetBrains</h3>

<pre><code>{
  "agents": {
    "kosmokrator": {
      "command": "kosmokrator",
      "args": ["acp"]
    }
  }
}</code></pre>

<h3>Neovim / CodeCompanion</h3>

<pre><code>acp_providers = {
  kosmokrator = {
    command = "kosmokrator",
    args = { "acp" }
  }
}</code></pre>

<h2 id="capabilities">Capabilities</h2>

<table>
    <thead><tr><th>ACP Feature</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td>Initialize</td><td>Supported</td><td>Advertises KosmoKrator version, prompt support, sessions, and auth method.</td></tr>
        <tr><td>New session</td><td>Supported</td><td>Creates a normal persisted KosmoKrator session for the requested cwd.</td></tr>
        <tr><td>Prompt</td><td>Supported</td><td>Streams assistant text and tool updates through <code>session/update</code>.</td></tr>
        <tr><td>Load / resume</td><td>Supported</td><td>Loads existing persisted sessions by ID.</td></tr>
        <tr><td>List sessions</td><td>Supported</td><td>Lists workspace sessions without requiring an LLM provider call.</td></tr>
        <tr><td>Cancel</td><td>Supported</td><td>Cancels the active turn and subagents where the underlying operation honors cancellation.</td></tr>
        <tr><td>Permissions</td><td>Supported</td><td>Guardian/Argus prompts are bridged to ACP <code>session/request_permission</code>.</td></tr>
        <tr><td>Config options</td><td>Supported</td><td>Model, agent mode, and permission mode are exposed through <code>session/set_config_option</code>.</td></tr>
        <tr><td>Legacy model switch</td><td>Supported</td><td><code>session/set_model</code> is accepted for clients that still use the draft model endpoint.</td></tr>
        <tr><td>Client MCP servers</td><td>Supported for stdio</td><td>ACP <code>mcpServers</code> become runtime-only MCP overlays for that active session and appear in <code>app.mcp.*</code>.</td></tr>
        <tr><td>Client filesystem / terminal delegation</td><td>Planned</td><td>File and shell tools currently execute through KosmoKrator's local tool layer.</td></tr>
    </tbody>
</table>

<h2 id="sessions">Sessions</h2>

<p>
    ACP session IDs are KosmoKrator session IDs. A session created in an editor
    can be resumed from the CLI, and a terminal-created session can be loaded by
    an ACP client:
</p>

<pre><code>kosmokrator --session &lt;id&gt;
kosmokrator --resume</code></pre>

<h2 id="permissions">Permissions</h2>

<p>ACP follows the same permission modes as the terminal UI:</p>

<ul>
    <li><strong>Guardian</strong> auto-approves safe operations and asks for risky ones.</li>
    <li><strong>Argus</strong> asks for every governed tool operation.</li>
    <li><strong>Prometheus</strong> auto-approves governed prompts while hard policy denies still apply.</li>
</ul>

<p>
    When the policy asks, KosmoKrator sends an ACP permission request with allow
    once, allow always, reject once, and reject always options. If the client
    cancels or fails to answer, KosmoKrator denies the operation.
</p>

<p>
    If the client sends <code>session/cancel</code> during a prompt turn,
    KosmoKrator requests cancellation from the active LLM call, cancels running
    subagents, and returns <code>stopReason: "cancelled"</code> for the turn.
    Operations that are already inside a non-interruptible local process may
    still emit final tool updates before the cancelled response is returned.
</p>

<h2 id="mcp">ACP MCP Servers</h2>

<p>
    ACP clients can pass MCP servers in <code>session/new</code>,
    <code>session/load</code>, or <code>session/resume</code>. KosmoKrator adds
    those servers as runtime overlays for the active ACP session: they are
    available to that session but are not written to project
    <code>.mcp.json</code>. Switching between loaded ACP sessions refreshes the
    runtime overlay so session-specific MCP servers do not leak into the next
    prompt turn.
</p>

<pre><code>{
  "cwd": "/path/to/project",
  "mcpServers": [
    {
      "name": "filesystem",
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "/path/to/project"],
      "env": []
    }
  ]
}</code></pre>

<p>
    Attached servers are available in Lua through the same namespace as
    configured MCP servers:
</p>

<pre><code>app.mcp.filesystem.list_allowed_directories({})</code></pre>

<h2 id="configuration">Headless Configuration</h2>

<p>
    ACP mode does not have its own credential store. Configure KosmoKrator once
    with normal headless commands:
</p>

<pre><code>printf %s "$OPENAI_API_KEY" | \
  kosmokrator providers:configure openai --api-key-stdin --json

kosmokrator settings:set agent.mode edit --global --json
kosmokrator settings:doctor --json</code></pre>

<h2 id="protocol">Protocol Notes</h2>

<ul>
    <li>Transport is newline-delimited JSON-RPC over stdio.</li>
    <li>Stdout is reserved for JSON-RPC frames.</li>
    <li>Session IDs are the normal KosmoKrator persisted session IDs.</li>
    <li>Supported agent methods include <code>initialize</code>, <code>authenticate</code>, <code>session/new</code>, <code>session/load</code>, <code>session/resume</code>, <code>session/list</code>, <code>session/prompt</code>, <code>session/cancel</code>, <code>session/close</code>, <code>session/set_mode</code>, <code>session/set_model</code>, and <code>session/set_config_option</code>.</li>
    <li>Tool calls are streamed as ACP <code>tool_call</code> and <code>tool_call_update</code> events.</li>
    <li>Assistant text is streamed as <code>agent_message_chunk</code>.</li>
    <li>Reasoning/notices use <code>agent_thought_chunk</code>.</li>
    <li>HTTP and SSE MCP transports are not advertised yet; stdio MCP servers are supported.</li>
</ul>

<?php
$docContent = ob_get_clean();
include __DIR__.'/../_docs-layout.php';
?>

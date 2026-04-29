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

<p>
    Clients that only understand ordinary ACP can ignore the extra frames.
    Rich wrappers can opt into KosmoKrator's native model by reading the
    advertised <code>kosmokratorCapabilities</code> and subscribing to
    namespaced <code>kosmokrator/*</code> notifications on the same JSON-RPC
    connection.
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
        <tr><td>KosmoKrator extension events</td><td>Supported</td><td>Native UI events are emitted as <code>kosmokrator/*</code> notifications for wrappers that need terminal-grade state.</td></tr>
        <tr><td>Direct integration calls</td><td>Supported</td><td>ACP clients can list, describe, and call headless integrations without going through an LLM turn.</td></tr>
        <tr><td>Direct MCP calls</td><td>Supported</td><td>ACP clients can list servers/tools, inspect schemas, call MCP tools, and execute MCP Lua workflows.</td></tr>
        <tr><td>Lua execution</td><td>Supported</td><td>Lua can run against the integration runtime, the MCP runtime, or the combined integration runtime.</td></tr>
    </tbody>
</table>

<h2 id="kosmokrator-extensions">KosmoKrator Extensions</h2>

<p>
    The extension layer keeps ACP compatibility while exposing the full
    KosmoKrator runtime to non-PHP applications. Extension events are JSON-RPC
    notifications whose method names start with <code>kosmokrator/</code>.
    Every event includes:
</p>

<ul>
    <li><code>protocolVersion</code> — extension protocol version.</li>
    <li><code>type</code> — event type without the namespace.</li>
    <li><code>sessionId</code> — KosmoKrator session ID.</li>
    <li><code>runId</code> — stable ID for the current prompt turn, when available.</li>
    <li><code>timestamp</code> — Unix timestamp with fractional seconds.</li>
</ul>

<pre><code>{
  "jsonrpc": "2.0",
  "method": "initialize",
  "params": {
    "protocolVersion": 1
  },
  "id": 1
}</code></pre>

<p>The response includes:</p>

<pre><code>{
  "protocolVersion": 1,
  "agentInfo": {
    "name": "KosmoKrator",
    "version": "..."
  },
  "kosmokratorCapabilities": {
    "protocolVersion": 1,
    "uiEvents": true,
    "textDeltas": true,
    "thinkingDeltas": true,
    "toolLifecycle": true,
    "permissions": true,
    "subagentTree": true,
    "subagentDashboard": true,
    "integrations": true,
    "mcp": true,
    "lua": true,
    "runtimeConfig": true,
    "sessions": true,
    "permissionModes": true
  }
}</code></pre>

<h3>Native Event Stream</h3>

<table>
    <thead><tr><th>Notification</th><th>Purpose</th></tr></thead>
    <tbody>
        <tr><td><code>kosmokrator/session_update</code></td><td>Prompt run started, completed, or cancelled.</td></tr>
        <tr><td><code>kosmokrator/phase_changed</code></td><td>Agent phase changed.</td></tr>
        <tr><td><code>kosmokrator/text_delta</code></td><td>Assistant response text delta.</td></tr>
        <tr><td><code>kosmokrator/thinking_delta</code></td><td>Reasoning or thought delta when available.</td></tr>
        <tr><td><code>kosmokrator/tool_started</code></td><td>Tool call was created.</td></tr>
        <tr><td><code>kosmokrator/tool_progress</code></td><td>Tool execution status changed.</td></tr>
        <tr><td><code>kosmokrator/tool_completed</code></td><td>Tool call completed or failed.</td></tr>
        <tr><td><code>kosmokrator/permission_requested</code></td><td>KosmoKrator is asking the ACP client to approve a governed operation.</td></tr>
        <tr><td><code>kosmokrator/permission_resolved</code></td><td>The client answered or the request was denied/cancelled.</td></tr>
        <tr><td><code>kosmokrator/subagent_spawned</code></td><td>One or more subagents were spawned.</td></tr>
        <tr><td><code>kosmokrator/subagent_running</code></td><td>Subagent batch execution started.</td></tr>
        <tr><td><code>kosmokrator/subagent_tree</code></td><td>Live subagent tree snapshot for hierarchical UIs.</td></tr>
        <tr><td><code>kosmokrator/subagent_completed</code></td><td>Subagent batch completed.</td></tr>
        <tr><td><code>kosmokrator/subagent_dashboard</code></td><td>Aggregated subagent dashboard state.</td></tr>
        <tr><td><code>kosmokrator/usage_updated</code></td><td>Token and cost counters changed.</td></tr>
        <tr><td><code>kosmokrator/runtime_changed</code></td><td>Mode, model, provider, or permission selection changed.</td></tr>
        <tr><td><code>kosmokrator/integration_event</code></td><td>Direct integration or Lua operation lifecycle.</td></tr>
        <tr><td><code>kosmokrator/mcp_event</code></td><td>Direct MCP operation lifecycle.</td></tr>
        <tr><td><code>kosmokrator/error</code></td><td>Agent/runtime error message.</td></tr>
    </tbody>
</table>

<pre><code>{
  "jsonrpc": "2.0",
  "method": "kosmokrator/tool_started",
  "params": {
    "protocolVersion": 1,
    "type": "tool_started",
    "sessionId": "01j...",
    "runId": "run_1",
    "timestamp": 1777478400.123,
    "toolCallId": "tool_0",
    "tool": "file_read",
    "args": {
      "path": "README.md"
    },
    "kind": "read",
    "title": "Read README.md"
  }
}</code></pre>

<h3>Subagent Trees</h3>

<p>
    Rich clients should build their tree from <code>subagent_spawned</code> and
    refresh it from <code>subagent_tree</code>. Tree nodes are plain JSON and
    include stable IDs, status, type, task, elapsed time, tool counts, errors,
    queue reason, last tool, message preview, retry timing, and children.
</p>

<pre><code>{
  "jsonrpc": "2.0",
  "method": "kosmokrator/subagent_tree",
  "params": {
    "type": "subagent_tree",
    "sessionId": "01j...",
    "runId": "run_1",
    "tree": [
      {
        "id": "docs-audit",
        "type": "explore",
        "task": "Audit docs",
        "status": "running",
        "elapsed": 4.2,
        "toolCalls": 3,
        "children": []
      }
    ]
  }
}</code></pre>

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

<h2 id="direct-methods">Direct Runtime Methods</h2>

<p>
    These methods are for applications that need the same headless integration,
    MCP, Lua, and runtime surfaces as the CLI. They require a loaded ACP
    session so project root, session MCP overlays, permissions, and credentials
    match the active agent.
</p>

<h3>Runtime Selection</h3>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 10,
  "method": "kosmokrator/runtime/set",
    "params": {
      "sessionId": "01j...",
      "provider": "openai",
      "mode": "edit",
      "model": "openai/gpt-5.4-mini",
      "permissionMode": "guardian"
  }
}</code></pre>

<h3>Settings And Credentials</h3>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 18,
  "method": "kosmokrator/settings/set",
  "params": {
    "sessionId": "01j...",
    "scope": "project",
    "path": "kosmokrator.tools.default_permission_mode",
    "value": "guardian"
  }
}</code></pre>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 19,
  "method": "kosmokrator/providers/configure",
  "params": {
    "sessionId": "01j...",
    "scope": "global",
    "provider": "openai",
    "model": "gpt-5.4-mini",
    "apiKey": "..."
  }
}</code></pre>

<p>
    Provider responses never echo secret values. They report whether an API key
    or base URL was configured.
</p>

<h3>Integrations</h3>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 11,
  "method": "kosmokrator/integrations/list",
  "params": {
    "sessionId": "01j...",
    "query": "plane",
    "limit": 20
  }
}</code></pre>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 12,
  "method": "kosmokrator/integrations/describe",
  "params": {
    "sessionId": "01j...",
    "function": "plane.list_issues"
  }
}</code></pre>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 13,
  "method": "kosmokrator/integrations/call",
  "params": {
    "sessionId": "01j...",
    "function": "plane.list_issues",
    "account": "work",
    "args": {
      "project_id": "..."
    },
    "force": false,
    "dryRun": false
  }
}</code></pre>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 20,
  "method": "kosmokrator/integrations/configure",
  "params": {
    "sessionId": "01j...",
    "integration": "plane",
    "account": "work",
    "scope": "project",
    "credentials": {
      "api_key": "...",
      "workspace_slug": "..."
    },
    "enabled": true,
    "permissions": {
      "read": "allow",
      "write": "ask"
    }
  }
}</code></pre>

<p>
    Integration credentials are stored in the global account credential store
    used by the headless integration CLI. The <code>scope</code> field controls
    the integration's enabled state and read/write permission policy.
</p>

<p>
    Integration calls follow configured read/write policy. Set
    <code>force: true</code> only for trusted automation that intentionally
    bypasses integration permission checks.
</p>

<h3>MCP</h3>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 14,
  "method": "kosmokrator/mcp/list_servers",
  "params": {
    "sessionId": "01j..."
  }
}</code></pre>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 15,
  "method": "kosmokrator/mcp/list_tools",
  "params": {
    "sessionId": "01j...",
    "server": "filesystem",
    "force": false
  }
}</code></pre>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 16,
  "method": "kosmokrator/mcp/call_tool",
  "params": {
    "sessionId": "01j...",
    "function": "filesystem.list_allowed_directories",
    "args": {},
    "force": false,
    "dryRun": false
  }
}</code></pre>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 21,
  "method": "kosmokrator/mcp/add_stdio_server",
  "params": {
    "sessionId": "01j...",
    "scope": "project",
    "name": "filesystem",
    "command": "npx",
    "args": ["-y", "@modelcontextprotocol/server-filesystem", "/path/to/project"],
    "env": {},
    "enabled": true,
    "trust": true,
    "permissions": {
      "read": "allow",
      "write": "ask"
    }
  }
}</code></pre>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 22,
  "method": "kosmokrator/mcp/set_secret",
  "params": {
    "sessionId": "01j...",
    "server": "github",
    "key": "env.GITHUB_TOKEN",
    "value": "..."
  }
}</code></pre>

<p>
    MCP direct calls honor project trust and read/write policy. Set
    <code>force: true</code> to bypass trust and policy for one trusted
    automation call.
</p>

<h3>Lua</h3>

<pre><code>{
  "jsonrpc": "2.0",
  "id": 17,
  "method": "kosmokrator/lua/execute",
  "params": {
    "sessionId": "01j...",
    "runtime": "integrations",
    "code": "dump(app.integrations.plane.list_issues({project_id = '...'}))",
    "options": {
      "cpuLimit": 2.0,
      "memoryLimit": 67108864
    },
    "force": false
  }
}</code></pre>

<p>
    Use <code>runtime: "integrations"</code> for the combined integration Lua
    environment, including <code>app.integrations.*</code> and available
    <code>app.mcp.*</code> namespaces. Use <code>runtime: "mcp"</code> for an
    MCP-only Lua environment.
</p>

<h2 id="protocol">Protocol Notes</h2>

<ul>
    <li>Transport is newline-delimited JSON-RPC over stdio.</li>
    <li>Stdout is reserved for JSON-RPC frames.</li>
    <li>Session IDs are the normal KosmoKrator persisted session IDs.</li>
    <li>Supported base ACP methods include <code>initialize</code>, <code>authenticate</code>, <code>session/new</code>, <code>session/load</code>, <code>session/resume</code>, <code>session/list</code>, <code>session/prompt</code>, <code>session/cancel</code>, <code>session/close</code>, <code>session/set_mode</code>, <code>session/set_model</code>, and <code>session/set_config_option</code>.</li>
    <li>Supported KosmoKrator methods include <code>kosmokrator/capabilities</code>, <code>kosmokrator/runtime/set</code>, <code>kosmokrator/settings/set</code>, <code>kosmokrator/providers/configure</code>, <code>kosmokrator/integrations/list</code>, <code>kosmokrator/integrations/describe</code>, <code>kosmokrator/integrations/configure</code>, <code>kosmokrator/integrations/call</code>, <code>kosmokrator/mcp/list_servers</code>, <code>kosmokrator/mcp/list_tools</code>, <code>kosmokrator/mcp/schema</code>, <code>kosmokrator/mcp/add_stdio_server</code>, <code>kosmokrator/mcp/set_secret</code>, <code>kosmokrator/mcp/call_tool</code>, and <code>kosmokrator/lua/execute</code>.</li>
    <li>Tool calls are streamed as ACP <code>tool_call</code> and <code>tool_call_update</code> events.</li>
    <li>Assistant text is streamed as <code>agent_message_chunk</code>.</li>
    <li>Reasoning/notices use <code>agent_thought_chunk</code>.</li>
    <li>HTTP and SSE MCP transports are not advertised yet; stdio MCP servers are supported.</li>
</ul>

<?php
$docContent = ob_get_clean();
include __DIR__.'/../_docs-layout.php';
?>

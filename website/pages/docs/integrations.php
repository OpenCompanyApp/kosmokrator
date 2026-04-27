<?php
$docTitle = 'Integrations CLI';
$docSlug = 'integrations';
ob_start();
?>

<p class="lead">
    KosmoKrator can run as a headless integration runtime. Other coding CLIs, shell scripts,
    cron jobs, CI jobs, and local automation can call the same OpenCompany integration packages
    that KosmoKrator agents use, without opening the interactive TUI and without requiring MCP.
</p>

<div class="tip">
    This is a direct local CLI surface, not an MCP server. MCP support can be layered on later,
    but today the contract is simple: run <code>kosmokrator integrations:...</code>, discover the
    available functions, call one function directly, or execute a Lua workflow against configured
    integrations.
</div>

<!-- ================================================================== -->
<h2 id="mental-model">Mental Model</h2>
<!-- ================================================================== -->

<p>
    The integrations CLI is a separate headless surface from the coding-agent REPL:
</p>

<ul>
    <li><strong>Provider</strong> &mdash; an installed integration package such as <code>plane</code>, <code>clickup</code>, or <code>github</code>.</li>
    <li><strong>Function</strong> &mdash; a callable operation exposed by a provider, named as <code>provider.function</code>.</li>
    <li><strong>Operation type</strong> &mdash; each function is either <code>read</code> or <code>write</code> for permission policy.</li>
    <li><strong>Account</strong> &mdash; an optional named credential alias for the same provider.</li>
    <li><strong>Runtime</strong> &mdash; the code path that validates arguments, checks provider state and permissions, invokes the package tool, and prints a structured result.</li>
</ul>

<p>
    The same function can be reached through three ergonomic surfaces:
</p>

<pre><code># Generic function call
kosmokrator integrations:call plane.list_projects --workspace-slug=kosmokrator --json

# Provider shortcut
kosmokrator integrations:plane list_projects --workspace-slug=kosmokrator --json

# Lua workflow endpoint
kosmokrator integrations:lua --eval 'print(docs.read("plane.list_projects"))'</code></pre>

<p>
    Use the generic form when a tool wants a stable universal command. Use the provider shortcut
    for humans. Use Lua when one workflow needs multiple integration calls, branching, loops, or
    local data shaping before returning output to another CLI.
</p>

<!-- ================================================================== -->
<h2 id="quick-start">Quick Start</h2>
<!-- ================================================================== -->

<h3 id="quick-start-discover">1. Discover Providers</h3>

<pre><code># Human-readable table
kosmokrator integrations:list

# Machine-readable provider catalog
kosmokrator integrations:list --json

# Activation, credential, account, and function counts
kosmokrator integrations:status
kosmokrator integrations:status --json</code></pre>

<p>
    Provider status has two separate ideas:
</p>

<table>
    <thead>
        <tr>
            <th>Status field</th>
            <th>Meaning</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>configured</code></td>
            <td>Required local credentials exist for the provider.</td>
        </tr>
        <tr>
            <td><code>active</code></td>
            <td>The provider is enabled and configured, so runtime calls are allowed to instantiate and execute its tools.</td>
        </tr>
        <tr>
            <td><code>accounts</code></td>
            <td>The default account plus any named credential aliases.</td>
        </tr>
        <tr>
            <td><code>functions</code></td>
            <td>The number of callable functions KosmoKrator discovered for that provider.</td>
        </tr>
    </tbody>
</table>

<h3 id="quick-start-search">2. Search for a Function</h3>

<pre><code># Search by provider, action, object, or description words
kosmokrator integrations:search "plane issue"
kosmokrator integrations:search "clickup task" --json</code></pre>

<p>
    Search returns function names, operation type, active status, and a short description.
    A coding CLI should search first when it does not already know the exact function name.
</p>

<h3 id="quick-start-read-docs">3. Read Docs and Schema</h3>

<pre><code># Provider overview
kosmokrator integrations:docs plane

# Function reference with direct CLI, generic CLI, JSON, Lua, and parameters
kosmokrator integrations:docs plane.create_issue

# JSON input schema for programmatic callers
kosmokrator integrations:schema plane.create_issue</code></pre>

<p>
    Function docs are intentionally self-contained. They include a direct CLI example, a generic
    CLI example, a JSON payload example, a Lua example, the operation type, activation status,
    and a parameter table with required fields.
</p>

<h3 id="quick-start-call">4. Call the Function</h3>

<pre><code># Generic call
kosmokrator integrations:call plane.list_projects --workspace-slug=kosmokrator --json

# Provider shortcut
kosmokrator integrations:plane list_projects --workspace-slug=kosmokrator --json

# JSON payload as an argument
kosmokrator integrations:call plane.search_issues '{"workspace_slug":"kosmokrator","search":"permission"}' --json

# JSON payload from stdin
printf '%s\n' '{"workspace_slug":"kosmokrator","search":"permission"}' \
  | kosmokrator integrations:call plane.search_issues --json</code></pre>

<p>
    Runtime calls return exit code <code>0</code> when the integration function succeeds and a non-zero
    exit code when validation, permission checks, provider activation, credentials, or the provider API
    fail.
</p>

<!-- ================================================================== -->
<h2 id="command-reference">Command Reference</h2>
<!-- ================================================================== -->

<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Purpose</th>
            <th>Machine-readable mode</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>integrations:list</code></td>
            <td>List providers, status, function counts, and example functions.</td>
            <td><code>--json</code></td>
        </tr>
        <tr>
            <td><code>integrations:status</code></td>
            <td>Show active/configured state, account aliases, and function counts.</td>
            <td><code>--json</code></td>
        </tr>
        <tr>
            <td><code>integrations:search &lt;query&gt;</code></td>
            <td>Search all provider functions by name, title, and description.</td>
            <td><code>--json</code></td>
        </tr>
        <tr>
            <td><code>integrations:docs [page]</code></td>
            <td>Read global, provider, or function docs. Pages use <code>provider</code> or <code>provider.function</code>.</td>
            <td><code>--json</code></td>
        </tr>
        <tr>
            <td><code>integrations:schema &lt;function&gt;</code></td>
            <td>Print the JSON input schema for one function.</td>
            <td>Always JSON</td>
        </tr>
        <tr>
            <td><code>integrations:examples &lt;page&gt;</code></td>
            <td>Show the same focused examples/docs for a provider or function.</td>
            <td><code>--json</code></td>
        </tr>
        <tr>
            <td><code>integrations:call &lt;function&gt;</code></td>
            <td>Call any function by full name.</td>
            <td><code>--json</code></td>
        </tr>
        <tr>
            <td><code>integrations:&lt;provider&gt; [function]</code></td>
            <td>Provider shortcut. With no function, prints provider docs. With a function, calls <code>provider.function</code>.</td>
            <td><code>--json</code></td>
        </tr>
        <tr>
            <td><code>integrations:lua</code></td>
            <td>Execute a Lua workflow against configured integrations.</td>
            <td><code>--json</code></td>
        </tr>
    </tbody>
</table>

<!-- ================================================================== -->
<h2 id="argument-passing">Argument Passing</h2>
<!-- ================================================================== -->

<p>
    Integration functions accept structured arguments. The CLI supports several input styles so
    humans and tools can choose the least awkward form.
</p>

<h3 id="argument-flags">Flags</h3>

<pre><code>kosmokrator integrations:plane create_issue \
  --workspace-slug=kosmokrator \
  --project-id=e5420c79-d899-4c4d-b372-320ae3915073 \
  --name="Investigate integration CLI docs" \
  --priority=medium \
  --json</code></pre>

<p>
    Flag names are normalized from kebab-case to snake_case, so
    <code>--workspace-slug</code> becomes <code>workspace_slug</code>. Both
    <code>--key=value</code> and <code>--key value</code> forms work.
</p>

<h3 id="argument-json">JSON Payloads</h3>

<pre><code>kosmokrator integrations:call plane.create_issue '{
  "workspace_slug": "kosmokrator",
  "project_id": "e5420c79-d899-4c4d-b372-320ae3915073",
  "name": "Investigate integration CLI docs",
  "priority": "medium"
}' --json</code></pre>

<p>
    JSON payloads must decode to an object. This is the most robust option for coding CLIs because
    it avoids shell quoting problems for arrays, nested objects, multiline descriptions, and HTML.
</p>

<h3 id="argument-stdin">Stdin Payloads</h3>

<pre><code>jq -n '{
  workspace_slug: "kosmokrator",
  search: "execute_lua"
}' | kosmokrator integrations:call plane.search_issues --json</code></pre>

<p>
    If no payload argument is present and stdin is piped, KosmoKrator reads stdin as the JSON
    payload. This is the recommended bridge for other tools that already produce JSON.
</p>

<h3 id="argument-arg">Repeated <code>--arg</code> Pairs</h3>

<pre><code>kosmokrator integrations:call plane.search_issues \
  --arg workspace_slug=kosmokrator \
  --arg search=permission \
  --json</code></pre>

<p>
    <code>--arg key=value</code> is useful when another CLI prefers repeated key-value pairs over
    shell flags. It uses the same coercion rules as normal flags.
</p>

<h3 id="argument-overrides">Merge and Override Rules</h3>

<p>
    When you provide both JSON and flags, JSON is loaded first and flags override matching keys:
</p>

<pre><code>kosmokrator integrations:call plane.search_issues \
  '{"workspace_slug":"kosmokrator","search":"old"}' \
  --search="new" \
  --json</code></pre>

<p>
    Scalar strings are coerced where obvious:
</p>

<table>
    <thead>
        <tr>
            <th>CLI value</th>
            <th>Runtime value</th>
        </tr>
    </thead>
    <tbody>
        <tr><td><code>true</code></td><td>boolean <code>true</code></td></tr>
        <tr><td><code>false</code></td><td>boolean <code>false</code></td></tr>
        <tr><td><code>null</code></td><td><code>null</code></td></tr>
        <tr><td><code>123</code></td><td>integer <code>123</code></td></tr>
        <tr><td><code>12.5</code></td><td>float <code>12.5</code></td></tr>
        <tr><td><code>medium</code></td><td>string <code>medium</code></td></tr>
    </tbody>
</table>

<p>
    For arrays and objects, prefer JSON payloads instead of trying to encode them as shell flags.
</p>

<!-- ================================================================== -->
<h2 id="json-output">JSON Output</h2>
<!-- ================================================================== -->

<p>
    Add <code>--json</code> to runtime calls when another process needs a stable result envelope:
</p>

<pre><code>{
  "function": "plane.list_workspaces",
  "success": true,
  "data": {
    "workspaces": [
      {"slug": "kosmokrator", "name": "kosmokrator", "id": null, "owner": null}
    ],
    "count": 1
  },
  "error": null,
  "meta": [],
  "duration_ms": 321.8
}</code></pre>

<table>
    <thead>
        <tr>
            <th>Field</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr><td><code>function</code></td><td>The fully qualified function that ran.</td></tr>
        <tr><td><code>success</code></td><td>Boolean success flag from validation, permissions, and provider execution.</td></tr>
        <tr><td><code>data</code></td><td>Provider-specific result data. Shape depends on the function schema and package implementation.</td></tr>
        <tr><td><code>error</code></td><td>Error message when <code>success</code> is false.</td></tr>
        <tr><td><code>meta</code></td><td>Reserved metadata for future provider/runtime details.</td></tr>
        <tr><td><code>duration_ms</code></td><td>Runtime duration for the integration function call.</td></tr>
    </tbody>
</table>

<p>
    Validation errors also use JSON when requested:
</p>

<pre><code>$ kosmokrator integrations:call plane.create_issue --json
{
  "success": false,
  "error": "Missing required parameter(s): project_id, name"
}</code></pre>

<!-- ================================================================== -->
<h2 id="lua-endpoint">Lua Endpoint</h2>
<!-- ================================================================== -->

<p>
    <code>integrations:lua</code> runs a Lua script in a sandbox with integration namespaces and
    documentation helpers. This is the ergonomic path for multi-step workflows that would be clumsy
    as a sequence of shell calls.
</p>

<pre><code># Inline Lua
kosmokrator integrations:lua --eval 'print(docs.read("plane.create_issue"))'

# Lua file
kosmokrator integrations:lua workflow.lua

# Pipe Lua on stdin
cat workflow.lua | kosmokrator integrations:lua

# Machine-readable envelope
kosmokrator integrations:lua workflow.lua --json

# Interactive integration Lua REPL
kosmokrator integrations:lua --repl</code></pre>

<h3 id="lua-doc-helpers">Lua Discovery Helpers</h3>

<p>
    The headless Lua endpoint exposes a small <code>docs</code> table:
</p>

<table>
    <thead>
        <tr>
            <th>Helper</th>
            <th>Returns</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>docs.list()</code></td>
            <td>Global integration CLI overview and provider list.</td>
        </tr>
        <tr>
            <td><code>docs.search(query)</code></td>
            <td>Matching functions as <code>provider.function - description</code> lines.</td>
        </tr>
        <tr>
            <td><code>docs.read(page)</code></td>
            <td>Provider docs for <code>provider</code> or function docs for <code>provider.function</code>.</td>
        </tr>
    </tbody>
</table>

<pre><code>print(docs.list())
print(docs.search("issue"))
print(docs.read("plane.create_issue"))</code></pre>

<h3 id="lua-namespaces">Lua Integration Namespaces</h3>

<p>
    Active providers are callable under <code>app.integrations</code>:
</p>

<pre><code>local projects = app.integrations.plane.list_projects({
  workspace_slug = "kosmokrator"
})

for _, project in ipairs(projects.projects or {}) do
  print(project.identifier .. " " .. project.name .. " " .. project.id)
end</code></pre>

<p>
    Account-aware paths are also generated when named account aliases exist:
</p>

<pre><code>app.integrations.plane.list_projects({...})          -- default account
app.integrations.plane.default.list_projects({...})  -- explicit default account
app.integrations.plane.work.list_projects({...})     -- named account alias</code></pre>

<h3 id="lua-script-example">Workflow Example</h3>

<p>
    This Lua script lists projects, finds the KosmoKrator project, searches issues, and prints
    compact JSON for a calling process:
</p>

<pre><code>local projects = app.integrations.plane.list_projects({
  workspace_slug = "kosmokrator"
})

local kosmo_project = nil
for _, project in ipairs(projects.projects or {}) do
  if project.identifier == "KOS" then
    kosmo_project = project
    break
  end
end

if not kosmo_project then
  error("KosmoKrator project not found")
end

local issues = app.integrations.plane.list_issues({
  workspace_slug = "kosmokrator",
  project_id = kosmo_project.id,
  search = "integration"
})

print(json.encode({
  project = kosmo_project.name,
  count = issues.count,
  issues = issues.issues
}))</code></pre>

<h3 id="lua-json-output">Lua JSON Result Envelope</h3>

<p>
    With <code>--json</code>, <code>integrations:lua</code> returns the Lua execution envelope rather
    than only printed output:
</p>

<pre><code>{
  "success": true,
  "output": "{\"project\":\"KosmoKrator\",\"count\":4,\"issues\":[...]}",
  "result": null,
  "error": null,
  "execution_time_ms": 42.5,
  "memory_usage": 1048576,
  "call_log": [
    {"function": "app.integrations.plane.list_projects", "tool": "plane_list_projects"},
    {"function": "app.integrations.plane.list_issues", "tool": "plane_list_issues"}
  ]
}</code></pre>

<h3 id="lua-sandbox-limits">Lua Sandbox Limits</h3>

<pre><code># Override Lua memory and CPU limits for this script
kosmokrator integrations:lua workflow.lua \
  --memory-limit=67108864 \
  --cpu-limit=10 \
  --json</code></pre>

<p>
    The Lua endpoint is for deterministic integration orchestration, not arbitrary local scripting.
    It does not provide direct filesystem, shell, or network access. Provider calls go through the
    same integration runtime and permission checks as direct CLI calls.
</p>

<h3 id="lua-vs-agent-lua">Headless Lua vs Agent Lua</h3>

<p>
    There are two Lua environments:
</p>

<table>
    <thead>
        <tr>
            <th>Surface</th>
            <th>Available namespaces</th>
            <th>Use case</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>kosmokrator integrations:lua</code></td>
            <td><code>docs.*</code>, <code>json.*</code>, <code>regex.*</code>, <code>app.integrations.*</code></td>
            <td>Headless integration workflows for scripts and external coding CLIs.</td>
        </tr>
        <tr>
            <td>Agent tool <code>execute_lua</code></td>
            <td><code>json.*</code>, <code>regex.*</code>, <code>app.integrations.*</code>, <code>app.tools.*</code></td>
            <td>Inside a KosmoKrator agent session, where Lua can compose integrations with native coding tools.</td>
        </tr>
    </tbody>
</table>

<p>
    If another coding CLI only needs integrations, prefer <code>integrations:lua</code>. If the
    KosmoKrator agent itself needs to combine integration data with file edits, shell commands, or
    subagents, use the agent-side Lua tools documented on the <a href="/docs/lua">Lua</a> page.
</p>

<!-- ================================================================== -->
<h2 id="permissions">Permissions and Activation</h2>
<!-- ================================================================== -->

<p>
    Integration calls are governed by provider activation, credentials, and read/write permissions.
    The runtime checks these before executing a provider tool.
</p>

<table>
    <thead>
        <tr>
            <th>Gate</th>
            <th>Failure message shape</th>
            <th>Fix</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Unknown function</td>
            <td><code>Unknown integration function: provider.function</code></td>
            <td>Run <code>integrations:search</code> or <code>integrations:docs provider</code>.</td>
        </tr>
        <tr>
            <td>Inactive provider</td>
            <td><code>Integration 'provider' is installed but not active...</code></td>
            <td>Enable the provider and configure credentials in <code>/settings</code>.</td>
        </tr>
        <tr>
            <td>Missing required parameters</td>
            <td><code>Missing required parameter(s): ...</code></td>
            <td>Read <code>integrations:schema provider.function</code>.</td>
        </tr>
        <tr>
            <td>Permission denied</td>
            <td><code>Integration 'provider' write access denied...</code></td>
            <td>Change provider read/write permission in <code>/settings</code>.</td>
        </tr>
        <tr>
            <td>Permission requires approval</td>
            <td><code>Integration 'provider' write requires approval...</code></td>
            <td>For non-interactive use, set that operation to <code>allow</code> or avoid the write.</td>
        </tr>
        <tr>
            <td>Provider API error</td>
            <td>Provider-specific structured failure.</td>
            <td>Inspect <code>error</code>, credentials, workspace/project IDs, and provider API availability.</td>
        </tr>
    </tbody>
</table>

<p>
    In interactive KosmoKrator sessions, <code>ask</code> permissions can route through user approval.
    In a pure headless integration CLI call, there is no interactive approval prompt. For automation,
    explicitly set the intended provider operation to <code>allow</code> and keep destructive operations
    on <code>deny</code> unless the workflow is trusted.
</p>

<!-- ================================================================== -->
<h2 id="configuration">Configuration Workflow</h2>
<!-- ================================================================== -->

<p>
    The recommended configuration flow is:
</p>

<ol>
    <li>Open <code>/settings</code> in an interactive KosmoKrator session.</li>
    <li>Go to <strong>Integrations</strong>.</li>
    <li>Select the provider.</li>
    <li>Store required credentials such as API keys, base URLs, and default workspace slugs.</li>
    <li>Enable the provider.</li>
    <li>Set read/write permission defaults for the workflows you want to run headlessly.</li>
    <li>Verify with <code>kosmokrator integrations:status --json</code>.</li>
</ol>

<p>
    Credentials are local secret settings, not command-line arguments. Avoid putting API keys in
    shell history or checked-in config files. Once stored, provider functions can be called by
    name without passing secrets to the CLI.
</p>

<p>
    For providers with multiple credential sets, use account aliases:
</p>

<pre><code># Default account
kosmokrator integrations:call plane.list_projects --json

# Named account alias
kosmokrator integrations:call plane.list_projects --account work --json
kosmokrator integrations:plane list_projects --account work --json</code></pre>

<!-- ================================================================== -->
<h2 id="coding-cli-patterns">Patterns for Other Coding CLIs</h2>
<!-- ================================================================== -->

<p>
    A coding CLI that wants to use KosmoKrator as a unified integration layer should follow a
    conservative discover-read-call loop:
</p>

<ol>
    <li>Run <code>kosmokrator integrations:status --json</code> to see active providers.</li>
    <li>Run <code>kosmokrator integrations:search "terms" --json</code> to find candidate functions.</li>
    <li>Run <code>kosmokrator integrations:schema provider.function</code> before constructing arguments.</li>
    <li>Prefer JSON payloads over flags for anything non-trivial.</li>
    <li>Always pass <code>--json</code> for machine parsing.</li>
    <li>Check both process exit code and the returned <code>success</code> field.</li>
    <li>For write operations, ask the human or require an explicit workflow policy before calling.</li>
</ol>

<h3 id="coding-cli-read">Read-Only Lookup</h3>

<pre><code>function_json=$(
  kosmokrator integrations:search "plane list issues" --json
)

schema_json=$(
  kosmokrator integrations:schema plane.list_issues
)

result_json=$(
  jq -n '{
    workspace_slug: "kosmokrator",
    project_id: "e5420c79-d899-4c4d-b372-320ae3915073",
    search: "integration"
  }' | kosmokrator integrations:call plane.list_issues --json
)</code></pre>

<h3 id="coding-cli-write">Write Operation Policy</h3>

<p>
    For write calls, an external CLI should be explicit about intent:
</p>

<pre><code># 1. Read schema
kosmokrator integrations:schema plane.create_issue

# 2. Show the planned payload to the user
jq -n '{
  workspace_slug: "kosmokrator",
  project_id: "PROJECT_UUID",
  name: "Issue title from external CLI",
  description_html: "&lt;p&gt;Created by a scripted workflow.&lt;/p&gt;"
}'

# 3. Only call after policy or user approval allows it
jq -n '{
  workspace_slug: "kosmokrator",
  project_id: "PROJECT_UUID",
  name: "Issue title from external CLI",
  description_html: "&lt;p&gt;Created by a scripted workflow.&lt;/p&gt;"
}' | kosmokrator integrations:call plane.create_issue --json</code></pre>

<h3 id="coding-cli-lua">Multi-Step Workflow Through Lua</h3>

<p>
    When another CLI needs a compact answer assembled from several integration calls, make a Lua
    file and treat KosmoKrator as a local integration endpoint:
</p>

<pre><code># workflow.lua
local docs_text = docs.read("plane.search_issues")
local issues = app.integrations.plane.search_issues({
  workspace_slug = "kosmokrator",
  search = "permission"
})
print(json.encode({
  docs_checked = string.find(docs_text, "plane.search_issues") ~= nil,
  count = issues.count,
  issues = issues.issues
}))</code></pre>

<pre><code>kosmokrator integrations:lua workflow.lua --json</code></pre>

<!-- ================================================================== -->
<h2 id="plane-example">Plane Example</h2>
<!-- ================================================================== -->

<p>
    Plane is a good example because it includes provider-level discovery, read operations, and
    write operations with required project and issue identifiers.
</p>

<pre><code># Provider docs and status
kosmokrator integrations:docs plane
kosmokrator integrations:status --json

# Current user / workspace verification
kosmokrator integrations:plane get_current_user --json
kosmokrator integrations:plane list_workspaces --json

# Project discovery
kosmokrator integrations:plane list_projects --workspace-slug=kosmokrator --json

# Project-scoped metadata
kosmokrator integrations:plane list_states \
  --workspace-slug=kosmokrator \
  --project-id=e5420c79-d899-4c4d-b372-320ae3915073 \
  --json

kosmokrator integrations:plane list_labels \
  --workspace-slug=kosmokrator \
  --project-id=e5420c79-d899-4c4d-b372-320ae3915073 \
  --json

# Issue search
kosmokrator integrations:plane list_issues \
  --workspace-slug=kosmokrator \
  --project-id=e5420c79-d899-4c4d-b372-320ae3915073 \
  --search=integration \
  --json</code></pre>

<p>
    Before creating or updating an issue, inspect the schema:
</p>

<pre><code>kosmokrator integrations:schema plane.create_issue</code></pre>

<p>
    The runtime validates required parameters before it calls Plane. For example, this fails
    locally and does not create anything:
</p>

<pre><code>kosmokrator integrations:plane create_issue --json</code></pre>

<!-- ================================================================== -->
<h2 id="best-practices">Best Practices</h2>
<!-- ================================================================== -->

<ul>
    <li>Use <code>integrations:status --json</code> as a health check before automation.</li>
    <li>Use <code>integrations:schema</code> as the source of truth for required arguments.</li>
    <li>Use JSON payloads for arrays, objects, HTML, multiline text, or generated input.</li>
    <li>Use provider shortcuts for humans and <code>integrations:call</code> for reusable scripts.</li>
    <li>Keep read operations broadly available and restrict write operations per provider.</li>
    <li>Check exit codes. Do not parse human table output in automation.</li>
    <li>Keep secrets in KosmoKrator's settings store. Do not pass API keys as shell flags.</li>
    <li>For multi-step workflows, prefer <code>integrations:lua</code> over long chains of fragile shell parsing.</li>
    <li>When integrating another coding CLI, make it discover docs and schemas at runtime instead of hardcoding stale assumptions.</li>
</ul>

<!-- ================================================================== -->
<h2 id="troubleshooting">Troubleshooting</h2>
<!-- ================================================================== -->

<table>
    <thead>
        <tr>
            <th>Symptom</th>
            <th>Likely cause</th>
            <th>Next step</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Provider appears but <code>active</code> is false.</td>
            <td>The package is installed, but it is disabled or missing required credentials.</td>
            <td>Open <code>/settings</code>, enable the provider, and configure credentials.</td>
        </tr>
        <tr>
            <td><code>Missing required parameter(s)</code>.</td>
            <td>The payload does not satisfy the function schema.</td>
            <td>Run <code>integrations:schema provider.function</code>.</td>
        </tr>
        <tr>
            <td><code>requires approval</code> in a script.</td>
            <td>The provider operation is set to <code>ask</code>, but headless calls cannot prompt.</td>
            <td>Set that read/write operation to <code>allow</code> for trusted automation.</td>
        </tr>
        <tr>
            <td>Shell flags behave oddly for arrays.</td>
            <td>Flags are scalar-oriented.</td>
            <td>Use a JSON payload or stdin JSON.</td>
        </tr>
        <tr>
            <td>Lua cannot call <code>app.tools.bash</code>.</td>
            <td><code>integrations:lua</code> only exposes integration namespaces, not native coding tools.</td>
            <td>Use agent-side <code>execute_lua</code> inside a KosmoKrator session for <code>app.tools.*</code>.</td>
        </tr>
        <tr>
            <td>A provider endpoint returns a 404 or unavailable feature.</td>
            <td>The provider API version or self-hosted deployment may not support that endpoint.</td>
            <td>Use the structured <code>error</code> field and fall back to supported read operations.</td>
        </tr>
    </tbody>
</table>

<?php
$docContent = ob_get_clean();
include __DIR__.'/../_docs-layout.php';
?>

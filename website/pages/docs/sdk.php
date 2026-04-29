<?php
$docTitle = 'Agent SDK';
$docSlug = 'sdk';
ob_start();
?>
<p class="lead">
    Embed KosmoKrator's headless agent runtime directly in PHP applications. The SDK lives in
    the main KosmoKrator package under <code>Kosmokrator\Sdk</code> and uses the same
    <code>AgentLoop::runHeadless()</code> path as <code>kosmokrator -p</code>.
</p>

<h2 id="quick-start">Quick Start</h2>

<pre><code>composer require opencompany/kosmokrator</code></pre>

<pre><code>&lt;?php

require __DIR__.'/vendor/autoload.php';

use Kosmokrator\Sdk\AgentBuilder;

$result = AgentBuilder::create()
    -&gt;forProject('/path/to/project')
    -&gt;withMode('edit')
    -&gt;withPermissionMode('guardian')
    -&gt;withMaxTurns(20)
    -&gt;withTimeout(300)
    -&gt;build()
    -&gt;collect('Fix the failing tests');

echo $result-&gt;text;</code></pre>

<p>
    The SDK is not a separate engine. It builds a normal headless KosmoKrator session, wires the
    normal tools, permissions, Lua runtime, integrations, MCP runtime, session storage, context
    management, and subagents, then returns structured PHP results.
</p>

<h2 id="cli-parity">Headless CLI Parity</h2>

<table>
    <thead><tr><th>CLI</th><th>SDK</th></tr></thead>
    <tbody>
        <tr><td><code>kosmokrator -p "task"</code></td><td><code>$agent-&gt;collect('task')</code></td></tr>
        <tr><td><code>--model</code></td><td><code>-&gt;withModel()</code></td></tr>
        <tr><td><code>--mode edit|plan|ask</code></td><td><code>-&gt;withMode()</code></td></tr>
        <tr><td><code>--permission-mode</code></td><td><code>-&gt;withPermissionMode()</code></td></tr>
        <tr><td><code>--yolo</code></td><td><code>-&gt;withYolo()</code></td></tr>
        <tr><td><code>--max-turns</code></td><td><code>-&gt;withMaxTurns()</code></td></tr>
        <tr><td><code>--timeout</code></td><td><code>-&gt;withTimeout()</code></td></tr>
        <tr><td><code>--system-prompt</code></td><td><code>-&gt;withSystemPrompt()</code></td></tr>
        <tr><td><code>--append-system-prompt</code></td><td><code>-&gt;appendSystemPrompt()</code></td></tr>
        <tr><td><code>--session</code></td><td><code>-&gt;resumeSession()</code></td></tr>
        <tr><td><code>--continue</code></td><td><code>-&gt;resumeLatestSession()</code></td></tr>
        <tr><td><code>--no-session</code></td><td><code>-&gt;withoutSessionPersistence()</code></td></tr>
        <tr><td><code>-o stream-json</code></td><td><code>-&gt;stream()</code> or <code>CallbackRenderer</code></td></tr>
    </tbody>
</table>

<h2 id="results">Results</h2>

<pre><code>$result = $agent-&gt;collect('Explain src/Agent/AgentLoop.php');

$result-&gt;text;           // final assistant response
$result-&gt;sessionId;      // persisted session id, unless disabled
$result-&gt;tokensIn;
$result-&gt;tokensOut;
$result-&gt;toolCalls;
$result-&gt;elapsedSeconds;
$result-&gt;success;
$result-&gt;exitCode;
$result-&gt;events;         // typed SDK events</code></pre>

<p>
    Long-lived workers should call <code>$agent-&gt;close()</code> when they are done with an
    agent instance. Runs clean up shell sessions and MCP clients automatically, but explicit
    close is useful after direct <code>$agent-&gt;mcp()</code> or <code>$agent-&gt;integrations()</code>
    helper usage.
</p>

<h2 id="streaming">Streaming And Callbacks</h2>

<p>
    <code>stream()</code> returns the event sequence for a run. For live delivery to a WebSocket,
    queue worker, or custom UI, use <code>CallbackRenderer</code>; callbacks are invoked while the
    underlying headless run is executing.
</p>

<pre><code>use Kosmokrator\Sdk\AgentBuilder;
use Kosmokrator\Sdk\Renderer\CallbackRenderer;

$agent = AgentBuilder::create()
    -&gt;forProject($repo)
    -&gt;withRenderer(new CallbackRenderer(
        onText: fn (string $text) =&gt; $websocket-&gt;send($text),
        onToolCall: fn (string $tool, array $args) =&gt; logger()-&gt;info('tool', compact('tool', 'args')),
        onToolResult: fn (string $tool, string $output, bool $success) =&gt; null,
    ))
    -&gt;build();

$result = $agent-&gt;collect('Refactor the auth module');</code></pre>

<h2 id="sessions">Sessions</h2>

<pre><code>$first = AgentBuilder::create()
    -&gt;forProject($repo)
    -&gt;build()
    -&gt;collect('What does the billing service do?');

$followup = AgentBuilder::create()
    -&gt;forProject($repo)
    -&gt;resumeSession($first-&gt;sessionId)
    -&gt;build()
    -&gt;collect('Now add tests for the edge cases');

$ephemeral = AgentBuilder::create()
    -&gt;forProject($repo)
    -&gt;withoutSessionPersistence()
    -&gt;build()
    -&gt;collect('Summarize this repository');</code></pre>

<h2 id="configuration">Headless Configuration</h2>

<p>
    SDK users can configure credentials and settings programmatically. These helpers write to the
    same project/global stores used by <code>providers:*</code>, <code>integrations:*</code>,
    <code>mcp:*</code>, and <code>secrets:*</code>.
</p>

<pre><code>use Kosmokrator\Sdk\Config\ProviderConfigurator;
use Kosmokrator\Sdk\Config\IntegrationConfigurator;
use Kosmokrator\Sdk\Config\McpConfigurator;

ProviderConfigurator::forProject($repo)
    -&gt;configure('openai', apiKey: getenv('OPENAI_API_KEY') ?: null, model: 'gpt-5.4-mini');

IntegrationConfigurator::forProject($repo)
    -&gt;configure('plane', account: 'work', credentials: [
        'api_key' =&gt; getenv('PLANE_API_KEY') ?: '',
        'workspace_slug' =&gt; 'acme',
    ], permissions: [
        'read' =&gt; 'allow',
        'write' =&gt; 'ask',
    ]);

McpConfigurator::forProject($repo)
    -&gt;addStdioServer('github', 'github-mcp-server',
        env: ['GITHUB_TOKEN' =&gt; '@secret:mcp.github.env.GITHUB_TOKEN'],
        permissions: ['read' =&gt; 'allow', 'write' =&gt; 'ask'],
        trust: true,
    )
    -&gt;setSecret('github', 'env.GITHUB_TOKEN', getenv('GITHUB_TOKEN') ?: '');</code></pre>

<h2 id="lua-integrations-mcp">Lua, Integrations, And MCP</h2>

<p>
    The SDK exposes the same runtime surfaces as the headless integration and MCP CLIs.
    Integration permissions and MCP trust/read/write policy are respected by default. Pass
    <code>force: true</code> only for trusted automation that should bypass those policies.
</p>

<pre><code>$agent = AgentBuilder::create()
    -&gt;forProject($repo)
    -&gt;build();

// Direct integration call
$issues = $agent-&gt;integrations()-&gt;call(
    'plane.list_issues',
    ['workspace_slug' =&gt; 'acme'],
    account: 'work',
);

// Integration Lua
$lua = $agent-&gt;integrations()-&gt;lua(
    'return app.integrations.plane.work.list_issues({workspace_slug="acme"})'
);

// Direct MCP call
$repos = $agent-&gt;mcp()-&gt;call('github.search_repositories', [
    'query' =&gt; 'kosmokrator',
]);

// Runtime-only MCP overlay, not written to .mcp.json
$agent = AgentBuilder::create()
    -&gt;forProject($repo)
    -&gt;withMcpServer('fake', command: 'php', args: ['tests/fixtures/mcp/fake_stdio_server.php'])
    -&gt;build();</code></pre>

<h2 id="permissions">Permissions</h2>

<pre><code>$agent = AgentBuilder::create()
    -&gt;forProject($repo)
    -&gt;withPermissionMode('guardian')
    -&gt;withPermissionCallback(function (string $tool, array $args): string {
        return str_starts_with($tool, 'file_read') ? 'allow' : 'deny';
    })
    -&gt;build();</code></pre>

<p>
    Valid callback results are <code>allow</code>, <code>deny</code>, and <code>always</code>.
    Boolean callbacks are also accepted: <code>true</code> maps to allow, <code>false</code> maps
    to deny.
</p>

<h2 id="anthropic-sdk-comparison">Anthropic Agent SDK Comparison</h2>

<table>
    <thead><tr><th>Feature</th><th>Anthropic Agent SDK</th><th>KosmoKrator SDK</th></tr></thead>
    <tbody>
        <tr><td>Language</td><td>Python / TypeScript</td><td>PHP / Composer</td></tr>
        <tr><td>Providers</td><td>Claude</td><td>OpenAI, Anthropic, Gemini, Ollama, OpenRouter, custom OpenAI-compatible providers, and more</td></tr>
        <tr><td>Coding tools</td><td>Read, edit, bash, grep, glob</td><td>Read, write, edit, patch, bash, shell sessions, grep, glob</td></tr>
        <tr><td>Permissions</td><td>SDK modes and callbacks</td><td>Guardian, Argus, Prometheus, callbacks, project rules</td></tr>
        <tr><td>Subagents</td><td>Supported</td><td>Multi-level subagents with dependency and group controls</td></tr>
        <tr><td>MCP</td><td>Supported</td><td>Supported, including Lua <code>app.mcp.*</code></td></tr>
        <tr><td>Lua code mode</td><td>No</td><td>Yes</td></tr>
        <tr><td>OpenCompany integrations</td><td>No</td><td>Yes, including multi-account aliases</td></tr>
    </tbody>
</table>

<h2 id="api-reference">AgentBuilder Reference</h2>

<pre><code>AgentBuilder::create(?string $basePath = null)
AgentBuilder::fromContainer(Container $container)

-&gt;forProject(string $cwd)
-&gt;fromKosmokratorConfig()
-&gt;withConfig(array $config)
-&gt;withConfigFile(string $path)
-&gt;withProvider(string $provider)
-&gt;withModel(string $model)
-&gt;withApiKey(string $key)
-&gt;withBaseUrl(string $url)
-&gt;withMode(string|AgentMode $mode)
-&gt;withPermissionMode(string|PermissionMode $mode)
-&gt;withYolo()
-&gt;withMaxTurns(int $turns)
-&gt;withTimeout(int $seconds)
-&gt;withSystemPrompt(string $prompt)
-&gt;appendSystemPrompt(string $suffix)
-&gt;resumeSession(string $sessionId)
-&gt;resumeLatestSession()
-&gt;withoutSessionPersistence()
-&gt;withOutputFormat(OutputFormat|string $format)
-&gt;withRenderer(RendererInterface $renderer)
-&gt;withPermissionCallback(Closure $callback)
-&gt;withMcpServer(...)
-&gt;build()</code></pre>

<pre><code>$agent-&gt;collect(string $prompt): AgentResult
$agent-&gt;stream(string $prompt): Generator
$agent-&gt;conversation(): AgentConversation
$agent-&gt;integrations(): IntegrationClient
$agent-&gt;mcp(): McpClient
$agent-&gt;cancel(string $reason = 'SDK run cancelled'): void
$agent-&gt;close(): void</code></pre>

<h2 id="stability">Stability</h2>

<p>
    The stable public API is <code>Kosmokrator\Sdk\*</code>,
    <code>Kosmokrator\Sdk\Event\*</code>, <code>Kosmokrator\Sdk\Renderer\*</code>, and
    <code>Kosmokrator\Sdk\Config\*</code>. Other namespaces are implementation details unless
    they are documented on this page.
</p>

<?php
$docContent = ob_get_clean();
include __DIR__.'/../_docs-layout.php';
?>

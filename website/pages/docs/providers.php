<?php
$docTitle = 'Providers';
$docSlug = 'providers';
ob_start();
?>

<p class="lead">
    KosmoKrator supports 20+ LLM providers out of the box, from major cloud APIs to local models and
    Chinese-market providers. You can also add custom OpenAI-compatible endpoints.
</p>

<!-- ================================================================ -->
<h2>Built-in Providers</h2>

<p>
    Every built-in provider is ready to use after entering credentials. The table below lists all
    providers shipped with KosmoKrator, their authentication mode, and key notes.
</p>

<table>
    <thead>
        <tr>
            <th>Provider ID</th>
            <th>Label</th>
            <th>Auth Mode</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>anthropic</code></td>
            <td>Anthropic</td>
            <td>API Key</td>
            <td>Claude family &mdash; Opus 4.5, Sonnet 4.5, Haiku 4.5</td>
        </tr>
        <tr>
            <td><code>openai</code></td>
            <td>OpenAI</td>
            <td>API Key</td>
            <td>GPT-4o, GPT-4.1 family, o-series reasoning models</td>
        </tr>
        <tr>
            <td><code>codex</code></td>
            <td>Codex (ChatGPT)</td>
            <td>OAuth</td>
            <td>Browser/device login flow, uses your ChatGPT subscription</td>
        </tr>
        <tr>
            <td><code>gemini</code></td>
            <td>Google Gemini</td>
            <td>API Key</td>
            <td>Gemini 2.5 Pro and Flash</td>
        </tr>
        <tr>
            <td><code>deepseek</code></td>
            <td>DeepSeek</td>
            <td>API Key</td>
            <td>DeepSeek V3 (chat), R1 (reasoning)</td>
        </tr>
        <tr>
            <td><code>groq</code></td>
            <td>Groq</td>
            <td>API Key</td>
            <td>Ultra-fast inference on dedicated hardware</td>
        </tr>
        <tr>
            <td><code>mistral</code></td>
            <td>Mistral</td>
            <td>API Key</td>
            <td>Mistral Large, Codestral</td>
        </tr>
        <tr>
            <td><code>xai</code></td>
            <td>xAI</td>
            <td>API Key</td>
            <td>Grok 3, with reasoning support</td>
        </tr>
        <tr>
            <td><code>openrouter</code></td>
            <td>OpenRouter</td>
            <td>API Key</td>
            <td>Meta-router for 100+ models from multiple providers</td>
        </tr>
        <tr>
            <td><code>perplexity</code></td>
            <td>Perplexity</td>
            <td>API Key</td>
            <td>Online search-augmented models</td>
        </tr>
        <tr>
            <td><code>ollama</code></td>
            <td>Ollama</td>
            <td>None</td>
            <td>Local models, no remote credentials required</td>
        </tr>
        <tr>
            <td><code>kimi</code></td>
            <td>Kimi (Moonshot)</td>
            <td>API Key</td>
            <td>Long-context Chinese/English models</td>
        </tr>
        <tr>
            <td><code>kimi-coding</code></td>
            <td>Kimi Coding</td>
            <td>API Key</td>
            <td>Code-optimized Moonshot endpoint</td>
        </tr>
        <tr>
            <td><code>mimo</code></td>
            <td>Xiaomi MiMo Token Plan</td>
            <td>API Key</td>
            <td>MiMo models via token-plan key (free tier available)</td>
        </tr>
        <tr>
            <td><code>mimo-api</code></td>
            <td>Xiaomi MiMo API</td>
            <td>API Key</td>
            <td>MiMo pay-as-you-go API</td>
        </tr>
        <tr>
            <td><code>minimax</code></td>
            <td>MiniMax</td>
            <td>API Key</td>
            <td>MiniMax models</td>
        </tr>
        <tr>
            <td><code>minimax-cn</code></td>
            <td>MiniMax CN</td>
            <td>API Key</td>
            <td>MiniMax China-region endpoint</td>
        </tr>
        <tr>
            <td><code>z</code></td>
            <td>Z.AI</td>
            <td>API Key</td>
            <td>Z.AI coding endpoint</td>
        </tr>
        <tr>
            <td><code>z-api</code></td>
            <td>Z.AI API</td>
            <td>API Key</td>
            <td>Z.AI standard API endpoint</td>
        </tr>
        <tr>
            <td><code>stepfun</code></td>
            <td>StepFun</td>
            <td>API Key</td>
            <td>Step models</td>
        </tr>
        <tr>
            <td><code>stepfun-plan</code></td>
            <td>StepFun Plan</td>
            <td>API Key</td>
            <td>Step Plan subscription endpoint with reasoning support</td>
        </tr>
    </tbody>
</table>

<!-- ================================================================ -->
<h2>Authentication Setup</h2>

<h3>First-run wizard</h3>

<p>
    The easiest way to configure credentials is the interactive setup command, which walks you through
    provider selection and API key entry:
</p>

<pre><code>kosmokrator setup</code></pre>

<p>
    The same setup can run headlessly. Use this form for containers, CI, and remote machines:
</p>

<pre><code># Configure an API-key provider and default model without exposing the key in argv
printf %s "$OPENAI_API_KEY" | \
  kosmokrator setup --provider openai --model gpt-5.4-mini \
  --api-key-stdin --global --json

# Equivalent provider-specific command
printf %s "$OPENAI_API_KEY" | \
  kosmokrator providers:configure openai --model gpt-5.4-mini \
  --api-key-stdin --global --json

# OAuth providers can use device login when available
kosmokrator providers:configure codex --device --global --json</code></pre>

<h3>Headless provider discovery</h3>

<p>
    Provider commands are designed for agents and scripts: they expose stable JSON, never print raw
    secrets, and include enough metadata to choose valid next commands.
</p>

<pre><code># List providers, auth mode, source, and configured status
kosmokrator providers:list --json

# Show one provider's status
kosmokrator providers:status openai --json

# List advertised models for a provider
kosmokrator providers:models openai --json

# Clear a stored API key
kosmokrator providers:logout openai --json</code></pre>

<p>
    Provider commands reject unknown provider IDs with <code>success: false</code> and a non-zero
    exit code. This keeps automation from mistaking an empty result for a valid provider.
</p>

<h3>API key storage</h3>

<p>
    API keys entered through the setup wizard, <code>providers:configure</code>, or
    <code>secrets:set</code> are stored in the local SQLite database at
    <code>~/.kosmokrator/data/kosmokrator.db</code>. Keys are never written to plain-text config
    files and JSON output only reports masked/configured status.
</p>

<pre><code># Set a provider key without putting it in argv history
printf %s "$OPENAI_API_KEY" | \
  kosmokrator secrets:set provider.openai.api_key --stdin --json

# Check managed secret status
kosmokrator secrets:status provider.openai.api_key --json
kosmokrator secrets:list --json
kosmokrator secrets:unset provider.openai.api_key --json</code></pre>

<h3>Environment variables</h3>

<p>
    Alternatively, you can set provider API keys via environment variables. These are read from your
    Prism PHP configuration and take effect if no key is stored in the database. Common variables:
</p>

<ul>
    <li><code>ANTHROPIC_API_KEY</code> &mdash; Anthropic</li>
    <li><code>OPENAI_API_KEY</code> &mdash; OpenAI</li>
    <li><code>DEEPSEEK_API_KEY</code> &mdash; DeepSeek</li>
    <li><code>GROQ_API_KEY</code> &mdash; Groq</li>
    <li><code>MISTRAL_API_KEY</code> &mdash; Mistral</li>
    <li><code>XAI_API_KEY</code> &mdash; xAI</li>
    <li><code>OPENROUTER_API_KEY</code> &mdash; OpenRouter</li>
    <li><code>PERPLEXITY_API_KEY</code> &mdash; Perplexity</li>
    <li><code>GEMINI_API_KEY</code> &mdash; Google Gemini</li>
    <li><code>KIMI_API_KEY</code> &mdash; Kimi / Kimi Coding</li>
    <li><code>MIMO_API_KEY</code> &mdash; MiMo (token plan)</li>
    <li><code>MIMO_PAYG_API_KEY</code> &mdash; MiMo (pay-as-you-go API)</li>
    <li><code>MINIMAX_API_KEY</code> &mdash; MiniMax</li>
    <li><code>MINIMAX_CN_API_KEY</code> &mdash; MiniMax CN (China region)</li>
    <li><code>STEPFUN_API_KEY</code> &mdash; StepFun / StepFun Plan</li>
    <li><code>ZAI_API_KEY</code> &mdash; Z.AI / Z.AI API</li>
</ul>

<div class="tip">
    Database-stored keys always take priority over environment variables. If you set a key via
    <code>/settings</code> and also have an environment variable, the stored key is used.
</div>

<h3>OAuth flow (Codex / ChatGPT)</h3>

<p>
    The <code>codex</code> provider uses a browser-based OAuth device login flow tied to your ChatGPT
    subscription. When you select Codex as your provider:
</p>

<ol>
    <li>KosmoKrator starts a local callback server on port <code>9876</code> (configurable in <code>config/kosmokrator.yaml</code>).</li>
    <li>Your browser opens to a ChatGPT authorization page.</li>
    <li>After granting access, the OAuth tokens are stored and refreshed automatically.</li>
</ol>

<p>
    Token status is shown in the settings UI &mdash; including the associated email, expiration state,
    and whether a refresh is due.
</p>

<!-- ================================================================ -->
<h2>Switching Providers</h2>

<p>
    You can change the active provider and model at any time during a session:
</p>

<ol>
    <li>Open the settings panel with the <code>/settings</code> command.</li>
    <li>Navigate to the <strong>Agent</strong> category.</li>
    <li>Change <code>default_provider</code> to the desired provider ID.</li>
    <li>Change <code>default_model</code> to a model supported by that provider.</li>
</ol>

<p>
    Both settings have <code>applies_now</code> effect &mdash; the change takes effect on the very next
    LLM call without restarting the session.
</p>

<div class="tip">
    The model selector is filtered by the currently selected provider. Change the provider first,
    then pick from its available models.
</div>

<!-- ================================================================ -->
<h2>Per-Depth Model Overrides</h2>

<p>
    KosmoKrator supports running different models at different agent depths. This lets you use a
    powerful (and more expensive) model for the main agent while routing subagents to faster or
    cheaper models.
</p>

<table>
    <thead>
        <tr>
            <th>Depth</th>
            <th>Role</th>
            <th>Settings</th>
            <th>Fallback</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>0</td>
            <td>Main agent</td>
            <td><code>default_provider</code> / <code>default_model</code></td>
            <td>&mdash;</td>
        </tr>
        <tr>
            <td>1</td>
            <td>Subagents</td>
            <td><code>subagent_provider</code> / <code>subagent_model</code></td>
            <td>Inherits from depth 0</td>
        </tr>
        <tr>
            <td>2+</td>
            <td>Sub-subagents</td>
            <td><code>subagent_depth2_provider</code> / <code>subagent_depth2_model</code></td>
            <td>Inherits from depth 1, then depth 0</td>
        </tr>
    </tbody>
</table>

<p>
    The resolution cascade works as follows: depth-2+ overrides fall back to depth-1 overrides, which
    fall back to the main agent defaults. Leave a setting empty to inherit from the parent depth.
</p>

<h3>Example: cost-optimized hierarchy</h3>

<pre><code># Main agent — most capable model
default_provider: anthropic
default_model: claude-opus-4-5-20250929

# Subagents — fast and affordable
subagent_provider: anthropic
subagent_model: claude-haiku-4-5-20251001

# Sub-subagents — inherit from subagent settings
# (leave subagent_depth2_provider and subagent_depth2_model empty)</code></pre>

<div class="tip">
    Per-depth overrides are configured under the <strong>Subagents</strong> category in <code>/settings</code>.
    Each setting applies immediately when changed.
</div>

<!-- ================================================================ -->
<h2>Custom Providers</h2>

<p>
    Any OpenAI-compatible API endpoint can be added as a custom provider. This is useful for
    self-hosted models, corporate proxies, or providers not yet included in the built-in catalog.
</p>

<h3>Adding a custom provider</h3>

<ol>
    <li>Open <code>/settings</code> and navigate to <strong>Provider Setup</strong>.</li>
    <li>Add a new provider with a unique ID.</li>
    <li>Configure the required fields:</li>
</ol>

<p>
    Or create/update the provider headlessly:
</p>

<pre><code>printf %s "$CORP_LLM_API_KEY" | kosmokrator providers:custom:upsert corp_llm \
  --label "Corporate LLM" \
  --url https://llm.corp.example/v1 \
  --model llama-3.1-70b \
  --context 128000 \
  --max-output 8192 \
  --api-key-stdin \
  --global --json

kosmokrator providers:custom:list --json
kosmokrator providers:custom:delete corp_llm --json</code></pre>

<p>
    For richer definitions, pass JSON on stdin. The payload may include <code>id</code>,
    <code>scope</code>, <code>api_key</code>, and a <code>definition</code> object with the same fields
    used in YAML (<code>label</code>, <code>driver</code>, <code>auth</code>, <code>url</code>,
    <code>default_model</code>, <code>modalities</code>, and <code>models</code>).
</p>

<table>
    <thead>
        <tr>
            <th>Field</th>
            <th>Description</th>
            <th>Example</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>label</code></td>
            <td>Human-readable name shown in the UI</td>
            <td>My Corporate LLM</td>
        </tr>
        <tr>
            <td><code>base_url</code></td>
            <td>Full URL to the chat completions endpoint</td>
            <td><code>https://llm.corp.example/v1</code></td>
        </tr>
        <tr>
            <td><code>api_key</code></td>
            <td>API key for authentication</td>
            <td><code>sk-corp-...</code></td>
        </tr>
        <tr>
            <td><code>default_model</code></td>
            <td>Model identifier to use by default</td>
            <td><code>llama-3.1-70b</code></td>
        </tr>
    </tbody>
</table>

<p>
    Custom providers use the relay system for request/response normalization, so they work with
    tool calling, streaming, and all other agent features as long as the endpoint implements the
    OpenAI chat completions format.
</p>

<!-- ================================================================ -->
<h2>Reasoning Support</h2>

<p>
    Some providers support extended thinking / reasoning modes, where the model performs chain-of-thought
    reasoning before producing its final answer. KosmoKrator controls this via the
    <code>reasoning_effort</code> setting (under the <strong>Agent</strong> category in <code>/settings</code>).
</p>

<table>
    <thead>
        <tr>
            <th>Provider</th>
            <th>Reasoning Behavior</th>
            <th>Effort Levels</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>openai</code></td>
            <td>Controllable via <code>reasoning_effort</code> for o-series models (o1, o3, o4-mini)</td>
            <td><code>low</code> / <code>medium</code> / <code>high</code></td>
        </tr>
        <tr>
            <td><code>xai</code></td>
            <td>Controllable via <code>reasoning_effort</code> for Grok 3 Think models</td>
            <td><code>low</code> / <code>medium</code> / <code>high</code></td>
        </tr>
        <tr>
            <td><code>deepseek</code></td>
            <td>Always-on reasoning for R1 models</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>stepfun</code>, <code>stepfun-plan</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>kimi</code>, <code>kimi-coding</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>groq</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>mistral</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>perplexity</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>openrouter</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>z</code>, <code>z-api</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>minimax</code>, <code>minimax-cn</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td><code>mimo</code>, <code>mimo-api</code></td>
            <td>Always-on reasoning</td>
            <td>Not configurable</td>
        </tr>
        <tr>
            <td>All others</td>
            <td>No reasoning support</td>
            <td>Setting is safely ignored</td>
        </tr>
    </tbody>
</table>

<div class="note">
    <strong>Anthropic</strong> supports extended thinking (chain-of-thought) via Prism's native driver,
    but this is not controlled through the <code>reasoning_effort</code> parameter. It is handled
    internally by the driver when supported models are used.
</div>

<p>
    The available effort levels are <code>off</code>, <code>low</code>, <code>medium</code>, and
    <code>high</code>. Setting the value to <code>off</code> disables reasoning parameters entirely,
    even for providers that support it.
</p>

<div class="tip">
    Reasoning models tend to produce longer, more thorough responses but use significantly more tokens.
    Use <code>low</code> or <code>medium</code> for routine tasks and reserve <code>high</code> for
    complex multi-step problems.
</div>

<!-- ================================================================ -->
<h2>LLM Clients</h2>

<p>
    Under the hood, KosmoKrator uses two client implementations to communicate with LLM providers.
    The correct client is selected automatically based on the provider.
</p>

<h3>AsyncLlmClient</h3>

<p>
    The primary client for most providers. Built on <strong>Amp HTTP</strong>, it sends raw HTTP requests
    to OpenAI-compatible chat completions endpoints with full async streaming support. Used for:
</p>

<ul>
    <li>OpenAI, DeepSeek, Groq, Mistral, xAI, OpenRouter, Perplexity</li>
    <li>Ollama, Kimi, Kimi Coding, MiMo, MiMo API, Z.AI, Z.AI API, StepFun, StepFun Plan</li>
    <li>All custom providers (OpenAI-compatible endpoints)</li>
</ul>

<h3>PrismService</h3>

<p>
    A synchronous client backed by the <strong>Prism PHP SDK</strong>. Used for providers that have
    native Prism drivers with specialized request/response handling:
</p>

<ul>
    <li>Anthropic (Claude) &mdash; uses Prism's native Anthropic driver with prompt caching</li>
    <li>Google Gemini &mdash; uses Prism's native Gemini driver</li>
    <li>MiniMax, MiniMax CN &mdash; uses Prism's Anthropic-compatible driver (Anthropic-format endpoints)</li>
</ul>

<h3>RetryableLlmClient</h3>

<p>
    A decorator that wraps either client, adding automatic retry logic with exponential backoff and
    jitter. Retries are triggered on:
</p>

<ul>
    <li><strong>Rate limits</strong> (HTTP 429) &mdash; honors <code>Retry-After</code> headers from the provider</li>
    <li><strong>Server errors</strong> (HTTP 5xx) &mdash; transient provider outages</li>
    <li><strong>Network failures</strong> &mdash; connection timeouts, DNS resolution errors</li>
</ul>

<p>
    The maximum number of retry attempts is configurable via the <code>max_retries</code> setting.
    A value of <code>0</code> means unlimited retries (the agent keeps trying until the provider
    responds successfully).
</p>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';

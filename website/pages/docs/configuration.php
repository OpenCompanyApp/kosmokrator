<?php
$docTitle = 'Configuration';
$docSlug = 'configuration';
ob_start();
?>

<p class="lead">
    KosmoKrator uses a layered configuration system that lets you set global defaults, override them per project, and fine-tune individual settings at runtime. This page covers every config file location, all settings categories, environment variable expansion, and full YAML examples.
</p>

<!-- ================================================================== -->
<h2 id="config-file-locations">Config File Locations</h2>

<p>
    Configuration is loaded from up to three YAML sources, merged in order. Later sources override earlier ones on a per-key basis.
</p>

<ol>
    <li>
        <strong>Bundled defaults</strong> &mdash; <code>config/kosmokrator.yaml</code> inside the application.
        Ships with sane defaults for every setting. You never need to edit this file; it exists as the baseline.
    </li>
    <li>
        <strong>User global config</strong> &mdash; <code>~/.kosmokrator/config.yaml</code>.
        Your personal overrides that apply to every project. This is the right place for API keys, preferred provider/model, theme choice, and permission mode.
    </li>
    <li>
        <strong>Project-level config</strong> &mdash; discovered by walking up from the current working directory to the filesystem root. At each directory level, KosmoKrator checks for <code>.kosmokrator/config.yaml</code> (priority) and <code>.kosmokrator.yaml</code>. The first match found wins, giving you per-project overrides. Use this to set a different model for a specific repo, enable plan mode by default, or adjust subagent concurrency for large monorepos.
    </li>
</ol>

<div class="tip">
    <p><strong>Tip:</strong> The priority chain is <strong>project &gt; global &gt; bundled</strong>. A setting in a project-level config always wins over the same key in <code>~/.kosmokrator/config.yaml</code>, which in turn overrides the bundled default.</p>
</div>

<h3 id="environment-variable-expansion">Environment Variable Expansion</h3>

<p>
    Any value in your YAML files can reference environment variables using the <code>${VAR_NAME}</code> syntax. This is especially useful for API keys and paths that differ between machines.
</p>

<pre><code>agent:
  default_provider: anthropic

# API key pulled from the shell environment
# (set in ~/.zshrc, ~/.bashrc, or your CI secrets)
anthropic:
  api_key: ${ANTHROPIC_API_KEY}

audio:
  soundfont: ${HOME}/.kosmokrator/soundfonts/FluidR3_GM.sf2</code></pre>

<p>
    If a referenced variable is not set, it is replaced with an empty string. This means unset variables are silently removed rather than causing an error at load time.
</p>

<!-- ================================================================== -->
<h2 id="runtime-settings">Runtime Settings &mdash; The /settings Command</h2>

<p>
    Beyond YAML files, KosmoKrator provides an interactive <code>/settings</code> command inside the REPL. It opens a TUI settings editor where you can browse categories, change values, and save immediately.
</p>

<ul>
    <li>Navigate categories with arrow keys or tab.</li>
    <li>Toggle, choose, or type new values inline.</li>
    <li>Changes are saved instantly to the same YAML priority chain (user global config or project-level config) via <code>SettingsManager</code>.</li>
    <li>Some settings take effect immediately (<em>applies now</em>), others on the next turn (<em>next turn</em>), and others require a new session (<em>next session</em>).</li>
</ul>

<div class="tip">
    <p><strong>Tip:</strong> Settings saved via <code>/settings</code> are written to your YAML config files, using the same priority chain as manual edits. To revert a runtime setting, clear it in <code>/settings</code> and the bundled defaults take over again.</p>
</div>

<!-- ================================================================== -->
<h2 id="settings-categories">Settings Reference</h2>

<p>
    Every configurable setting is listed below, grouped by category. The <strong>Effect</strong> column indicates when a change takes effect: <em>applies now</em> (mid-conversation), <em>next turn</em> (after your next message), or <em>next session</em> (requires restarting KosmoKrator).
</p>

<!-- ---------------------------------------- General ---------------------------------------- -->
<h3 id="general">General</h3>

<p>
    Controls the overall look and feel of the terminal interface.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>ui.renderer</code></td>
            <td>choice: <code>auto</code>, <code>tui</code>, <code>ansi</code></td>
            <td><code>auto</code></td>
            <td>Preferred renderer. <code>auto</code> selects the interactive TUI when the terminal supports it and falls back to pure ANSI otherwise. Force one or the other with <code>tui</code> or <code>ansi</code>.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>ui.theme</code></td>
            <td>choice</td>
            <td><code>default</code></td>
            <td>Terminal color theme preset. Additional themes may be added in future releases.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>ui.intro_animated</code></td>
            <td>toggle</td>
            <td><code>on</code></td>
            <td>Play the cosmic startup animation before opening the REPL. Disable with <code>off</code> or the <code>--no-animation</code> CLI flag for instant startup.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>ui.show_reasoning</code></td>
            <td>toggle</td>
            <td><code>off</code></td>
            <td>Display model reasoning/thinking content before each response. When on, the agent's chain-of-thought output is shown inline before the final answer.</td>
            <td>applies now</td>
        </tr>
    </tbody>
</table>

<!-- ---------------------------------------- Models ---------------------------------------- -->
<h3 id="models">Models</h3>

<p>
    Select the LLM provider and model used for the main agent. The available choices are populated dynamically from your configured providers.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>agent.default_provider</code></td>
            <td>dynamic choice</td>
            <td><em>(first configured provider)</em></td>
            <td>The LLM provider used when a new session starts. Providers are registered in the provider configuration (Anthropic, OpenAI, Ollama, OpenRouter, and dozens more).</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.default_model</code></td>
            <td>dynamic choice</td>
            <td><em>(provider default)</em></td>
            <td>The model to use with the selected provider. The list of available models depends on the chosen provider.</td>
            <td>next session</td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p><strong>Tip:</strong> You can switch providers and models mid-session with <code>/models</code> without restarting. The command shows recent and likely choices first; full inventory remains in <code>/settings</code>.</p>
</div>

<!-- ---------------------------------------- Agent ---------------------------------------- -->
<h3 id="agent">Agent</h3>

<p>
    Controls the core agent behavior: operating mode, sampling parameters, and retry policy.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>agent.mode</code></td>
            <td>choice: <code>edit</code>, <code>plan</code>, <code>ask</code></td>
            <td><code>edit</code></td>
            <td>Starting mode for interactive sessions. <strong>edit</strong> grants full read-write tool access. <strong>plan</strong> allows reading and planning but no file modifications. <strong>ask</strong> is a pure chat mode with no tool use.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>agent.temperature</code></td>
            <td>number (0&ndash;2)</td>
            <td><code>0.0</code></td>
            <td>Sampling temperature for the LLM. Lower values produce more deterministic output; higher values increase creativity and variation.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>agent.max_tokens</code></td>
            <td>number</td>
            <td><em>(model default)</em></td>
            <td>Override the maximum number of output tokens per LLM response. Leave unset to use the model's built-in limit.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>agent.max_retries</code></td>
            <td>number</td>
            <td><code>0</code></td>
            <td>Retry limit for transient provider failures (HTTP 429, 500, 503, network timeouts). Set <code>0</code> for unlimited retries with exponential backoff.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>agent.reasoning_effort</code></td>
            <td>choice: <code>off</code>, <code>low</code>, <code>medium</code>, <code>high</code></td>
            <td><code>high</code></td>
            <td>Controls extended thinking / chain-of-thought reasoning for models that support it (e.g., Claude with extended thinking, OpenAI o-series). <code>off</code> disables reasoning parameters entirely.</td>
            <td>applies now</td>
        </tr>
    </tbody>
</table>

<!-- ---------------------------------------- Subagents ---------------------------------------- -->
<h3 id="subagents">Subagents</h3>

<p>
    Subagents are child agent processes spawned to handle parallel subtasks. You can configure separate providers and models at each depth level, plus concurrency and reliability controls.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>agent.subagent_provider</code></td>
            <td>dynamic choice</td>
            <td><em>(inherits main provider)</em></td>
            <td>Provider for depth-1 subagents. Leave empty to use the main agent's provider.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.subagent_model</code></td>
            <td>dynamic choice</td>
            <td><em>(inherits main model)</em></td>
            <td>Model for depth-1 subagents. Leave empty to use the main agent's model.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.subagent_depth2_provider</code></td>
            <td>dynamic choice</td>
            <td><em>(inherits subagent provider)</em></td>
            <td>Provider for depth-2 and deeper subagents. Falls back to the subagent provider, then the main agent provider.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.subagent_depth2_model</code></td>
            <td>dynamic choice</td>
            <td><em>(inherits subagent model)</em></td>
            <td>Model for depth-2 and deeper subagents. Falls back to the subagent model, then the main agent model.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.subagent_max_depth</code></td>
            <td>number</td>
            <td><code>3</code></td>
            <td>Maximum nesting depth for spawned agent trees. A value of 3 means the main agent (depth 0) can spawn subagents (depth 1), which can spawn sub-subagents (depth 2), which can spawn depth-3 agents.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.subagent_concurrency</code></td>
            <td>number</td>
            <td><code>10</code></td>
            <td>Maximum number of subagents running concurrently across all depths. Set <code>0</code> for unlimited.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.subagent_max_retries</code></td>
            <td>number</td>
            <td><code>2</code></td>
            <td>How many times a failed subagent is retried before reporting the failure to the parent. Set <code>0</code> for no retries.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.subagent_idle_watchdog_seconds</code></td>
            <td>number</td>
            <td><code>900</code></td>
            <td>Cancel a subagent if it stops making progress updates for this many seconds. Set <code>0</code> to disable the watchdog.</td>
            <td>next session</td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p><strong>Tip:</strong> A common pattern is to use a powerful model (e.g., Claude Opus) for the main agent, a fast model (e.g., Claude Sonnet) for depth-1 subagents, and an even cheaper model (e.g., Claude Haiku) for depth-2+. This optimizes cost while keeping the coordinator sharp.</p>
</div>

<!-- ---------------------------------------- Permissions ---------------------------------------- -->
<h3 id="permissions">Permissions</h3>

<p>
    The permission system controls which tool calls require explicit user approval.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>tools.default_permission_mode</code></td>
            <td>choice: <code>guardian</code>, <code>argus</code>, <code>prometheus</code></td>
            <td><code>guardian</code></td>
            <td>
                Default permission policy for tool calls.
                <ul>
                    <li><strong>Guardian</strong> &mdash; asks approval for all write operations and untrusted commands. Safe read commands (git, ls, cat, etc.) are auto-approved.</li>
                    <li><strong>Argus</strong> &mdash; asks approval for every tool call, including reads. Maximum oversight.</li>
                    <li><strong>Prometheus</strong> &mdash; auto-approves everything. Full autonomy, no prompts. Use with caution.</li>
                </ul>
            </td>
            <td>applies now</td>
        </tr>
    </tbody>
</table>

<!-- ---------------------------------------- Integrations ---------------------------------------- -->
<h3 id="integrations">Integrations</h3>

<p>
    KosmoKrator discovers installed OpenCompany integration packages from your <code>composer.lock</code>. Current packages may use either the newer <code>opencompanyapp/integration-*</code> prefix or the legacy <code>opencompanyapp/ai-tool-*</code> prefix. Each integration exposes Lua-callable API functions via <code>app.integrations.{name}</code>. Integration settings are managed at runtime through <code>/settings</code> under the <strong>Integrations</strong> category.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>integrations.permissions_default</code></td>
            <td>choice: <code>allow</code>, <code>ask</code>, <code>deny</code></td>
            <td><code>ask</code></td>
            <td>
                Default permission policy for integration API operations not explicitly configured.
                <ul>
                    <li><strong>allow</strong> &mdash; auto-approve the operation.</li>
                    <li><strong>ask</strong> &mdash; prompt the user before executing.</li>
                    <li><strong>deny</strong> &mdash; block the operation outright.</li>
                </ul>
            </td>
            <td>applies now</td>
        </tr>
    </tbody>
</table>

<!-- ---------------------------------------- Gateway ---------------------------------------- -->
<h3 id="gateway">Gateway</h3>

<p>
    The Gateway category controls external chat surfaces. Today the shipped
    gateway is Telegram, started with <code>kosmokrator gateway:telegram</code>.
    Gateway state lives partly in normal config and partly in the local secret
    store, so you usually manage it through <code>/settings</code> rather than
    by editing YAML directly.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>gateway.telegram.enabled</code></td>
            <td>toggle</td>
            <td><code>off</code></td>
            <td>Enable or disable the Telegram gateway runtime.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>gateway.telegram.session_mode</code></td>
            <td>choice: <code>chat</code>, <code>chat_user</code>, <code>thread</code>, <code>thread_user</code></td>
            <td><code>thread</code></td>
            <td>
                Controls how Telegram chats map to Kosmo sessions.
                <ul>
                    <li><code>chat</code> &mdash; one session per chat</li>
                    <li><code>chat_user</code> &mdash; one session per chat/user pair</li>
                    <li><code>thread</code> &mdash; one session per Telegram thread/topic</li>
                    <li><code>thread_user</code> &mdash; one session per thread/user pair</li>
                </ul>
            </td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>gateway.telegram.allowed_users</code></td>
            <td>list</td>
            <td><em>empty</em></td>
            <td>Optional Telegram user allowlist. Accepts numeric user IDs and usernames. Empty means any user is allowed.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>gateway.telegram.allowed_chats</code></td>
            <td>list</td>
            <td><em>empty</em></td>
            <td>Optional Telegram chat allowlist. Empty means all chats are allowed.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>gateway.telegram.require_mention</code></td>
            <td>toggle</td>
            <td><code>on</code></td>
            <td>Require a mention or direct reply in group chats before the bot responds. Direct messages are unaffected.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>gateway.telegram.free_response_chats</code></td>
            <td>list</td>
            <td><em>empty</em></td>
            <td>Chats that are allowed to receive normal free-form responses without mention gating.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>gateway.telegram.poll_timeout_seconds</code></td>
            <td>number</td>
            <td><code>20</code></td>
            <td>Long-poll timeout for the Telegram bot loop.</td>
            <td>next session</td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p><strong>Telegram token:</strong> The bot token is managed through <code>/settings → Gateway</code> and stored outside normal YAML by default. You can also provide it via <code>KOSMOKRATOR_TELEGRAM_BOT_TOKEN</code>.</p>
</div>

<!-- ---------------------------------------- Codex ---------------------------------------- -->
<h3 id="codex">Codex</h3>

<p>
    The Codex section configures the built-in OAuth server used for authenticating with cloud providers and services.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>codex.oauth_port</code></td>
            <td>number</td>
            <td><code>9876</code></td>
            <td>The local port used by the OAuth callback server when authenticating with external providers. Change this if the default port conflicts with another service.</td>
            <td>next session</td>
        </tr>
    </tbody>
</table>

<!-- ---------------------------------------- Context & Memory ---------------------------------------- -->
<h3 id="context-memory">Context &amp; Memory</h3>

<p>
    Fine-tune how KosmoKrator manages the conversation context window, compaction, pruning, and the persistent memory system.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>context.memories</code></td>
            <td>toggle</td>
            <td><code>on</code></td>
            <td>Enable the persistent memory system. When on, the agent can recall facts from previous sessions and save new memories for future use.</td>
            <td>next turn</td>
        </tr>
        <tr>
            <td><code>context.auto_compact</code></td>
            <td>toggle</td>
            <td><code>on</code></td>
            <td>Automatically compact the conversation context before it hits the model's token limit. When the remaining input budget drops below the auto-compact buffer, the agent summarizes older messages to free space.</td>
            <td>next turn</td>
        </tr>
        <tr>
            <td><code>context.compact_threshold</code></td>
            <td>number</td>
            <td><code>60</code></td>
            <td>Legacy threshold percentage for the compaction fallback mechanism. Lower values trigger compaction sooner.</td>
            <td>next turn</td>
        </tr>
        <tr>
            <td><code>context.reserve_output_tokens</code></td>
            <td>number</td>
            <td><code>16000</code></td>
            <td>Headroom reserved for the assistant's response. This is subtracted from the model's context window to determine how many tokens are available for input (system prompt + conversation history).</td>
            <td>next turn</td>
        </tr>
        <tr>
            <td><code>context.warning_buffer_tokens</code></td>
            <td>number</td>
            <td><code>24000</code></td>
            <td>When the remaining input budget drops below this threshold, KosmoKrator displays context pressure warnings in the status bar.</td>
            <td>next turn</td>
        </tr>
        <tr>
            <td><code>context.auto_compact_buffer_tokens</code></td>
            <td>number</td>
            <td><code>12000</code></td>
            <td>When the remaining input budget drops below this threshold, auto-compaction triggers (if enabled).</td>
            <td>next turn</td>
        </tr>
        <tr>
            <td><code>context.blocking_buffer_tokens</code></td>
            <td>number</td>
            <td><code>3000</code></td>
            <td>Hard-stop buffer to prevent overrunning the model's context window. If remaining budget hits this, the agent force-compacts before the next LLM call.</td>
            <td>next turn</td>
        </tr>
        <tr>
            <td><code>context.prune_protect</code></td>
            <td>number</td>
            <td><code>40000</code></td>
            <td>Number of recent tool-result tokens protected from micro-pruning. Ensures the agent retains full detail for the most recent tool outputs.</td>
            <td>next turn</td>
        </tr>
        <tr>
            <td><code>context.prune_min_savings</code></td>
            <td>number</td>
            <td><code>20000</code></td>
            <td>Minimum token savings required for a prune pass to be accepted. Prevents thrashing from tiny, repeated prune operations.</td>
            <td>next turn</td>
        </tr>
    </tbody>
</table>

<!-- ---------------------------------------- Audio ---------------------------------------- -->
<h3 id="audio">Audio</h3>

<p>
    KosmoKrator can compose and play a short musical piece after each agent response, reflecting the nature of the work performed. This requires a SoundFont file for MIDI playback.
</p>

<table>
    <thead>
        <tr>
            <th>Setting</th>
            <th>Type</th>
            <th>Default</th>
            <th>Description</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>audio.completion_sound</code></td>
            <td>toggle</td>
            <td><code>off</code></td>
            <td>Compose and play a short musical piece after each agent response. The composition reflects the nature of the completed work.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>audio.soundfont</code></td>
            <td>text (file path)</td>
            <td><code>~/.kosmokrator/soundfonts/FluidR3_GM.sf2</code></td>
            <td>Path to a SoundFont (.sf2) file for MIDI playback. FluidR3 GM is recommended for a good general-purpose sound set.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>audio.llm_timeout</code></td>
            <td>number (seconds)</td>
            <td><code>60</code></td>
            <td>Maximum seconds to wait for the AI to compose a musical piece before falling back to a built-in omen sound.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>audio.max_duration</code></td>
            <td>number (seconds)</td>
            <td><code>8</code></td>
            <td>Maximum length of the composed musical piece in seconds.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>audio.max_retries</code></td>
            <td>number</td>
            <td><code>1</code></td>
            <td>Number of times to retry if the LLM fails to generate a valid music composition script.</td>
            <td>applies now</td>
        </tr>
        <tr>
            <td><code>agent.audio_provider</code></td>
            <td>dynamic choice</td>
            <td><em>(inherits main provider)</em></td>
            <td>Provider for the audio composition LLM call. Leave empty to use the main agent's provider.</td>
            <td>next session</td>
        </tr>
        <tr>
            <td><code>agent.audio_model</code></td>
            <td>dynamic choice</td>
            <td><em>(inherits main model)</em></td>
            <td>Model for the audio composition LLM call. Leave empty to use the main agent's model. A cheap, fast model works well here.</td>
            <td>next session</td>
        </tr>
    </tbody>
</table>

<!-- ================================================================== -->
<h2 id="yaml-examples">YAML Configuration Examples</h2>

<p>
    Below are complete <code>~/.kosmokrator/config.yaml</code> examples for common setups. Copy one as your starting point and adjust to taste.
</p>

<!-- ---- Anthropic ---- -->
<h3 id="example-anthropic">Anthropic (Claude)</h3>

<pre><code># ~/.kosmokrator/config.yaml — Anthropic Claude setup
agent:
  default_provider: anthropic
  default_model: claude-sonnet-4-20250514
  temperature: 0.0
  max_retries: 3
  reasoning_effort: medium
  mode: edit

  # Use a cheaper model for subagents
  subagent_provider: anthropic
  subagent_model: claude-haiku-4-20250414
  subagent_max_depth: 3
  subagent_concurrency: 8

ui:
  renderer: auto
  intro_animated: true
  show_reasoning: false

tools:
  default_permission_mode: guardian

context:
  auto_compact: true
  memories: true</code></pre>

<div class="tip">
    <p><strong>Tip:</strong> Set <code>ANTHROPIC_API_KEY</code> in your shell environment rather than putting it in the YAML file. KosmoKrator reads it automatically.</p>
</div>

<!-- ---- OpenAI ---- -->
<h3 id="example-openai">OpenAI</h3>

<pre><code># ~/.kosmokrator/config.yaml — OpenAI setup
agent:
  default_provider: openai
  default_model: gpt-4.1
  temperature: 0.0
  max_retries: 3
  reasoning_effort: off

  subagent_provider: openai
  subagent_model: gpt-4.1-mini

ui:
  renderer: auto
  intro_animated: true

tools:
  default_permission_mode: guardian</code></pre>

<!-- ---- Ollama ---- -->
<h3 id="example-ollama">Local Ollama</h3>

<pre><code># ~/.kosmokrator/config.yaml — Local Ollama setup
agent:
  default_provider: ollama
  default_model: qwen3:32b
  temperature: 0.0
  max_retries: 0          # unlimited retries (local server may restart)

  # Smaller model for subagents to stay within local resources
  subagent_provider: ollama
  subagent_model: qwen3:8b
  subagent_max_depth: 2
  subagent_concurrency: 3

ui:
  renderer: auto
  intro_animated: false    # skip animation for quick iteration

tools:
  default_permission_mode: prometheus   # auto-approve for local-only use</code></pre>

<div class="tip">
    <p><strong>Tip:</strong> With local models, keep subagent concurrency low to avoid saturating your GPU. A concurrency of 2&ndash;4 is typically the sweet spot for consumer hardware.</p>
</div>

<!-- ---- Custom provider ---- -->
<h3 id="example-custom">Custom / OpenAI-Compatible Provider</h3>

<pre><code># ~/.kosmokrator/config.yaml — Custom endpoint
agent:
  default_provider: custom
  default_model: my-fine-tuned-model
  temperature: 0.2
  max_retries: 5

  # Main agent uses the custom provider, subagents use OpenAI
  subagent_provider: openai
  subagent_model: gpt-4.1-mini

ui:
  renderer: ansi    # force ANSI for SSH sessions

tools:
  default_permission_mode: guardian</code></pre>

<p>
    Custom providers that expose an OpenAI-compatible API can be configured by registering them in the provider setup. Use <code>/settings</code> under the <strong>Provider Setup</strong> category to add the base URL and API key.
</p>

<!-- ---- Multi-provider mix ---- -->
<h3 id="example-multi">Multi-Provider Mix</h3>

<pre><code># ~/.kosmokrator/config.yaml — Use different providers at each depth
agent:
  default_provider: anthropic
  default_model: claude-sonnet-4-20250514
  temperature: 0.0
  reasoning_effort: high

  # Depth-1 subagents: fast Anthropic model
  subagent_provider: anthropic
  subagent_model: claude-haiku-4-20250414

  # Depth-2+ subagents: cheap OpenAI for bulk exploration
  subagent_depth2_provider: openai
  subagent_depth2_model: gpt-4.1-mini

  subagent_max_depth: 3
  subagent_concurrency: 12

  # Audio composition uses a small model to save cost
  audio_provider: openai
  audio_model: gpt-4.1-nano

audio:
  completion_sound: true
  soundfont: ${HOME}/.kosmokrator/soundfonts/FluidR3_GM.sf2
  max_duration: 6

tools:
  default_permission_mode: guardian</code></pre>

<!-- ================================================================== -->
<h2 id="environment-variables">Environment Variables</h2>

<p>
    KosmoKrator reads API keys and other secrets from environment variables. Set these in your shell profile (<code>~/.zshrc</code>, <code>~/.bashrc</code>, etc.) or in your CI/CD environment.
</p>

<table>
    <thead>
        <tr>
            <th>Variable</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>ANTHROPIC_API_KEY</code></td>
            <td>API key for Anthropic (Claude models)</td>
        </tr>
        <tr>
            <td><code>OPENAI_API_KEY</code></td>
            <td>API key for OpenAI (GPT models)</td>
        </tr>
        <tr>
            <td><code>GOOGLE_API_KEY</code></td>
            <td>API key for Google (Gemini models)</td>
        </tr>
        <tr>
            <td><code>MISTRAL_API_KEY</code></td>
            <td>API key for Mistral</td>
        </tr>
        <tr>
            <td><code>GROQ_API_KEY</code></td>
            <td>API key for Groq</td>
        </tr>
        <tr>
            <td><code>XAI_API_KEY</code></td>
            <td>API key for xAI (Grok models)</td>
        </tr>
        <tr>
            <td><code>DEEPSEEK_API_KEY</code></td>
            <td>API key for DeepSeek</td>
        </tr>
        <tr>
            <td><code>OPENROUTER_API_KEY</code></td>
            <td>API key for OpenRouter (multi-provider gateway)</td>
        </tr>
        <tr>
            <td><code>TOGETHER_API_KEY</code></td>
            <td>API key for Together AI</td>
        </tr>
        <tr>
            <td><code>FIREWORKS_API_KEY</code></td>
            <td>API key for Fireworks AI</td>
        </tr>
    </tbody>
</table>

<h3 id="env-var-syntax">Using Variables in YAML</h3>

<p>
    Reference any environment variable in your YAML config with the <code>${VAR_NAME}</code> syntax. This works for any value, not just API keys:
</p>

<pre><code># Paths and keys from environment
audio:
  soundfont: ${HOME}/.kosmokrator/soundfonts/FluidR3_GM.sf2

session:
  history_dir: ${KOSMOKRATOR_DATA_DIR}/sessions</code></pre>

<!-- ================================================================== -->
<h2 id="yaml-structure">Full YAML Structure Reference</h2>

<p>
    For reference, here is the complete top-level structure of <code>kosmokrator.yaml</code> with all sections:
</p>

<pre><code>agent:
  default_provider: ...       # LLM provider name
  default_model: ...          # Model identifier
  temperature: 0.0            # Sampling temperature
  max_retries: 0              # 0 = unlimited
  mode: edit                  # edit | plan | ask
  reasoning_effort: high      # off | low | medium | high
  subagent_provider: ...      # Depth-1 subagent provider
  subagent_model: ...         # Depth-1 subagent model
  subagent_depth2_provider: ... # Depth-2+ provider
  subagent_depth2_model: ...  # Depth-2+ model
  subagent_max_depth: 3
  subagent_concurrency: 10
  subagent_max_retries: 2
  subagent_idle_watchdog_seconds: 900
  audio_provider: ...         # Audio composition provider
  audio_model: ...            # Audio composition model
  system_prompt: |
    ...                       # Custom system prompt (advanced)

ui:
  renderer: auto              # auto | tui | ansi
  intro_animated: true
  theme: default
  show_reasoning: false       # Show model thinking before responses

codex:
  oauth_port: 9876            # OAuth callback server port

integrations:
  permissions_default: ask    # allow | ask | deny (default for integration ops)

tools:
  approval_required:          # Tools requiring explicit approval
    - file_write
    - file_edit
    - apply_patch
    - bash
    - shell_start
    - shell_write
    - execute_lua
  denied_tools: []            # Tools always blocked (overrides all modes)
  safe_tools:                 # Tools auto-approved in Guardian mode
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
  default_permission_mode: guardian
  bash:
    timeout: 120              # Bash command timeout (seconds)
    blocked_commands:
      - "rm -rf /"
  shell:
    wait_ms: 100              # Shell session poll interval
    idle_ttl: 300             # Shell session idle timeout
  blocked_paths:              # Glob patterns for blocked file paths
    - "*.env"
    - ".git/*"
    - "*.pem"
    - "*id_rsa*"
    - "*id_ed25519*"
    - "*.key"
  allowed_paths:              # Paths exempt from blocked_paths
    - "~/.kosmokrator"
    - "/tmp"
  guardian_safe_commands:      # Commands auto-approved in Guardian mode
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

context:
  max_output_lines: 2000
  max_output_bytes: 50000
  reserve_output_tokens: 16000
  warning_buffer_tokens: 24000
  auto_compact_buffer_tokens: 12000
  blocking_buffer_tokens: 3000
  prune_protect: 40000
  prune_min_savings: 20000
  compact_threshold: 60
  memory_warning_mb: 50

session:
  auto_save: true
  history_dir: ~/.kosmokrator/sessions

audio:
  completion_sound: false
  soundfont: "~/.kosmokrator/soundfonts/FluidR3_GM.sf2"
  llm_timeout: 60
  max_duration: 8
  max_retries: 1</code></pre>

<div class="tip">
    <p><strong>Tip:</strong> You only need to include the keys you want to override. KosmoKrator merges your partial config on top of the bundled defaults, so a three-line config file is perfectly valid.</p>
</div>

<?php
$docContent = ob_get_clean();
include __DIR__.'/../_docs-layout.php';

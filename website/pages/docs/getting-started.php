<?php
$docTitle = 'Getting Started';
$docSlug = 'getting-started';
ob_start();
?>
<p class="lead">
    Five minutes from zero to your first AI-assisted code change. This guide
    covers the essentials: install, configure, and start using KosmoKrator in
    your project. For detailed installation options, see the
    <a href="/docs/installation">Installation</a> page.
</p>


<!-- ================================================================== -->
<h2 id="prerequisites">Prerequisites</h2>
<!-- ================================================================== -->

<p>
    You need a terminal and an API key from at least one LLM provider
    (Anthropic, OpenAI, Google, Mistral, or any of the 40+ supported providers).
    That's it.
</p>

<p>
    If you use the <strong>static binary</strong>, no PHP installation is
    required &mdash; the runtime is bundled. If you prefer the PHAR or source
    install, check your PHP version:
</p>

<pre><code>php -v</code></pre>

<p>
    You need <strong>PHP 8.4 or newer</strong> for the PHAR and source methods.
    The static binary has no PHP requirement at all.
</p>


<!-- ================================================================== -->
<h2 id="install">Install in 30 Seconds</h2>
<!-- ================================================================== -->

<p>
    Pick your preferred method below. For detailed instructions, platform-specific
    notes, and troubleshooting, see <a href="/docs/installation">Installation</a>.
</p>

<div class="install-tabs">
    <button class="install-tab active" onclick="switchInstallTab(event, 'binary')">Static Binary</button>
    <button class="install-tab" onclick="switchInstallTab(event, 'phar')">PHAR</button>
    <button class="install-tab" onclick="switchInstallTab(event, 'source')">From Source</button>
</div>

<div id="install-binary" class="install-panel active">
    <p>
        Auto-detects your OS and architecture. No PHP required.
    </p>
    <pre><code>curl -fsSL https://raw.githubusercontent.com/OpenCompanyApp/kosmokrator/main/install.sh | bash</code></pre>
    <p class="muted">
        Or download manually &mdash; see
        <a href="/docs/installation#static-binary">Installation</a> for all
        platform binaries.
    </p>
</div>

<div id="install-phar" class="install-panel">
    <p>
        Requires PHP 8.4+. Download the PHAR and place it on your
        <code>$PATH</code>.
    </p>
    <pre><code>sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator.phar \
  -o /usr/local/bin/kosmokrator \
  && sudo chmod +x /usr/local/bin/kosmokrator</code></pre>
</div>

<div id="install-source" class="install-panel">
    <p>
        Requires PHP 8.4+ and Composer. Clone the repository and install
        dependencies.
    </p>
    <pre><code>git clone https://github.com/OpenCompanyApp/kosmokrator.git
cd kosmokrator
composer install</code></pre>
    <p>Then run with <code>bin/kosmokrator</code>.</p>
</div>

<div class="tip">
    <p>
        <strong>Verify:</strong> Run <code>kosmokrator --version</code> to
        confirm the install succeeded.
    </p>
</div>


<!-- ================================================================== -->
<h2 id="first-run">First Run</h2>
<!-- ================================================================== -->

<p>
    Launch the setup wizard to configure your LLM provider:
</p>

<pre><code>kosmokrator setup</code></pre>

<p>The wizard walks you through three steps:</p>

<ol>
    <li>
        <strong>Pick a provider</strong> &mdash; Choose from 40+ supported
        providers (Anthropic, OpenAI, Google, Mistral, local endpoints, and more).
    </li>
    <li>
        <strong>Authenticate</strong> &mdash; Most providers accept an API key
        entered directly. OAuth-based providers (like Codex) use a browser or
        device flow instead &mdash; the wizard opens a link for you to authorize.
        Credentials are stored locally at <code>~/.kosmokrator/config.yaml</code>
        and never sent anywhere except the provider's own API.
    </li>
    <li>
        <strong>Pick a model</strong> &mdash; Select a default model. You can
        override this per-session or change it in the config later.
    </li>
</ol>

<p>
    Once setup is complete, start KosmoKrator in your project directory:
</p>

<pre><code>cd your-project
kosmokrator</code></pre>

<div class="tip">
    <p>
        <strong>Reconfigure anytime:</strong> Run <code>kosmokrator setup</code>
        again or use the <code>/settings</code> command inside a session to
        update providers and models on the fly.
    </p>
</div>


<!-- ================================================================== -->
<h2 id="first-task">Your First Task</h2>
<!-- ================================================================== -->

<p>
    Once KosmoKrator is running, you'll see the interactive prompt. Here are
    three ways to put it to work immediately:
</p>

<h3>Ask a question</h3>

<p>
    Type a natural-language question about your codebase. KosmoKrator will
    read relevant files and explain what's going on.
</p>

<pre><code>You &gt; Explain how the routing works in this project</code></pre>

<p>
    The agent searches your project, reads the relevant files, and responds
    with a clear explanation &mdash; no file edits, just understanding.
</p>

<h3>Make a change</h3>

<p>
    Describe what you want done in plain language. The agent plans the change,
    reads the files it needs, and writes the code.
</p>

<pre><code>You &gt; Add a /health endpoint to the API controller</code></pre>

<p>
    Depending on your <a href="/docs/permissions">permission mode</a>,
    file writes may ask for your approval before they land. In
    <strong>Prometheus</strong> mode they go through automatically; in
    <strong>Guardian</strong> mode you review each one.
</p>

<h3>Use a power command</h3>

<p>
    Power commands (prefixed with <code>:</code>) activate specialized agent
    behaviors with a single instruction.
</p>

<pre><code>You &gt; :review src/Controller/UserController.php</code></pre>

<p>
    This launches a focused code review of the specified file. The agent reads
    the file, analyzes it for bugs, style issues, and improvements, and
    presents its findings.
</p>

<div class="tip">
    <p>
        <strong>More power commands:</strong> Try <code>:release</code> to
        generate a conventional commit, or <code>:trace</code> to investigate a
        failing test. See the full list on the
        <a href="/docs/commands">Commands</a> page.
    </p>
</div>


<h3>Dispatch a skill</h3>

<p>
    Use the <code>$</code> prefix to dispatch a named skill directly:
</p>

<pre><code>You &gt; $phpstan src/Controller/UserController.php</code></pre>

<p>
    This invokes the <strong>phpstan</strong> skill on the specified file.
    Skills are specialized, reusable workflows you can define in your
    project config. See <a href="/docs/commands">Commands</a> for details.
</p>


<!-- ================================================================== -->
<h2 id="switch-modes">Switch Modes</h2>
<!-- ================================================================== -->

<p>
    KosmoKrator operates in one of three modes that control what the agent can
    do. Switch modes at any time with a slash command:
</p>

<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Mode</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>/edit</code></td>
            <td>Edit</td>
            <td>Full access &mdash; read, write, search, and execute. The agent can modify files and run commands.</td>
        </tr>
        <tr>
            <td><code>/plan</code></td>
            <td>Plan</td>
            <td>Read-only access &mdash; the agent reads files and produces a plan but cannot write or execute anything.</td>
        </tr>
        <tr>
            <td><code>/ask</code></td>
            <td>Ask</td>
            <td>Question-only &mdash; the agent reads files to answer questions but cannot suggest edits or run commands.</td>
        </tr>
    </tbody>
</table>
</div>

<p>
    For the complete list of slash commands, power commands, and keyboard
    shortcuts, see the <a href="/docs/commands">Commands</a> reference.
</p>

<div class="tip">
    <p>
        <strong>Agent modes vs. permission modes:</strong> The table above
        shows <em>agent modes</em>, which control what capabilities the
        agent has (read, write, execute). Separately, <em>permission
        modes</em> control how aggressively those capabilities are
        auto-approved. Switch permission modes with
        <code>/guardian</code> (review every action),
        <code>/argus</code> (review writes and commands), or
        <code>/prometheus</code> (auto-approve everything). See
        <a href="/docs/permissions">Permissions</a> for details.
    </p>
</div>


<!-- ================================================================== -->
<h2 id="cli-essentials">CLI Essentials</h2>
<!-- ================================================================== -->

<h3>Subcommands</h3>

<p>
    Beyond the interactive session, KosmoKrator ships with several
    subcommands:
</p>

<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>Command</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>kosmokrator setup</code></td>
            <td>Run the setup wizard (providers, models, keys).</td>
        </tr>
        <tr>
            <td><code>kosmokrator config</code></td>
            <td>View and manage configuration values.</td>
        </tr>
        <tr>
            <td><code>kosmokrator auth</code></td>
            <td>Manage provider authentication (API keys, OAuth tokens).</td>
        </tr>
    </tbody>
</table>
</div>

<h3>Useful CLI options</h3>

<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>Option</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>--no-animation</code></td>
            <td>Disable all terminal animations. Useful in CI or screen readers.</td>
        </tr>
        <tr>
            <td><code>--renderer &lt;type&gt;</code></td>
            <td>Choose the output renderer (e.g. <code>stream</code>, <code>batch</code>).</td>
        </tr>
        <tr>
            <td><code>--resume</code></td>
            <td>Resume the most recent session.</td>
        </tr>
        <tr>
            <td><code>--session &lt;id&gt;</code></td>
            <td>Resume a specific session by ID.</td>
        </tr>
    </tbody>
</table>
</div>

<h3>Project-level config</h3>

<p>
    Global config lives at <code>~/.kosmokrator/config.yaml</code>. For
    per-project settings, create <code>.kosmokrator/config.yaml</code> in
    your project root. Project config overrides global values &mdash;
    perfect for team-shared defaults, model preferences, or custom skills.
    See <a href="/docs/configuration">Configuration</a> for the full
    reference.
</p>


<!-- ================================================================== -->
<h2 id="next-steps">Next Steps</h2>
<!-- ================================================================== -->

<p>
    You're set up and productive. Dive deeper into the topics below to get the
    most out of KosmoKrator.
</p>

<div class="docs-index-grid">
    <a href="/docs/installation" class="docs-card">
        <span class="card-icon">&#x1F4E5;</span>
        <h3>Installation</h3>
        <p>Static binaries, PHAR, source install, CLI options, and troubleshooting.</p>
    </a>
    <a href="/docs/configuration" class="docs-card">
        <span class="card-icon">&#x2699;&#xFE0F;</span>
        <h3>Configuration</h3>
        <p>Config file layering, all settings categories, environment variables, and YAML examples.</p>
    </a>
    <a href="/docs/tools" class="docs-card">
        <span class="card-icon">&#x1F6E0;&#xFE0F;</span>
        <h3>Tools</h3>
        <p>Complete reference for all built-in tools: file ops, search, bash, shell sessions, and more.</p>
    </a>
    <a href="/docs/providers" class="docs-card">
        <span class="card-icon">&#x1F50C;</span>
        <h3>Providers</h3>
        <p>40+ LLM providers, authentication setup, custom endpoints, and model overrides.</p>
    </a>
    <a href="/docs/agents" class="docs-card">
        <span class="card-icon">&#x1F465;</span>
        <h3>Agents</h3>
        <p>Agent types, subagent swarms, dependency DAGs, concurrency control, and stuck detection.</p>
    </a>
    <a href="/docs/permissions" class="docs-card">
        <span class="card-icon">&#x1F6E1;&#xFE0F;</span>
        <h3>Permissions</h3>
        <p>Guardian, Argus, and Prometheus modes. Evaluation chain, heuristics, and approval flows.</p>
    </a>
    <a href="/docs/commands" class="docs-card">
        <span class="card-icon">&#x26A1;</span>
        <h3>Commands</h3>
        <p>All slash commands, power commands, and keyboard shortcuts with usage examples.</p>
    </a>
</div>

<script>
function switchInstallTab(event, panelId) {
    const tabContainer = event.target.closest('.install-tabs');
    const parent = tabContainer.parentElement;
    tabContainer.querySelectorAll('.install-tab').forEach(t => t.classList.remove('active'));
    parent.querySelectorAll('.install-panel').forEach(p => p.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('install-' + panelId).classList.add('active');
}
</script>
<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';

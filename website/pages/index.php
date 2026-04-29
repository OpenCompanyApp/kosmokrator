<?php
$pageTitle = 'KosmoKrator — AI coding agent for terminal and headless automation';
$pageClass = 'homepage';

ob_start();
?>
    <link rel="canonical" href="https://kosmokrator.dev/">
    <style>
        .homepage .hero {
            min-height: auto;
            align-items: stretch;
            text-align: left;
            padding: 8rem 0 4rem;
        }

        .homepage .hero::after,
        .homepage .nebula-glow,
        .homepage .scroll-indicator {
            display: none;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(360px, 0.95fr);
            gap: 3rem;
            align-items: center;
        }

        .hero-copy {
            max-width: 720px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--crimson-light);
            background: var(--crimson-dim);
            border: 1px solid rgba(220, 20, 60, 0.18);
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78rem;
            margin-bottom: 1.25rem;
        }

        .homepage h1 {
            animation: none;
            opacity: 1;
            font-size: clamp(2.8rem, 6vw, 5.2rem);
            line-height: 0.98;
            margin-bottom: 1.25rem;
        }

        .homepage h1.hero-title-fallback {
            position: absolute !important;
            width: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: -1px !important;
            overflow: hidden !important;
            clip: rect(0, 0, 0, 0) !important;
            white-space: nowrap !important;
            border: 0 !important;
        }

        .homepage .ascii-logo {
            animation: none;
            opacity: 0.95;
            font-size: clamp(0.28rem, 0.72vw, 0.5rem);
            line-height: 1.18;
            margin: 0 0 1.25rem;
            max-width: 100%;
            position: relative;
            z-index: 2;
        }

        .hero-lead {
            color: var(--text-muted);
            font-size: clamp(1.05rem, 1.8vw, 1.28rem);
            max-width: 660px;
            margin-bottom: 1.75rem;
        }

        .hero-actions {
            justify-content: flex-start;
            animation: none;
            opacity: 1;
            margin-bottom: 1.75rem;
        }

        .hero-points {
            display: flex;
            flex-wrap: wrap;
            gap: 0.7rem;
            padding: 0;
            margin: 0;
            list-style: none;
        }

        .hero-points li {
            color: var(--text-muted);
            background: rgba(240, 240, 245, 0.04);
            border: 1px solid rgba(240, 240, 245, 0.08);
            border-radius: 8px;
            padding: 0.45rem 0.7rem;
            font-size: 0.88rem;
        }

        .product-panel {
            background: rgba(14, 14, 24, 0.9);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.38);
        }

        .panel-tabs {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-bottom: 1px solid var(--border);
        }

        .panel-tab {
            padding: 0.75rem 0.9rem;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78rem;
            border-right: 1px solid rgba(220, 20, 60, 0.07);
            background: rgba(220, 20, 60, 0.025);
        }

        .panel-tab:last-child {
            border-right: 0;
        }

        .panel-tab.active {
            color: var(--crimson-light);
            background: var(--crimson-dim);
        }

        .product-panel pre {
            margin: 0;
            padding: 1.35rem;
            min-height: 360px;
            color: #d6e2ee;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            line-height: 1.65;
            overflow-x: auto;
            background: transparent;
        }

        .metric-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-top: 2rem;
        }

        .metric {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1rem;
        }

        .metric strong {
            display: block;
            color: var(--star-white);
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.4rem;
            line-height: 1;
            margin-bottom: 0.35rem;
        }

        .metric span {
            color: var(--text-muted);
            font-size: 0.82rem;
        }

        .surface-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
        }

        .surface-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.4rem;
        }

        .surface-card h3 {
            color: var(--star-white);
            font-size: 1.05rem;
            margin-bottom: 0.45rem;
        }

        .surface-card p {
            color: var(--text-muted);
            margin-bottom: 1rem;
            font-size: 0.92rem;
        }

        .surface-card a {
            color: var(--crimson-light);
            text-decoration: none;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
        }

        .command-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 1.25rem;
            align-items: stretch;
        }

        .install-method-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
            align-items: stretch;
        }

        .command-box {
            background: var(--space-dark);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.25rem;
        }

        .method-label {
            display: inline-flex;
            align-items: center;
            color: var(--crimson-light);
            background: var(--crimson-dim);
            border: 1px solid rgba(220, 20, 60, 0.16);
            border-radius: 6px;
            padding: 0.2rem 0.5rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.68rem;
            margin-bottom: 0.75rem;
        }

        .command-box h3 {
            color: var(--star-white);
            font-size: 1rem;
            margin-bottom: 0.85rem;
        }

        .command-box p {
            color: var(--text-muted);
            font-size: 0.86rem;
            line-height: 1.55;
            margin-bottom: 1rem;
        }

        .command-box pre {
            margin: 0;
            color: #d6e2ee;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
            line-height: 1.65;
            white-space: pre-wrap;
        }

        .homepage .section-header {
            text-align: left;
            max-width: 760px;
        }

        .homepage .section-header .section-desc {
            margin-left: 0;
            margin-right: 0;
        }

        .homepage .reveal,
        .homepage .reveal-stagger .reveal-child {
            opacity: 1;
            transform: none;
            transition: none;
        }

        @media (max-width: 991.98px) {
            .hero-grid,
            .surface-grid,
            .command-grid,
            .install-method-grid {
                grid-template-columns: 1fr;
            }

            .product-panel pre {
                min-height: auto;
            }

            .metric-strip {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 575.98px) {
            .panel-tabs,
            .metric-strip {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "SoftwareApplication",
        "name": "KosmoKrator",
        "applicationCategory": "DeveloperApplication",
        "operatingSystem": "macOS, Linux, Android via Termux",
        "description": "AI coding agent for terminal use, headless automation, integrations, MCP, ACP, and embeddable PHP SDK workflows.",
        "url": "https://kosmokrator.dev/",
        "downloadUrl": "https://github.com/OpenCompanyApp/kosmokrator/releases/latest",
        "license": "https://opensource.org/licenses/MIT",
        "programmingLanguage": "PHP",
        "codeRepository": "https://github.com/OpenCompanyApp/kosmokrator",
        "author": {
            "@type": "Organization",
            "name": "OpenCompany",
            "url": "https://github.com/OpenCompanyApp"
        },
        "offers": {
            "@type": "Offer",
            "price": "0",
            "priceCurrency": "USD"
        }
    }
    </script>
<?php
$extraHead = ob_get_clean();

ob_start();
?>
        <header class="hero">
            <div class="hero-grid">
                <div class="hero-copy">
                    <div class="eyebrow">PHP 8.4 agent runtime for terminal, CI, editors, and apps</div>
                    <pre class="ascii-logo"
>&#x2588;&#x2588;&#x2557;  &#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2588;&#x2557;   &#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2557;  &#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557;  &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557;
&#x2588;&#x2588;&#x2551; &#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2551; &#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x255A;&#x2550;&#x2550;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x255D;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;
&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D; &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;
&#x2588;&#x2588;&#x2554;&#x2550;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551;&#x255A;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2554;&#x2550;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;
&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2557;&#x255A;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551; &#x255A;&#x2550;&#x255D; &#x2588;&#x2588;&#x2551;&#x255A;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x255A;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2551;
&#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D; &#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D; &#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D;&#x255A;&#x2550;&#x255D;     &#x255A;&#x2550;&#x255D; &#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D; &#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D;&#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D;&#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D;   &#x255A;&#x2550;&#x255D;    &#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D; &#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D;</pre>
                    <h1 class="hero-title-fallback">KosmoKrator</h1>
                    <p class="hero-lead">
                        A terminal-first coding agent with the same runtime available headlessly through
                        CLI commands, Lua, MCP, ACP, and an embeddable PHP SDK.
                    </p>
                    <div class="hero-actions">
                        <a href="/docs/getting-started" class="btn btn-primary">Get Started</a>
                        <a href="/docs/sdk" class="btn btn-secondary">Read SDK Docs</a>
                        <a href="https://github.com/OpenCompanyApp/kosmokrator" class="btn btn-secondary" target="_blank" rel="noopener">GitHub</a>
                    </div>
                    <ul class="hero-points">
                        <li>Interactive TUI or ANSI terminal</li>
                        <li>Headless JSON and stream-json execution</li>
                        <li>OpenCompany integrations, MCP, and Lua</li>
                        <li>ACP for non-PHP UI wrappers</li>
                        <li>SDK parity with the headless CLI</li>
                    </ul>
                </div>

                <div class="product-panel" aria-label="KosmoKrator surfaces">
                    <div class="panel-tabs">
                        <div class="panel-tab active">terminal</div>
                        <div class="panel-tab">headless</div>
                        <div class="panel-tab">sdk</div>
                        <div class="panel-tab">acp</div>
                    </div>
<pre><code>$ kosmokrator
You > Fix the failing tests and explain the risky changes.

  phase: exploring
  tool: php vendor/bin/phpunit
  tool: grep pattern="PaymentService"
  subagents: 3 running, 1 waiting

$ kosmokrator -p "Review this branch" \
    --mode plan \
    --output stream-json

$ kosmokrator integrations:plane search_issues --json
$ kosmokrator mcp:call github search_repositories \
    '{"query":"kosmokrator"}'

$agent = AgentBuilder::create()
    ->forProject($repo)
    ->withMode('edit')
    ->build()
    ->collect('Add tests for the billing edge cases');</code></pre>
                </div>
            </div>

            <div class="metric-strip">
                <div class="metric"><strong>~50MB</strong><span>runtime footprint</span></div>
                <div class="metric"><strong>40+</strong><span>LLM providers</span></div>
                <div class="metric"><strong>10</strong><span>parallel subagents by default</span></div>
                <div class="metric"><strong>1:1</strong><span>headless and SDK runtime parity</span></div>
            </div>
        </header>

        <main>
            <section id="features">
                <div class="section-header reveal">
                    <span class="section-label">Runtime Surface</span>
                    <h2>One agent core, several integration points</h2>
                    <p class="section-desc">
                        The terminal UI is only one way to use KosmoKrator. The same session builder,
                        tools, permissions, subagents, integrations, MCP runtime, Lua runtime, and context
                        system are available to automation and external applications.
                    </p>
                </div>

                <div class="surface-grid reveal-stagger">
                    <div class="surface-card reveal-child">
                        <h3>Terminal Agent</h3>
                        <p>Interactive TUI with ANSI fallback, slash commands, approvals, model switching, sessions, memories, and live subagent monitoring.</p>
                        <a href="/docs/getting-started">/docs/getting-started</a>
                    </div>
                    <div class="surface-card reveal-child">
                        <h3>Headless CLI</h3>
                        <p>Use `kosmokrator -p`, JSON output, stream-json events, settings commands, provider credentials, and agent-friendly helper commands in scripts.</p>
                        <a href="/docs/headless">/docs/headless</a>
                    </div>
                    <div class="surface-card reveal-child">
                        <h3>Integrations, MCP, and Lua</h3>
                        <p>Call OpenCompany integrations and MCP tools directly from CLI or Lua while preserving read/write permissions and credential handling.</p>
                        <a href="/docs/integrations">/docs/integrations</a>
                    </div>
                    <div class="surface-card reveal-child">
                        <h3>SDK and ACP</h3>
                        <p>Embed the headless runtime in PHP applications, or wrap KosmoKrator from non-PHP apps through ACP plus KosmoKrator extension events.</p>
                        <a href="/docs/sdk">/docs/sdk</a>
                    </div>
                </div>
            </section>

            <section id="quickstart">
                <div class="section-header reveal">
                    <span class="section-label">Install</span>
                    <h2>Install the way your environment expects</h2>
                    <p class="section-desc">Use a zero-dependency binary, a small PHAR, a source checkout, or Termux on Android. Every path lands on the same CLI and headless runtime.</p>
                </div>

                <div class="install-method-grid reveal-stagger">
                    <div class="command-box reveal-child">
                        <span class="method-label">Recommended</span>
                        <h3>Static binary</h3>
                        <p>Self-contained release binary. No local PHP, Composer, Node, or Python runtime required.</p>
<pre><code># macOS / Linux
curl -fsSL https://raw.githubusercontent.com/OpenCompanyApp/kosmokrator/main/install.sh | bash

kosmokrator setup
kosmokrator</code></pre>
                    </div>
                    <div class="command-box reveal-child">
                        <span class="method-label">Small package</span>
                        <h3>PHAR</h3>
                        <p>Single PHP archive for machines that already have PHP 8.4+ installed.</p>
<pre><code>sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator.phar \
  -o /usr/local/bin/kosmokrator
sudo chmod +x /usr/local/bin/kosmokrator

kosmokrator setup</code></pre>
                    </div>
                    <div class="command-box reveal-child">
                        <span class="method-label">Development</span>
                        <h3>Source checkout</h3>
                        <p>Best when contributing, testing branches, or building a custom PHAR.</p>
<pre><code>git clone https://github.com/OpenCompanyApp/kosmokrator.git
cd kosmokrator
composer install

bin/kosmokrator setup</code></pre>
                    </div>
                    <div class="command-box reveal-child">
                        <span class="method-label">Android</span>
                        <h3>Termux</h3>
                        <p>Run KosmoKrator on Android with Termux. Use ANSI renderer for the most predictable mobile terminal UX.</p>
<pre><code>pkg update
pkg install php composer git curl

curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator.phar \
  -o $PREFIX/bin/kosmokrator
chmod +x $PREFIX/bin/kosmokrator</code></pre>
                    </div>
                </div>

                <div class="command-grid reveal-stagger" style="margin-top: 1.25rem;">
                    <div class="command-box reveal-child">
                        <h3>Headless provider setup</h3>
<pre><code>kosmokrator providers:configure openai \
  --api-key-env OPENAI_API_KEY \
  --model gpt-5.4-mini \
  --global --json

kosmokrator -p "Summarize this repo" \
  --mode ask \
  -o json</code></pre>
                    </div>
                    <div class="command-box reveal-child">
                        <h3>Full installation docs</h3>
                        <p>Manual platform binaries, PHP extension requirements, update paths, PHAR builds, and troubleshooting live in the installation guides.</p>
<pre><code>kosmokrator --version
kosmokrator setup
kosmokrator</code></pre>
                        <a href="/docs/installation" class="btn btn-secondary" style="margin-top: 1rem;">Installation Docs</a>
                        <a href="/docs/termux" class="btn btn-secondary" style="margin-top: 1rem;">Termux Docs</a>
                    </div>
                </div>
            </section>

            <section id="permissions">
                <div class="section-header reveal">
                    <span class="section-label">Control</span>
                    <h2>Permissions are explicit in both UI and headless mode</h2>
                    <p class="section-desc">
                        Guardian, Argus, and Prometheus govern native tools. Integration and MCP read/write
                        policies apply in headless runs too, with force flags reserved for trusted automation.
                    </p>
                </div>

                <div class="row modes-grid reveal-stagger">
                    <div class="col-md-4 reveal-child">
                        <div class="mode-card">
                            <div class="mode-name">Guardian</div>
                            <div class="mode-tagline">Smart approval</div>
                            <div class="mode-desc">Safe reads and known-safe commands can proceed; writes and uncertain actions ask first.</div>
                        </div>
                    </div>
                    <div class="col-md-4 reveal-child">
                        <div class="mode-card featured">
                            <div class="mode-name">Argus</div>
                            <div class="mode-tagline">Ask for everything</div>
                            <div class="mode-desc">Every governed tool call requires explicit approval. Useful for audits and sensitive projects.</div>
                        </div>
                    </div>
                    <div class="col-md-4 reveal-child">
                        <div class="mode-card">
                            <div class="mode-name">Prometheus</div>
                            <div class="mode-tagline">Trusted automation</div>
                            <div class="mode-desc">Run without approval prompts for CI, scripts, and controlled environments.</div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="architecture">
                <div class="section-header reveal">
                    <span class="section-label">Architecture</span>
                    <h2>Built around a reusable headless session</h2>
                    <p class="section-desc">
                        `AgentSessionBuilder` wires the LLM client, tools, permissions, context manager,
                        renderer, subagents, integrations, MCP, and Lua. Terminal, CLI, SDK, and ACP entry
                        points all run through that same shape.
                    </p>
                </div>

                <div class="arch-flow reveal">
                    <div class="arch-node">CLI / SDK / ACP</div>
                    <span class="arch-arrow">&rarr;</span>
                    <div class="arch-node">AgentSessionBuilder</div>
                    <span class="arch-arrow">&rarr;</span>
                    <div class="arch-node core">AgentLoop</div>
                    <span class="arch-arrow">&rarr;</span>
                    <div class="arch-node">Tools + Integrations + MCP</div>
                </div>
            </section>
        </main>
<?php
$pageBody = ob_get_clean();

include __DIR__.'/_layout.php';

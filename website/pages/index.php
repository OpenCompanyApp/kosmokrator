<?php
$pageTitle = 'KosmoKrator — AI Coding Agent for the Terminal';
$pageClass = 'homepage';

// Extra head content: Schema.org JSON-LD and canonical
ob_start();
?>
    <link rel="canonical" href="https://kosmokrator.dev/">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "SoftwareApplication",
        "name": "KosmoKrator",
        "applicationCategory": "DeveloperApplication",
        "operatingSystem": "macOS, Linux",
        "description": "Lightweight, mythology-themed AI coding agent for the terminal. Written in PHP 8.4 with parallel subagent swarms, 40+ LLM providers, and ~50MB RAM footprint.",
        "url": "https://kosmokrator.dev/",
        "downloadUrl": "https://github.com/OpenCompanyApp/kosmokrator/releases/latest",
        "softwareVersion": "0.3",
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

// Page body
ob_start();
?>
        <header class="hero">
            <div class="nebula-glow"></div>
            <pre class="ascii-logo"
>&#x2588;&#x2588;&#x2557;  &#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2588;&#x2557;   &#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2557;  &#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557;  &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557;
&#x2588;&#x2588;&#x2551; &#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2551; &#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x255A;&#x2550;&#x2550;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x255D;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;
&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D; &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D; &#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;
&#x2588;&#x2588;&#x2554;&#x2550;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551;&#x255A;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2554;&#x2550;&#x2588;&#x2588;&#x2557; &#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2554;&#x2550;&#x2550;&#x2588;&#x2588;&#x2557;
&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2557;&#x255A;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551; &#x255A;&#x2550;&#x255D; &#x2588;&#x2588;&#x2551;&#x255A;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2557;&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2551;&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2551;   &#x2588;&#x2588;&#x2551;   &#x255A;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2588;&#x2554;&#x255D;&#x2588;&#x2588;&#x2551;  &#x2588;&#x2588;&#x2551;
&#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D; &#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D; &#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D;&#x255A;&#x2550;&#x255D;     &#x255A;&#x2550;&#x255D; &#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D; &#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D;&#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D;&#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D;   &#x255A;&#x2550;&#x255D;    &#x255A;&#x2550;&#x2550;&#x2550;&#x2550;&#x2550;&#x255D; &#x255A;&#x2550;&#x255D;  &#x255A;&#x2550;&#x255D;</pre>


            <p class="tagline">Lightweight, mythology-themed AI coding agent written in PHP. ~50MB RAM, parallel subagent swarms, and a permission system that keeps you in control &mdash; all in your terminal.</p>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 0.5rem; position: relative; z-index: 2; opacity: 0; animation: hero-fade-in 0.8s ease 1s forwards;">by OpenCompany &mdash; open-source tools for developers &middot; MIT License</p>

            <div class="hero-actions">
                <a href="#quickstart" class="btn btn-primary">Get Started</a>
                <a href="https://github.com/OpenCompanyApp/kosmokrator" class="btn btn-secondary" target="_blank" rel="noopener">View on GitHub</a>
            </div>
        </header>

        <main>
            <div class="section-sep"></div>

            <!-- Features -->
            <section id="features">
                <div class="section-header reveal">
                    <span class="section-label">Features</span>
                    <h2>Celestial Capabilities</h2>
                    <p class="section-desc">Everything you need for AI-powered coding, from autonomous agents to fine-grained permissions.</p>
                </div>
                <div class="features-grid reveal-stagger">
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1FAB6;</span>
                        <h3>Featherweight Runtime</h3>
                        <p>~50MB RAM footprint. Pure PHP &mdash; no Node.js, Python, or Electron bloat. Static binary with zero system dependencies. Starts in under a second.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F465;</span>
                        <h3>Subagent Swarm</h3>
                        <p>Spawn parallel child agents with dependency chains, sequential groups, and automatic retries. Up to 10 concurrent agents.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F6E1;&#xFE0F;</span>
                        <h3>Permission System</h3>
                        <p>Guardian, Argus, and Prometheus modes. Auto-approve safe ops, approve each action, or go fully autonomous.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F50C;</span>
                        <h3>40+ LLM Providers</h3>
                        <p>OpenAI, Anthropic, Google, DeepSeek, Groq, Ollama, xAI, Mistral, OpenRouter, StepFun, and many more through native SDKs and OpenAI-compatible APIs.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F9E0;</span>
                        <h3>Smart Context</h3>
                        <p>Importance-scored pruning, tool result deduplication, LLM-based compaction, and persistent memory extraction across sessions.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F4AD;</span>
                        <h3>Reasoning &amp; Thinking</h3>
                        <p>Native support for extended thinking and reasoning tokens. See the model's chain-of-thought with configurable budget controls.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F5A5;&#xFE0F;</span>
                        <h3>Terminal-Native</h3>
                        <p>Full TUI with Symfony Console or pure ANSI fallback. Works in any terminal, looks stunning in modern ones.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x26A1;</span>
                        <h3>Power Commands</h3>
                        <p>20+ workflow shortcuts with unique animations: <code>:unleash</code>, <code>:review</code>, <code>:deep-dive</code>, <code>:research</code>, and more. Combinable.</p>
                    </div>
                </div>
            </section>

            <div class="section-sep"></div>

            <!-- Interface -->
            <section id="interface">
                <div class="section-header reveal">
                    <span class="section-label">Interface</span>
                    <h2>How It Looks</h2>
                    <p class="section-desc">A rich, interactive experience running entirely in your terminal.</p>
                </div>

                <div class="terminal-mockup reveal" id="demo-terminal">
                    <div class="terminal-header">
                        <div class="terminal-dot dot-red"></div>
                        <div class="terminal-dot dot-yellow"></div>
                        <div class="terminal-dot dot-green"></div>
                        <span class="terminal-title">kosmokrator &mdash; zsh</span>
                    </div>
                    <div class="terminal-body" id="terminal-content">
                    </div>
                </div>

                <div class="stats-row reveal-stagger">
                    <div class="stat-item reveal-child">
                        <div class="stat-number" data-count="50">0</div>
                        <div class="stat-label">~MB RAM Usage</div>
                    </div>
                    <div class="stat-item reveal-child">
                        <div class="stat-number" data-count="40">0</div>
                        <div class="stat-label">LLM Providers</div>
                    </div>
                    <div class="stat-item reveal-child">
                        <div class="stat-number" data-count="10">0</div>
                        <div class="stat-label">Parallel Agents</div>
                    </div>
                    <div class="stat-item reveal-child">
                        <div class="stat-number" data-count="3">0</div>
                        <div class="stat-label">Permission Modes</div>
                    </div>
                </div>
            </section>

            <div class="section-sep"></div>

            <!-- Permission Modes -->
            <section id="permissions">
                <div class="section-header reveal">
                    <span class="section-label">Permissions</span>
                    <h2>Choose Your Trust Level</h2>
                    <p class="section-desc">Three modes that balance safety and autonomy, from cautious to fully autonomous.</p>
                </div>
                <div class="modes-grid reveal-stagger">
                    <div class="mode-card reveal-child">
                        <div class="mode-icon">&#x1F6E1;&#xFE0F;</div>
                        <div class="mode-name">Guardian</div>
                        <div class="mode-tagline">Smart Auto-Approve</div>
                        <div class="mode-desc">Auto-approves known-safe operations. Asks for writes, edits, and unknown commands.</div>
                        <ul class="mode-features">
                            <li>Safe commands auto-approved</li>
                            <li>Writes and unknowns gated</li>
                            <li>Best for daily use (default)</li>
                        </ul>
                    </div>
                    <div class="mode-card featured reveal-child">
                        <div class="mode-icon">&#x1F441;&#xFE0F;</div>
                        <div class="mode-name">Argus</div>
                        <div class="mode-tagline">Ask For Everything</div>
                        <div class="mode-desc">Every governed tool call requires explicit approval. Full visibility into every action.</div>
                        <ul class="mode-features">
                            <li>Approve every tool call</li>
                            <li>Full audit trail</li>
                            <li>Best for learning / exploring</li>
                        </ul>
                    </div>
                    <div class="mode-card reveal-child">
                        <div class="mode-icon">&#x1F525;</div>
                        <div class="mode-name">Prometheus</div>
                        <div class="mode-tagline">Full Autonomy</div>
                        <div class="mode-desc">Unrestricted execution. The agent works independently with no approval prompts.</div>
                        <ul class="mode-features">
                            <li>No approval required</li>
                            <li>Maximum speed</li>
                            <li>Best for trusted CI/CD</li>
                        </ul>
                    </div>
                </div>
            </section>

            <div class="section-sep"></div>

            <!-- Agent Types -->
            <section id="agents">
                <div class="section-header reveal">
                    <span class="section-label">Agents</span>
                    <h2>The Agent Hierarchy</h2>
                    <p class="section-desc">Three specialized agent types with progressively narrower capabilities to prevent privilege escalation.</p>
                </div>

                <div class="reveal">
                    <table class="agent-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Capabilities</th>
                                <th>Can Spawn</th>
                                <th>Use Case</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="agent-type-badge badge-general">General</span></td>
                                <td>Full: read, write, edit, bash, subagent</td>
                                <td>General, Explore, Plan</td>
                                <td>Autonomous coding tasks</td>
                            </tr>
                            <tr>
                                <td><span class="agent-type-badge badge-explore">Explore</span></td>
                                <td>Read-only: file_read, glob, grep, bash</td>
                                <td>Explore only</td>
                                <td>Research &amp; investigation</td>
                            </tr>
                            <tr>
                                <td><span class="agent-type-badge badge-plan">Plan</span></td>
                                <td>Read-only: file_read, glob, grep, bash</td>
                                <td>Explore only</td>
                                <td>Planning &amp; architecture</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="features-grid reveal-stagger" style="margin-top: 3rem;">
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F500;</span>
                        <h3>Dependency DAGs</h3>
                        <p>Agents declare <code>depends_on</code> with automatic circular-dependency detection. Upstream results inject into downstream task prompts.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F4E6;</span>
                        <h3>Sequential Groups</h3>
                        <p>Assign a <code>group</code> to run agents serially within a parallel swarm. Ordered pipelines without sacrificing overall concurrency.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x23F3;</span>
                        <h3>Await &amp; Background</h3>
                        <p><code>await</code> blocks until the agent finishes. <code>background</code> returns immediately &mdash; results inject on the next LLM turn.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F504;</span>
                        <h3>Auto-Retry with Backoff</h3>
                        <p>Failed agents retry with exponential backoff + jitter. Auth errors (401/403) are never retried.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F6A6;</span>
                        <h3>Concurrency Control</h3>
                        <p>Global semaphore caps concurrent agents. Per-group semaphores enforce ordering. Configurable depth limits.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1FA91;</span>
                        <h3>Slot Yielding</h3>
                        <p>Parents yield their concurrency slot to children and reclaim it after, preventing deadlocks when the pool is full.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F9E0;</span>
                        <h3>Stuck Detection</h3>
                        <p>Rolling-window repetition detection for headless agents: nudge &rarr; final notice &rarr; force return. No infinite loops.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F4E1;</span>
                        <h3>Watchdog Timers</h3>
                        <p>Configurable idle timeout per agent. Stuck agents are killed automatically without manual intervention.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F512;</span>
                        <h3>Permission Narrowing</h3>
                        <p>Children can only reduce capabilities, never escalate. An Explore agent can only spawn more Explore agents.</p>
                    </div>
                </div>
            </section>

            <div class="section-sep"></div>

            <!-- Swarm Control UI -->
            <section id="swarm-ui">
                <div class="section-header reveal">
                    <span class="section-label">Swarm Control</span>
                    <h2>Live Swarm Dashboard</h2>
                    <p class="section-desc">Press <code style="font-family:'JetBrains Mono',monospace;font-size:0.9rem;color:var(--crimson-light);background:var(--crimson-dim);padding:0.15rem 0.45rem;border-radius:4px;">ctrl+a</code> to open the live Swarm Control overlay &mdash; progress bars, resource tracking, and per-agent stats that auto-refresh every 2 seconds.</p>
                </div>

                <div class="terminal-mockup reveal" style="max-width: 780px; margin: 2rem auto 0;">
                    <div class="terminal-header">
                        <div class="terminal-dot dot-red"></div>
                        <div class="terminal-dot dot-yellow"></div>
                        <div class="terminal-dot dot-green"></div>
                        <span class="terminal-title">kosmokrator &mdash; Swarm Control</span>
                    </div>
                    <pre class="terminal-body" style="font-size: 0.72rem; line-height: 1.6; padding: 1.5rem 1.75rem; color: #c9d1d9; background: #0d1117; overflow-x: auto;">
<span style="color:#64c8dc;">&#9210;  S W A R M   C O N T R O L</span>

<span style="color:#50dc64;">&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;&#9608;</span>  <span style="color:#50dc64;">100.0%</span>
<span style="color:#8b949e;">28 of 28 agents completed</span>

<span style="color:#50dc64;">&#10003; 28 done</span>   <span style="color:#d29922;">&#9679; 0 running</span>   <span style="color:#6e7681;">&#9676; 0 queued</span>   <span style="color:#f85149;">&#10007; 0 failed</span>

<span style="color:#64c8dc;">&#9472;&#9472;&#9472;&#9472; &#9737; Resources &#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;</span>

<span style="color:#8b949e;">Tokens</span>    <span style="color:#c9d1d9;">14.1M in</span>  <span style="color:#6e7681;">&#183;</span>  <span style="color:#c9d1d9;">453k out</span>  <span style="color:#6e7681;">&#183;</span>  <span style="color:#c9d1d9;">14.5M total</span>
<span style="color:#8b949e;">Cost</span>      <span style="color:#50dc64;">$49.07</span>   <span style="color:#6e7681;">&#183;</span>  <span style="color:#8b949e;">avg $1.75/agent</span>
<span style="color:#8b949e;">Elapsed</span>   <span style="color:#c9d1d9;">12m 23s</span>  <span style="color:#6e7681;">&#183;</span>  <span style="color:#8b949e;">rate 2.3 agents/min</span>

<span style="color:#6e7681;">Esc/q close  &#183;  auto-refreshes every 2s</span></pre>
                </div>

                <div class="features-grid reveal-stagger" style="margin-top: 2.5rem;">
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F4CA;</span>
                        <h3>Live Progress</h3>
                        <p>Global progress bar and per-agent status. See running, done, queued, and failed counts at a glance.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F333;</span>
                        <h3>Tree View</h3>
                        <p>Hierarchical display with status icons &mdash; running, done, failed, waiting on dependencies. Toggle with ctrl+a.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x23F1;&#xFE0F;</span>
                        <h3>Resource Tracking</h3>
                        <p>Token usage, cost breakdown, elapsed time, and agent throughput rate &mdash; all auto-refreshing in real time.</p>
                    </div>
                </div>
            </section>

            <div class="section-sep"></div>

            <!-- Quick Start -->
            <section id="quickstart">
                <div class="section-header reveal">
                    <span class="section-label">Get Started</span>
                    <h2>Install &amp; Run</h2>
                    <p class="section-desc">Static binary, PHAR, or from source &mdash; pick whichever fits your setup.</p>
                </div>

                <div class="reveal" style="max-width: 820px; margin: 0 auto;">
                    <div class="install-tabs">
                        <button class="install-tab active" data-tab="binary">Binary</button>
                        <button class="install-tab" data-tab="phar">PHAR</button>
                        <button class="install-tab" data-tab="source">Source</button>
                    </div>

                    <div class="install-panel active" id="tab-binary">
                        <div class="quickstart-code" style="border-radius: 0 0 14px 14px; border-top: none;">
                            <div class="terminal-body">
                                <div class="terminal-line"><span class="code-comment"># ~25MB static binary &mdash; no PHP required</span></div>
                                <div class="terminal-line" style="margin-top:0.75rem"><span class="code-comment"># macOS (Apple Silicon)</span></div>
                                <div class="terminal-line"><span class="prompt">$</span> <span class="command">curl -L https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-macos-aarch64 \</span></div>
                                <div class="terminal-line"><span class="command">  -o /usr/local/bin/kosmokrator &amp;&amp; chmod +x /usr/local/bin/kosmokrator</span></div>
                                <div class="terminal-line" style="margin-top:0.75rem"><span class="code-comment"># macOS (Intel)</span></div>
                                <div class="terminal-line"><span class="prompt">$</span> <span class="command">curl -L .../kosmokrator-macos-x86_64 \</span></div>
                                <div class="terminal-line"><span class="command">  -o /usr/local/bin/kosmokrator &amp;&amp; chmod +x /usr/local/bin/kosmokrator</span></div>
                                <div class="terminal-line" style="margin-top:0.75rem"><span class="code-comment"># Linux (x86_64 / ARM)</span></div>
                                <div class="terminal-line"><span class="prompt">$</span> <span class="command">curl -L .../kosmokrator-linux-x86_64 \  <span class="code-comment"># or kosmokrator-linux-aarch64</span></span></div>
                                <div class="terminal-line"><span class="command">  -o /usr/local/bin/kosmokrator &amp;&amp; chmod +x /usr/local/bin/kosmokrator</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="install-panel" id="tab-phar">
                        <div class="quickstart-code" style="border-radius: 0 0 14px 14px; border-top: none;">
                            <div class="terminal-body">
                                <div class="terminal-line"><span class="code-comment"># ~5MB, requires PHP 8.4+ with pcntl, posix, mbstring</span></div>
                                <div class="terminal-line" style="margin-top:0.5rem"><span class="prompt">$</span> <span class="command">curl -L https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator.phar \</span></div>
                                <div class="terminal-line"><span class="command">  -o /usr/local/bin/kosmokrator &amp;&amp; chmod +x /usr/local/bin/kosmokrator</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="install-panel" id="tab-source">
                        <div class="quickstart-code" style="border-radius: 0 0 14px 14px; border-top: none;">
                            <div class="terminal-body">
                                <div class="terminal-line"><span class="code-comment"># Requires PHP 8.4+, Composer</span></div>
                                <div class="terminal-line" style="margin-top:0.5rem"><span class="prompt">$</span> <span class="command">git clone https://github.com/OpenCompanyApp/kosmokrator.git</span></div>
                                <div class="terminal-line"><span class="prompt">$</span> <span class="command">cd kosmokrator &amp;&amp; composer install</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="quickstart-code" style="margin-top: 1.25rem;">
                        <div class="terminal-header">
                            <div class="terminal-dot dot-red"></div>
                            <div class="terminal-dot dot-yellow"></div>
                            <div class="terminal-dot dot-green"></div>
                            <span class="terminal-title">Then Launch</span>
                        </div>
                        <div class="terminal-body">
                            <div class="terminal-line"><span class="prompt">$</span> <span class="command">kosmokrator setup</span>    <span class="code-comment"># First run &mdash; select provider &amp; API key</span></div>
                            <div class="terminal-line"><span class="prompt">$</span> <span class="command">kosmokrator</span>          <span class="code-comment"># Start the agent</span></div>
                        </div>
                    </div>

                    <p style="text-align: center; color: var(--text-muted); font-size: 0.92rem; margin-top: 1rem;">
                        Supports <strong style="color: var(--crimson-light);">40+ LLM providers</strong> &mdash; OpenAI, Anthropic, Google, DeepSeek, Groq, Mistral, xAI, OpenRouter, StepFun, Ollama, and any OpenAI-compatible endpoint.
                    </p>
                </div>
            </section>

            <div class="section-sep"></div>

            <!-- Architecture -->
            <section id="architecture">
                <div class="section-header reveal">
                    <span class="section-label">Architecture</span>
                    <h2>How It Works</h2>
                    <p class="section-desc">A thin orchestrator loop that delegates to specialized subsystems.</p>
                </div>

                <div class="arch-flow reveal">
                    <div class="arch-node">bin/kosmokrator</div>
                    <span class="arch-arrow">&rarr;</span>
                    <div class="arch-node">Kernel</div>
                    <span class="arch-arrow">&rarr;</span>
                    <div class="arch-node">AgentCommand</div>
                    <span class="arch-arrow">&rarr;</span>
                    <div class="arch-node core">AgentLoop</div>
                </div>

                <div class="arch-flow reveal" style="margin-top: 1rem;">
                    <div class="arch-node">ToolExecutor</div>
                    <div class="arch-node">ContextManager</div>
                    <div class="arch-node">StuckDetector</div>
                    <div class="arch-node">SubagentOrchestrator</div>
                    <div class="arch-node">UIManager</div>
                </div>

                <div class="features-grid reveal-stagger" style="margin-top: 3rem;">
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F504;</span>
                        <h3>Agent Loop</h3>
                        <p>A ~570-line REPL orchestrator that manages the conversation, delegates tool calls, handles streaming, and coordinates subagents.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F500;</span>
                        <h3>Subagent System</h3>
                        <p>Three agent types with dependency resolution, concurrency semaphores, retry policies, and stuck detection.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F4BE;</span>
                        <h3>Session Persistence</h3>
                        <p>SQLite-backed storage for sessions, messages, memories, and settings. Resume conversations and recall context.</p>
                    </div>
                </div>
            </section>

            <div class="section-sep"></div>

            <!-- Tech Stack -->
            <section id="tech">
                <div class="section-header reveal">
                    <span class="section-label">Tech Stack</span>
                    <h2>Built With Cosmic Power</h2>
                    <p class="section-desc">Modern PHP 8.4 with async I/O, rich terminal UI, and first-class LLM SDK support.</p>
                </div>
                <div class="features-grid reveal-stagger">
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F718;</span>
                        <h3>PHP 8.4</h3>
                        <p>Strict types, enums, readonly classes, and property hooks throughout the entire codebase.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x26A1;</span>
                        <h3>Amp / Revolt</h3>
                        <p>Async HTTP streaming with non-blocking I/O. Responsive interactions even with long-running LLM requests.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F3A8;</span>
                        <h3>Symfony TUI</h3>
                        <p>Rich full-screen terminal UI with widgets, dialogs, animations, markdown rendering, and an inline editor.</p>
                    </div>
                    <div class="feature-card reveal-child">
                        <span class="feature-icon">&#x1F52E;</span>
                        <h3>Prism PHP</h3>
                        <p>First-class SDK for Anthropic, OpenAI, and other native providers with structured output and tool calling.</p>
                    </div>
                </div>
            </section>
        </main>
<?php
$pageBody = ob_get_clean();

// Homepage-specific JS
ob_start();
?>
    <script>
        // Terminal typing animation
        const termLines = [
            { delay: 0,    html: '<span class="prompt">$</span> <span class="command">kosmokrator</span>' },
            { delay: 800,  html: '', type: 'gap' },
            { delay: 900,  html: '<span style="color:#70a0d0;">  PHPStan reports 251 errors across the codebase. The most pragmatic approach: adjust the</span>' },
            { delay: 1000, html: '<span style="color:#70a0d0;">  config to suppress noisy categories while fixing the real bugs. Let me check the current setup.</span>' },
            { delay: 1600, html: '' },
            { delay: 1700, html: '<span style="color:#d29922;">  &#x26A1;&#xFE0E; php vendor/bin/phpstan analyse --no-progress --error-format=raw 2>&1 | head -300</span>' },
            { delay: 2200, html: '<span style="color:#8b949e;">  &boxv; Note: Using configuration file phpstan.neon.</span>' },
            { delay: 2300, html: '<span style="color:#8b949e;">    src/Agent/AgentLoop.php:56:Property AgentLoop::$lastCacheReadInputTokens is never read.</span>' },
            { delay: 2500, html: '<span style="color:#8b949e;">    <span style="color:#d29922;">&#x229B; +251 lines</span> (ctrl+o to reveal)</span>' },
            { delay: 3200, html: '' },
            { delay: 3300, html: '<span style="color:#70a0d0;">  251 errors. The majority fall into distinct categories. Let me categorize them:</span>' },
            { delay: 4200, html: '' },
            { delay: 4300, html: '<span style="color:#d29922;">  &#x26A1;&#xFE0E; php vendor/bin/phpstan analyse --no-progress --error-format=raw 2>&1 | sed \'s/.*: //\' | sort | uniq -c | sort -rn | head -30</span>' },
            { delay: 4800, html: '<span style="color:#8b949e;">  &boxv;   14 string} on left side of ?? always exists and is not nullable.</span>' },
            { delay: 4900, html: '<span style="color:#8b949e;">       7 array&lt;int, array&lt;string, mixed&gt;&gt;} on left side of ?? always exists.</span>' },
            { delay: 5000, html: '<span style="color:#8b949e;">    <span style="color:#d29922;">&#x229B; +30 lines</span> (ctrl+o to reveal)</span>' },
            { delay: 5700, html: '' },
            { delay: 5800, html: '<span style="color:#70a0d0;">  The most efficient approach: add targeted ignoreErrors patterns for the noise, then fix</span>' },
            { delay: 5900, html: '<span style="color:#70a0d0;">  the real bugs individually. Let me dispatch this as subagents for speed.</span>' },
            { delay: 6600, html: '' },
            { delay: 6700, html: '<span style="color:#64c8dc;">&#x23FA; 3 agents (2 running, 1 waiting, 0 done)</span>' },
            { delay: 7000, html: '<span style="color:#64c8dc;">  &#x251C;&#x2500;</span> <span style="color:#d29922;">&#x25CF;</span> <span style="color:#8b949e;">General</span> phpstan-fixes <span style="color:#8b949e;">&middot; Fix all real PHPStan errors (8s)</span>' },
            { delay: 7200, html: '<span style="color:#64c8dc;">  &#x251C;&#x2500;</span> <span style="color:#d29922;">&#x25CF;</span> <span style="color:#8b949e;">Explore</span> ci-audit <span style="color:#8b949e;">&middot; Audit CI workflow config (5s)</span>' },
            { delay: 7400, html: '<span style="color:#64c8dc;">  &#x2514;&#x2500;</span> <span style="color:#6e7681;">&#x25CC;</span> <span style="color:#8b949e;">General</span> release-prep <span style="color:#8b949e;">&middot; depends on: phpstan-fixes</span>' },
            { delay: 7800, html: '' },
            { delay: 7900, html: '<span style="color:#70a0d0;">&#x2726; 3 agents active</span> <span style="color:#8b949e;">&middot; 0 done &middot; 0:08 &middot; ctrl+a for dashboard</span>' },
            { delay: 8600, html: '' },
            { delay: 8700, html: '<span style="color:#50dc64;">  &#x250C; Tasks</span>' },
            { delay: 8800, html: '<span style="color:#50dc64;">  &#x2502;</span> <span style="color:#d29922;">&#x25CE;</span> Fix all PHPStan errors' },
            { delay: 8900, html: '<span style="color:#50dc64;">  &#x2502;</span> <span style="color:#8b949e;">&#x25CB;</span> Fix CI workflows' },
            { delay: 9000, html: '<span style="color:#50dc64;">  &#x2502;</span> <span style="color:#8b949e;">&#x25CB;</span> Stage, bump version, test, release v0.3.0' },
            { delay: 9100, html: '<span style="color:#50dc64;">  &#x2514;</span>' },
        ];

        let termAnimated = false;
        const termObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !termAnimated) {
                    termAnimated = true;
                    animateTerminal();
                    termObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.3 });

        const termEl = document.getElementById('demo-terminal');
        if (termEl) termObserver.observe(termEl);

        function animateTerminal() {
            const content = document.getElementById('terminal-content');
            termLines.forEach(line => {
                setTimeout(() => {
                    const div = document.createElement('div');
                    div.className = 'terminal-line';
                    div.innerHTML = line.html;
                    div.style.opacity = '0';
                    div.style.transform = 'translateY(4px)';
                    content.appendChild(div);
                    requestAnimationFrame(() => {
                        div.style.transition = 'all 0.3s ease';
                        div.style.opacity = '1';
                        div.style.transform = 'translateY(0)';
                    });
                }, line.delay);
            });
        }

        // Install tabs
        document.querySelectorAll('.install-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.install-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.install-panel').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
            });
        });

        // Counter animation
        const counters = document.querySelectorAll('[data-count]');
        let counterAnimated = false;
        const counterObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !counterAnimated) {
                    counterAnimated = true;
                    counters.forEach(el => {
                        const target = parseInt(el.dataset.count);
                        const duration = 1500;
                        const start = performance.now();
                        function tick(now) {
                            const progress = Math.min((now - start) / duration, 1);
                            const eased = 1 - Math.pow(1 - progress, 3);
                            const val = Math.round(eased * target);
                            el.textContent = val + (target === 100 ? '' : '+');
                            if (progress < 1) requestAnimationFrame(tick);
                        }
                        requestAnimationFrame(tick);
                    });
                    counterObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.3 });

        const statsRow = document.querySelector('.stats-row');
        if (statsRow) counterObserver.observe(statsRow);
    </script>
<?php
$extraScript = ob_get_clean();

include __DIR__ . '/_layout.php';

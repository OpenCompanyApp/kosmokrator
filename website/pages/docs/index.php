<?php
$pageTitle = 'Documentation — KosmoKrator';
$pageClass = 'docs-page';

$topics = [
    'getting-started' => ['Getting Started', 'Five-minute quickstart: install, configure, and make your first AI-assisted code change.', '&#x1F680;'],
    'installation' => ['Installation',     'Get up and running with static binaries, PHAR, or from source. First-run setup and CLI options.', '&#x1F4E5;'],
    'configuration' => ['Configuration',    'Config file layering, all settings categories, environment variables, and YAML examples.', '&#x2699;&#xFE0F;'],
    'headless' => ['Headless Mode',    'Non-interactive execution for CI/CD, scripts, and automated workflows with JSON and streaming output.', '&#x1F916;'],
    'tools' => ['Tools',            'Complete reference for all built-in tools: file ops, search, bash, shell sessions, and more.', '&#x1F6E0;&#xFE0F;'],
    'lua' => ['Lua',              'Lua runtime guide: namespaces, app.tools, docs flow, and integration scripting patterns.', '&#x1F40D;'],
    'providers' => ['Providers',        '40+ LLM providers, authentication setup, custom endpoints, and per-depth model overrides.', '&#x1F50C;'],
    'agents' => ['Agents',           'Agent types, subagent swarms, dependency DAGs, concurrency control, and stuck detection.', '&#x1F465;'],
    'permissions' => ['Permissions',      'Guardian, Argus, and Prometheus modes. Evaluation chain, heuristics, and approval flows.', '&#x1F6E1;&#xFE0F;'],
    'context' => ['Context & Memory', 'Context pipeline, token budgets, compaction, pruning, and persistent memory system.', '&#x1F9E0;'],
    'commands' => ['Commands',         'All slash commands, power commands, and keyboard shortcuts with usage examples.', '&#x26A1;'],
    'patterns' => ['Advanced Patterns', 'Real-world recipes: CI/CD, cost optimization, code review, swarm orchestration, and more.', '&#x1F373;'],
    'ui-guide' => ['UI Guide',         'TUI and ANSI renderers, terminal compatibility, and output display.', '&#x1F5A5;&#xFE0F;'],
    'architecture' => ['Architecture',      'Request lifecycle, key directories, rendering layer, agent loop, and session persistence.', '&#x1F3D7;&#xFE0F;'],
];

ob_start();
?>
<div class="docs-layout row">
    <aside class="docs-sidebar col-lg-3">
        <button class="docs-mobile-toggle">Menu</button>
        <nav class="docs-nav flex-column">
            <a href="/docs" class="docs-nav-heading">Documentation</a>
<?php foreach ($topics as $slug => $info) { ?>
            <a href="/docs/<?= $slug ?>" class="docs-nav-item"><?= $info[0] ?></a>
<?php } ?>
        </nav>
    </aside>
    <main class="docs-content col-lg-9">
        <h1>Documentation</h1>
        <p class="lead">Everything you need to get the most out of KosmoKrator. Pick a topic to dive in.</p>

        <div class="docs-index-grid">
<?php foreach ($topics as $slug => $info) { ?>
            <a href="/docs/<?= $slug ?>" class="docs-card">
                <span class="card-icon"><?= $info[2] ?></span>
                <h3><?= $info[0] ?></h3>
                <p><?= $info[1] ?></p>
            </a>
<?php } ?>
        </div>
    </main>
</div>
<?php
$pageBody = ob_get_clean();
include __DIR__.'/../_layout.php';

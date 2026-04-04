<?php
$pageTitle = 'Documentation — KosmoKrator';
$pageClass = 'docs-page';

$topics = [
    'installation'  => ['Installation',     'Get up and running with static binaries, PHAR, or from source. First-run setup and CLI options.', '&#x1F4E5;'],
    'configuration' => ['Configuration',    'Config file layering, all settings categories, environment variables, and YAML examples.', '&#x2699;&#xFE0F;'],
    'tools'         => ['Tools',            'Complete reference for all built-in tools: file ops, search, bash, shell sessions, and more.', '&#x1F6E0;&#xFE0F;'],
    'providers'     => ['Providers',        '40+ LLM providers, authentication setup, custom endpoints, and per-depth model overrides.', '&#x1F50C;'],
    'agents'        => ['Agents',           'Agent types, subagent swarms, dependency DAGs, concurrency control, and stuck detection.', '&#x1F465;'],
    'permissions'   => ['Permissions',      'Guardian, Argus, and Prometheus modes. Evaluation chain, heuristics, and approval flows.', '&#x1F6E1;&#xFE0F;'],
    'context'       => ['Context & Memory', 'Context pipeline, token budgets, compaction, pruning, and persistent memory system.', '&#x1F9E0;'],
    'commands'      => ['Commands',         'All slash commands, power commands, and keyboard shortcuts with usage examples.', '&#x26A1;'],
];

ob_start();
?>
<div class="docs-layout row">
    <aside class="docs-sidebar col-lg-3">
        <button class="docs-mobile-toggle">Menu</button>
        <nav class="docs-nav flex-column">
            <a href="/docs" class="docs-nav-heading">Documentation</a>
<?php foreach ($topics as $slug => $info): ?>
            <a href="/docs/<?= $slug ?>" class="docs-nav-item"><?= $info[0] ?></a>
<?php endforeach; ?>
        </nav>
    </aside>
    <main class="docs-content col-lg-9">
        <h1>Documentation</h1>
        <p class="lead">Everything you need to get the most out of KosmoKrator. Pick a topic to dive in.</p>

        <div class="docs-index-grid">
<?php foreach ($topics as $slug => $info): ?>
            <a href="/docs/<?= $slug ?>" class="docs-card">
                <span class="card-icon"><?= $info[2] ?></span>
                <h3><?= $info[0] ?></h3>
                <p><?= $info[1] ?></p>
            </a>
<?php endforeach; ?>
        </div>
    </main>
</div>
<?php
$pageBody = ob_get_clean();
include __DIR__ . '/../_layout.php';

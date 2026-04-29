<?php
/**
 * Docs layout wrapper.
 *
 * Expects: $docTitle, $docSlug, $docContent
 * Produces: $pageTitle, $pageClass, $pageBody — then includes _layout.php
 */
$pageTitle = $docTitle.' — KosmoKrator Docs';
$pageClass = 'docs-page';

$topics = [
    'getting-started' => ['Getting Started',   'Quick-start guide'],
    'installation' => ['Installation',       'Get up and running'],
    'termux' => ['Termux (Android)',   'Running on Android via Termux'],
    'configuration' => ['Configuration',       'Settings and config files'],
    'headless' => ['Headless Mode',       'CI/CD and non-interactive execution'],
    'integrations' => ['Integrations CLI',    'Headless integrations for scripts and coding CLIs'],
    'mcp' => ['MCP',                 'Portable .mcp.json servers, headless calls, and app.mcp Lua'],
    'acp' => ['ACP',                 'Editor and IDE integration through Agent Client Protocol'],
    'tools' => ['Tools',               'Built-in tool reference'],
    'lua' => ['Lua',                 'Lua runtime, namespaces, and app.tools reference'],
    'providers' => ['Providers',           'LLM providers and models'],
    'agents' => ['Agents',              'Subagent types and swarms'],
    'permissions' => ['Permissions',         'Permission modes and rules'],
    'context' => ['Context & Memory',    'Context management pipeline'],
    'commands' => ['Commands',            'Slash and power commands'],
    'patterns' => ['Advanced Patterns',    'Real-world usage recipes'],
    'ui-guide' => ['UI Guide',            'TUI and ANSI renderers, terminal compatibility'],
    'architecture' => ['Architecture',         'Request lifecycle, rendering layer, agent loop internals'],
];

ob_start();
?>
<div class="docs-layout row">
    <aside class="docs-sidebar col-lg-3">
        <button class="docs-mobile-toggle">Menu</button>
        <nav class="docs-nav flex-column">
            <a href="/docs" class="docs-nav-heading">Documentation</a>
<?php foreach ($topics as $slug => $info) { ?>
            <a href="/docs/<?= $slug ?>" class="docs-nav-item<?= $slug === $docSlug ? ' active' : '' ?>"><?= $info[0] ?></a>
<?php } ?>
        </nav>
    </aside>
    <main class="docs-content col-lg-9">
        <div class="docs-breadcrumb">
            <a href="/">Home</a> &rsaquo; <a href="/docs">Docs</a> &rsaquo; <?= htmlspecialchars($docTitle) ?>
        </div>
        <h1><?= htmlspecialchars($docTitle) ?></h1>
        <?= $docContent ?>
    </main>
</div>
<?php
$pageBody = ob_get_clean();
include __DIR__.'/_layout.php';

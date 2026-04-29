<?php

$pagesDir = __DIR__ . '/pages';
$htmlDir = __DIR__ . '/html';

// ── Minification helpers ──

function minifyHtml(string $html): string
{
    $placeholders = [];
    $i = 0;

    // Protect <pre>, <script>, <style> blocks from whitespace mangling
    $html = preg_replace_callback(
        '/<(script|style|pre)((?:\s[^>]*)?)>(.*?)<\/\1>/si',
        function ($m) use (&$placeholders, &$i) {
            $tag = strtolower($m[1]);
            $attrs = $m[2];
            $content = $m[3];

            if ($tag === 'script' && preg_match('/type\s*=\s*["\']application\//i', $attrs)) {
                $key = "<!--PH_{$i}-->";
                $placeholders[$key] = "<{$tag}{$attrs}>{$content}</{$tag}>";
                $i++;
                return $key;
            }

            if ($tag === 'style') {
                $content = minifyCss($content);
            } elseif ($tag === 'script') {
                $content = minifyJs($content);
            }
            // <pre> content is preserved as-is

            $key = "<!--PH_{$i}-->";
            $placeholders[$key] = "<{$tag}{$attrs}>{$content}</{$tag}>";
            $i++;
            return $key;
        },
        $html
    );

    $html = preg_replace('/<!--(?!PH_)\s*.*?-->/s', '', $html);
    $html = preg_replace('/>\s+</', '> <', $html);
    $html = preg_replace('/\s{2,}/', ' ', $html);
    $html = str_replace(array_keys($placeholders), array_values($placeholders), $html);

    return trim($html);
}

function minifyCss(string $css): string
{
    $css = preg_replace('/\/\*.*?\*\//s', '', $css);
    $css = preg_replace('/\s+/', ' ', $css);
    $css = preg_replace('/\s*([{};:,>~+])\s*/', '$1', $css);
    $css = str_replace(';}', '}', $css);
    return trim($css);
}

function minifyJs(string $js): string
{
    // Only safe transforms — no comment stripping (too risky without a parser)
    $js = preg_replace('/\n{2,}/', "\n", $js);
    return trim($js);
}

function annotateCodeBlocks(string $html): string
{
    return (string) preg_replace_callback(
        '/<pre><code(?![^>]*\bclass=)([^>]*)>(.*?)<\/code><\/pre>/si',
        static function (array $m): string {
            $language = inferCodeLanguage($m[2]);
            if ($language === null) {
                return $m[0];
            }

            return '<pre><code class="language-'.$language.'"'.$m[1].'>'.$m[2].'</code></pre>';
        },
        $html
    );
}

function inferCodeLanguage(string $encoded): ?string
{
    $code = trim(html_entity_decode(strip_tags($encoded), ENT_QUOTES | ENT_HTML5));
    if ($code === '') {
        return null;
    }

    if (preg_match('/^(#|\$\s|kosmokrator|php |composer |git |curl |sudo |pkg |printf |jq |chmod |ln |cd |cat |brew |bin\/|termux-|COMPOSER_|mcp-cli\b)/m', $code)) {
        return 'bash';
    }

    if (preg_match('/^(\/[a-z][a-z_-]*|:[a-z][a-z_-]*|You >|\$[a-z][a-z0-9_-]+[ \t]+(?![=])|[A-Za-z_][A-Za-z0-9_]*=\$\(|file_read|file_write|file_edit|apply_patch|glob|grep|web_search|web_fetch|web_crawl|bash|memory_|session_|task_|lua_|execute_lua|ask_)/', $code)) {
        return 'bash';
    }

    if (str_starts_with($code, '<?php') || preg_match('/\b(use|namespace)\s+Kosmokrator\\\\|AgentBuilder::|\$[A-Za-z_][A-Za-z0-9_]*|->/', $code)) {
        return 'php';
    }

    if ((str_starts_with($code, '{') || str_starts_with($code, '[')) && json_decode($code, true) !== null) {
        return 'json';
    }

    if (preg_match('/^(agent|prism|integrations|mcp|tools|context|name|on|jobs|steps|providers|permissions|memory|gateway|ui):/m', $code)) {
        return 'yaml';
    }

    if (preg_match('/\b(local|return|function|end|then)\b|app\.(integrations|mcp|tools)|docs\.(list|read|search)|dump\(/', $code)) {
        return 'lua';
    }

    if (str_contains($code, "\n") && preg_match('/^[A-Za-z0-9_.-]+:\s/m', $code)) {
        return 'yaml';
    }

    return 'plaintext';
}

function cleanDir(string $dir): void
{
    if (! is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
}

// ── Discover pages recursively (skip _ prefixed partials) ──

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    if (str_starts_with($file->getFilename(), '_')) continue;
    $files[] = $file->getPathname();
}
sort($files);

if (! $files) {
    die("No PHP files found in {$pagesDir}/\n");
}

// Clean and create output directory
cleanDir($htmlDir);
if (! is_dir($htmlDir)) {
    mkdir($htmlDir, 0755, true);
}

echo "Building...\n";

$typeExtensions = [
    'text/html' => '.html',
    'image/svg+xml' => '.svg',
    'application/json' => '.json',
    'text/plain' => '.txt',
];

foreach ($files as $file) {
    $relative = str_replace([$pagesDir . '/', '.php'], '', $file);
    $baseName = $relative;

    if ($relative === 'index') {
        $route = '/';
    } elseif (str_ends_with($relative, '/index')) {
        $route = '/' . dirname($relative);
    } else {
        $route = '/' . $relative;
    }

    ob_start();
    include $file;
    $output = ob_get_clean();

    // Detect content type
    $detectedType = 'text/html';
    $trimmed = ltrim($output);
    if (str_starts_with($trimmed, '<svg') || str_starts_with($trimmed, '<?xml')) {
        $detectedType = 'image/svg+xml';
    } elseif (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
        $detectedType = 'application/json';
    }

    $output = preg_replace('/^Content-Type:.*\n?/mi', '', $output);
    $output = ltrim($output);

    if ($detectedType === 'text/html') {
        $output = annotateCodeBlocks($output);
        $output = minifyHtml($output);
    }

    // Write static file
    $ext = $typeExtensions[$detectedType] ?? '.html';
    $staticDir = $htmlDir . '/' . dirname($baseName);
    if ($staticDir !== $htmlDir && ! is_dir($staticDir)) {
        mkdir($staticDir, 0755, true);
    }
    $staticFile = $htmlDir . '/' . $baseName . $ext;
    file_put_contents($staticFile, $output);

    $typeLabel = $detectedType === 'text/html' ? 'html' : $detectedType;
    echo "  {$route} ({$typeLabel}) -> " . basename($staticFile) . "\n";
}

// Count output
$htmlFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($htmlDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
$htmlCount = iterator_count($htmlFiles);
$totalSize = 0;
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($htmlDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
) as $f) {
    $totalSize += $f->getSize();
}

echo "\nOutput: html/ ({$htmlCount} files, " . number_format($totalSize / 1024, 1) . " KB)\n";

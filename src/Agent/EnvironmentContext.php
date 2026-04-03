<?php

namespace Kosmokrator\Agent;

/**
 * Gathers host and project metadata (OS, shell, git state, language/framework detection)
 * into a formatted string for inclusion in the system prompt.
 * Works alongside ProtectedContextBuilder which handles agent-mode-specific context.
 */
class EnvironmentContext
{
    /**
     * Collect and format the full environment context block.
     */
    public static function gather(): string
    {
        $lines = [];

        $lines[] = '# Environment';
        $lines[] = 'Working directory: '.getcwd();
        $lines[] = 'Platform: '.PHP_OS_FAMILY.' ('.php_uname('s').' '.php_uname('r').')';
        $lines[] = 'Shell: '.(getenv('SHELL') ?: getenv('COMSPEC') ?: 'unknown');
        $lines[] = "Today's date: ".date('Y-m-d');

        // Git info
        $gitBranch = self::exec('git rev-parse --abbrev-ref HEAD 2>/dev/null');
        if ($gitBranch !== '') {
            $lines[] = 'Git branch: '.$gitBranch;
            $gitRoot = self::exec('git rev-parse --show-toplevel 2>/dev/null');
            if ($gitRoot !== '') {
                $lines[] = 'Git root: '.$gitRoot;
            }
        } else {
            $lines[] = 'Git: not a repository';
        }

        // Project detection — language-agnostic
        $project = self::detectProject();
        if ($project !== []) {
            $lines[] = '';
            $lines[] = '# Project';
            foreach ($project as $line) {
                $lines[] = $line;
            }
        }

        return "\n\n".implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    private static function detectProject(): array
    {
        $cwd = getcwd();
        $info = [];

        // PHP / Composer
        if (file_exists($cwd.'/composer.json')) {
            $composer = self::readJson($cwd.'/composer.json');
            if ($composer !== null) {
                if (isset($composer['name'])) {
                    $info[] = "Name: {$composer['name']}";
                }
                if (isset($composer['description'])) {
                    $info[] = "Description: {$composer['description']}";
                }
                $info[] = 'Type: PHP (Composer)';
                $phpVersion = $composer['require']['php'] ?? null;
                if ($phpVersion) {
                    $info[] = "PHP constraint: {$phpVersion}";
                }
                // Detect framework
                if (isset($composer['require']['laravel/framework'])) {
                    $info[] = 'Framework: Laravel '.$composer['require']['laravel/framework'];
                } elseif (isset($composer['require']['symfony/framework-bundle'])) {
                    $info[] = 'Framework: Symfony';
                }
            }
        }

        // Node.js / JavaScript / TypeScript
        if (file_exists($cwd.'/package.json')) {
            $pkg = self::readJson($cwd.'/package.json');
            if ($pkg !== null) {
                if ($info === []) {
                    // Only set name/description if not already set by composer
                    if (isset($pkg['name'])) {
                        $info[] = "Name: {$pkg['name']}";
                    }
                    if (isset($pkg['description'])) {
                        $info[] = "Description: {$pkg['description']}";
                    }
                }
                $type = file_exists($cwd.'/tsconfig.json') ? 'TypeScript (Node.js)' : 'JavaScript (Node.js)';
                $info[] = "Type: {$type}";
                // Detect framework
                $deps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);
                if (isset($deps['next'])) {
                    $info[] = 'Framework: Next.js';
                } elseif (isset($deps['nuxt'])) {
                    $info[] = 'Framework: Nuxt';
                } elseif (isset($deps['react'])) {
                    $info[] = 'Framework: React';
                } elseif (isset($deps['vue'])) {
                    $info[] = 'Framework: Vue';
                } elseif (isset($deps['svelte'])) {
                    $info[] = 'Framework: Svelte';
                } elseif (isset($deps['express'])) {
                    $info[] = 'Framework: Express';
                }
            }
        }

        // Python
        if (file_exists($cwd.'/pyproject.toml') || file_exists($cwd.'/setup.py') || file_exists($cwd.'/requirements.txt')) {
            if ($info === []) {
                $info[] = 'Type: Python';
            } else {
                $info[] = 'Also: Python';
            }
            if (file_exists($cwd.'/pyproject.toml')) {
                $info[] = 'Build: pyproject.toml';
            }
        }

        // Rust
        if (file_exists($cwd.'/Cargo.toml')) {
            $info[] = $info === [] ? 'Type: Rust (Cargo)' : 'Also: Rust (Cargo)';
        }

        // Go
        if (file_exists($cwd.'/go.mod')) {
            $goMod = @file_get_contents($cwd.'/go.mod');
            if ($goMod !== false && preg_match('/^module\s+(\S+)/m', $goMod, $m)) {
                if ($info === []) {
                    $info[] = "Name: {$m[1]}";
                }
            }
            $info[] = $info === [] ? 'Type: Go' : 'Also: Go';
        }

        // Java / Kotlin
        if (file_exists($cwd.'/pom.xml')) {
            $info[] = $info === [] ? 'Type: Java (Maven)' : 'Also: Java (Maven)';
        } elseif (file_exists($cwd.'/build.gradle') || file_exists($cwd.'/build.gradle.kts')) {
            $info[] = $info === [] ? 'Type: Java/Kotlin (Gradle)' : 'Also: Java/Kotlin (Gradle)';
        }

        // Ruby
        if (file_exists($cwd.'/Gemfile')) {
            $info[] = $info === [] ? 'Type: Ruby' : 'Also: Ruby';
            if (file_exists($cwd.'/config/application.rb')) {
                $info[] = 'Framework: Rails';
            }
        }

        // .NET / C#
        if (glob($cwd.'/*.csproj') || glob($cwd.'/*.sln')) {
            $info[] = $info === [] ? 'Type: .NET (C#)' : 'Also: .NET (C#)';
        }

        // Elixir
        if (file_exists($cwd.'/mix.exs')) {
            $info[] = $info === [] ? 'Type: Elixir (Mix)' : 'Also: Elixir (Mix)';
        }

        return $info;
    }

    private static function readJson(string $path): ?array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private static function exec(string $command): string
    {
        return trim((string) shell_exec($command));
    }
}

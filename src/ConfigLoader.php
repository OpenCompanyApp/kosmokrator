<?php

declare(strict_types=1);

namespace Kosmokrator;

use Illuminate\Config\Repository;
use Kosmokrator\Settings\ConfigCompatibility;
use Kosmokrator\Settings\SettingsPaths;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and merges configuration from three layers: bundled defaults (config/*.yaml),
 * user-level overrides (~/.kosmo/config.yaml), and project-level overrides
 * (.kosmo/config.yaml walked up from cwd). Legacy .kosmokrator paths are read
 * for compatibility. Runs early in the boot sequence,
 * before any services are registered.
 */
class ConfigLoader
{
    /** @param string $configPath Absolute path to the bundled config/ directory */
    public function __construct(
        private readonly string $configPath,
    ) {}

    /**
     * Build the final config repository by merging bundled → user → project layers.
     *
     * @return Repository The fully merged config repository
     */
    public function load(): Repository
    {
        $config = [];

        foreach ($this->discoverFiles($this->configPath) as $key => $path) {
            $config[$key] = $this->parseYaml($path);
        }

        // Merge user config (~/.kosmo/config.yaml)
        $userConfig = $this->loadUserConfig();
        if ($userConfig !== null) {
            $config = $this->mergeDeep($config, $userConfig);
        }

        // Merge project config (.kosmo/config.yaml, .kosmo.yaml, and legacy .kosmokrator paths)
        $projectConfig = $this->loadProjectConfig();
        if ($projectConfig !== null) {
            $config = $this->mergeDeep($config, $projectConfig);
        }

        return new Repository($config);
    }

    /**
     * Discover all *.yaml files in a directory, keyed by filename without extension.
     *
     * @return array<string, string> Map of config key → absolute file path
     */
    private function discoverFiles(string $path): array
    {
        $files = [];

        foreach (glob($path.'/*.yaml') as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $files[$key] = $file;
        }

        return $files;
    }

    /** Parse a YAML file, resolving ${ENV_VAR} placeholders from the process environment. */
    private function parseYaml(string $path): array
    {
        $content = file_get_contents($path);

        // Resolve ${ENV_VAR} placeholders (check $_ENV, $_SERVER, then getenv)
        $content = preg_replace_callback('/\$\{(\w+)\}/', function (array $matches) {
            $value = $_ENV[$matches[1]] ?? $_SERVER[$matches[1]] ?? getenv($matches[1]);

            return $value !== false ? $value : null;
        }, $content);

        try {
            return Yaml::parse($content) ?? [];
        } catch (\Throwable $e) {
            error_log("ConfigLoader: Failed to parse YAML file {$path}: ".$e->getMessage());

            return [];
        }
    }

    /** Load user-level config from ~/.kosmo/config.yaml, falling back to legacy ~/.kosmokrator/config.yaml. */
    private function loadUserConfig(): ?array
    {
        $path = (new SettingsPaths)->globalReadPath();
        if ($path !== null) {
            return $this->normalizeExternalConfig($this->parseYaml($path));
        }

        return null;
    }

    /** Load and merge project-level configs by walking from cwd up to root. */
    private function loadProjectConfig(): ?array
    {
        $merged = null;
        foreach ($this->projectConfigCandidates() as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $parsed = $this->normalizeExternalConfig($this->parseYaml($path));
            $merged = $merged === null ? $parsed : $this->mergeDeep($merged, $parsed);
        }

        return $merged;
    }

    /**
     * Build candidate config file paths by walking from cwd to filesystem root,
     * producing outermost (root) paths first so deeper configs override.
     *
     * @return list<string>
     */
    private function projectConfigCandidates(): array
    {
        $cwd = getcwd() ?: '.';
        $dirs = [];
        $seen = [];
        $current = realpath($cwd) ?: $cwd;

        while ($current !== '' && ! isset($seen[$current])) {
            $seen[$current] = true;
            $dirs[] = $current;
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        $dirs = array_reverse($dirs);
        $paths = [];
        foreach ($dirs as $dir) {
            foreach (array_reverse(SettingsPaths::projectCandidatesForRoot($dir)) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Restructure a flat external config into the canonical namespace structure
     * (prism.providers, kosmo, relay) expected by the rest of the codebase.
     */
    private function normalizeExternalConfig(array $data): array
    {
        $data = ConfigCompatibility::normalizeKosmoNamespace($data);

        $knownRoots = ['app', 'kosmo', 'prism', 'models', 'relay'];
        $hasKnownRoot = array_intersect(array_keys($data), $knownRoots) !== [];

        if ($hasKnownRoot) {
            if (isset($data['providers']) && ! isset($data['prism']['providers'])) {
                $data['prism']['providers'] = $data['providers'];
                unset($data['providers']);
            }

            return $data;
        }

        $result = [];

        if (isset($data['providers'])) {
            $result['prism']['providers'] = $data['providers'];
            unset($data['providers']);
        }

        if (isset($data['relay'])) {
            $result['relay'] = is_array($data['relay']) ? $data['relay'] : [];
            unset($data['relay']);
        }

        if (! empty($data)) {
            $result['kosmo'] = $data;
        }

        return $result;
    }

    /** Recursively merge $override into $base; scalar values in $override win. */
    private function mergeDeep(array $base, array $override): array
    {
        return ConfigCompatibility::mergeDeep($base, $override);
    }
}

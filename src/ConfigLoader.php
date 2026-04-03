<?php

namespace Kosmokrator;

use Illuminate\Config\Repository;
use Symfony\Component\Yaml\Yaml;

class ConfigLoader
{
    public function __construct(
        private readonly string $configPath,
    ) {}

    public function load(): Repository
    {
        $config = [];

        foreach ($this->discoverFiles($this->configPath) as $key => $path) {
            $config[$key] = $this->parseYaml($path);
        }

        // Merge user config (~/.config/kosmokrator/config.yaml, legacy ~/.kosmokrator/config.yaml)
        $userConfig = $this->loadUserConfig();
        if ($userConfig !== null) {
            $config = $this->mergeDeep($config, $userConfig);
        }

        // Merge project config (.kosmokrator/config.yaml, legacy .kosmokrator.yaml in cwd)
        $projectConfig = $this->loadProjectConfig();
        if ($projectConfig !== null) {
            $config = $this->mergeDeep($config, $projectConfig);
        }

        return new Repository($config);
    }

    /**
     * @return array<string, string>
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

    private function parseYaml(string $path): array
    {
        $content = file_get_contents($path);

        // Resolve ${ENV_VAR} placeholders (check $_ENV, $_SERVER, then getenv)
        $content = preg_replace_callback('/\$\{(\w+)\}/', function (array $matches) {
            $value = $_ENV[$matches[1]] ?? $_SERVER[$matches[1]] ?? getenv($matches[1]);

            return $value !== false ? $value : '';
        }, $content);

        return Yaml::parse($content) ?? [];
    }

    private function loadUserConfig(): ?array
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        $paths = [
            $home.'/.kosmokrator/config.yaml',
            $home.'/.config/kosmokrator/config.yaml',
        ];

        $merged = null;
        foreach ($paths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $parsed = $this->normalizeExternalConfig($this->parseYaml($path));
            $merged = $merged === null ? $parsed : $this->mergeDeep($merged, $parsed);
        }

        return $merged;
    }

    private function loadProjectConfig(): ?array
    {
        $cwd = getcwd();
        $paths = [
            $cwd.'/.kosmokrator.yaml',
            $cwd.'/.kosmokrator/config.yaml',
        ];

        $merged = null;
        foreach ($paths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $parsed = $this->normalizeExternalConfig($this->parseYaml($path));
            $merged = $merged === null ? $parsed : $this->mergeDeep($merged, $parsed);
        }

        return $merged;
    }

    private function normalizeExternalConfig(array $data): array
    {
        $knownRoots = ['app', 'kosmokrator', 'prism', 'models', 'relay'];
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
            $result['kosmokrator'] = $data;
        }

        return $result;
    }

    private function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}

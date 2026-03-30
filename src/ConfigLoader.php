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

        // Merge user config (~/.kosmokrator/config.yaml)
        $userConfig = $this->loadUserConfig();
        if ($userConfig !== null) {
            $config = $this->mergeDeep($config, $userConfig);
        }

        // Merge project config (.kosmokrator.yaml in cwd)
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

        foreach (glob($path . '/*.yaml') as $file) {
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
        $path = $home . '/.kosmokrator/config.yaml';

        if (! file_exists($path)) {
            return null;
        }

        $userConfig = $this->parseYaml($path);

        $result = [];

        // Map user provider keys into prism config
        if (isset($userConfig['providers'])) {
            $result['prism']['providers'] = $userConfig['providers'];
            unset($userConfig['providers']);
        }

        // Everything else goes under kosmokrator.*
        if (! empty($userConfig)) {
            $result['kosmokrator'] = $userConfig;
        }

        return $result;
    }

    private function loadProjectConfig(): ?array
    {
        $path = getcwd() . '/.kosmokrator.yaml';

        if (! file_exists($path)) {
            return null;
        }

        return ['kosmokrator' => $this->parseYaml($path)];
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

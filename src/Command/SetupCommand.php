<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\UI\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'setup', description: 'Configure KosmoKrator (API keys, provider, model)')]
class SetupCommand extends Command
{
    private const CONFIG_DIR = '/.kosmokrator';
    private const CONFIG_FILE = '/.kosmokrator/config.yaml';

    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $home = $this->getHomeDir();
        $configPath = $home . self::CONFIG_FILE;
        $existing = $this->loadExisting($configPath);

        $r = Theme::reset();
        $white = Theme::white();
        $dim = Theme::text();
        $accent = Theme::accent();
        $primary = Theme::primary();

        echo "\n{$accent}  ⚡ KosmoKrator Setup{$r}\n\n";

        // Provider selection
        $providers = ['z' => 'Z.AI (GLM-5.1)', 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)'];
        $currentProvider = $existing['agent']['default_provider'] ?? 'z';

        echo "{$dim}  Available providers:{$r}\n";
        foreach ($providers as $key => $label) {
            $marker = $key === $currentProvider ? "{$accent}→{$r}" : ' ';
            echo "  {$marker} {$white}{$key}{$r} — {$dim}{$label}{$r}\n";
        }
        echo "\n";

        $provider = readline("{$dim}  Provider [{$currentProvider}]: {$r}") ?: $currentProvider;
        $provider = trim($provider);

        // Model selection
        $defaultModels = ['z' => 'GLM-5.1', 'anthropic' => 'claude-sonnet-4-20250514', 'openai' => 'gpt-4o'];
        $currentModel = $existing['agent']['default_model'] ?? ($defaultModels[$provider] ?? 'GLM-5.1');
        $model = readline("{$dim}  Model [{$currentModel}]: {$r}") ?: $currentModel;
        $model = trim($model);

        // API key
        $envKey = match ($provider) {
            'z' => 'ZAI_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            'openai' => 'OPENAI_API_KEY',
            default => strtoupper($provider) . '_API_KEY',
        };

        $currentKey = $existing['providers'][$provider]['api_key'] ?? '';
        $maskedKey = $currentKey !== '' ? substr($currentKey, 0, 8) . '...' . substr($currentKey, -4) : '';
        $keyPrompt = $maskedKey !== '' ? " [{$maskedKey}]" : '';

        $apiKey = readline("{$dim}  API key{$keyPrompt}: {$r}") ?: $currentKey;
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            echo "\n{$primary}  ✗ API key is required.{$r}\n\n";
            return Command::FAILURE;
        }

        // Build config
        $config = [
            'agent' => [
                'default_provider' => $provider,
                'default_model' => $model,
            ],
            'providers' => [
                $provider => [
                    'api_key' => $apiKey,
                ],
            ],
        ];

        // Write config
        $dir = $home . self::CONFIG_DIR;
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // Merge with existing config (preserve other keys)
        $merged = array_replace_recursive($existing, $config);
        file_put_contents($configPath, Yaml::dump($merged, 4, 2));
        chmod($configPath, 0600);

        echo "\n{$accent}  ✓ Config saved to {$configPath}{$r}\n";
        echo "{$dim}  Run {$white}php bin/kosmokrator{$dim} to start.{$r}\n\n";

        return Command::SUCCESS;
    }

    private function getHomeDir(): string
    {
        return getenv('HOME') ?: getenv('USERPROFILE') ?: '';
    }

    private function loadExisting(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        return Yaml::parseFile($path) ?? [];
    }
}

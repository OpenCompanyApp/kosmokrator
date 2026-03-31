<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\UI\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'setup', description: 'Configure KosmoKrator (API keys, provider, model)')]
class SetupCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsRepository::class);

        $r = Theme::reset();
        $white = Theme::white();
        $dim = Theme::text();
        $accent = Theme::accent();
        $primary = Theme::primary();

        echo "\n{$accent}  ⚡ KosmoKrator Setup{$r}\n\n";

        // Provider selection
        $providers = [
            'anthropic' => 'Anthropic (Claude)',
            'openai' => 'OpenAI (GPT)',
            'gemini' => 'Google Gemini',
            'deepseek' => 'DeepSeek',
            'groq' => 'Groq',
            'mistral' => 'Mistral AI',
            'xai' => 'xAI (Grok)',
            'openrouter' => 'OpenRouter',
            'perplexity' => 'Perplexity',
            'ollama' => 'Ollama (local)',
            'z' => 'Z.AI coding plan',
            'z-api' => 'Z.AI standard API',
        ];
        $currentProvider = $settings->get('global', 'agent.default_provider') ?? 'z';

        echo "{$dim}  Available providers:{$r}\n";
        foreach ($providers as $key => $label) {
            $marker = $key === $currentProvider ? "{$accent}→{$r}" : ' ';
            echo "  {$marker} {$white}{$key}{$r} — {$dim}{$label}{$r}\n";
        }
        echo "\n";

        $provider = readline("{$dim}  Provider [{$currentProvider}]: {$r}") ?: $currentProvider;
        $provider = trim($provider);

        // Model selection
        $defaultModels = [
            'anthropic' => 'claude-sonnet-4-20250514',
            'openai' => 'gpt-4o',
            'gemini' => 'gemini-2.5-pro',
            'deepseek' => 'deepseek-chat',
            'groq' => 'llama-3.3-70b-versatile',
            'mistral' => 'mistral-large-latest',
            'xai' => 'grok-3',
            'openrouter' => 'anthropic/claude-sonnet-4',
            'perplexity' => 'sonar-pro',
            'ollama' => 'llama3.1',
            'z' => 'GLM-5.1',
            'z-api' => 'GLM-5.1',
        ];
        $currentModel = $settings->get('global', 'agent.default_model') ?? ($defaultModels[$provider] ?? 'GLM-5.1');
        $model = readline("{$dim}  Model [{$currentModel}]: {$r}") ?: $currentModel;
        $model = trim($model);

        // API key
        $currentKey = $settings->get('global', "provider.{$provider}.api_key") ?? '';
        $maskedKey = $currentKey !== '' ? substr($currentKey, 0, 8).'...'.substr($currentKey, -4) : '';
        $keyPrompt = $maskedKey !== '' ? " [{$maskedKey}]" : '';

        $apiKey = readline("{$dim}  API key{$keyPrompt}: {$r}") ?: $currentKey;
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            echo "\n{$primary}  ✗ API key is required.{$r}\n\n";

            return Command::FAILURE;
        }

        // Save to SQLite
        $settings->set('global', 'agent.default_provider', $provider);
        $settings->set('global', 'agent.default_model', $model);
        $settings->set('global', "provider.{$provider}.api_key", $apiKey);

        echo "\n{$accent}  ✓ Settings saved.{$r}\n";
        echo "{$dim}  Run {$white}php bin/kosmokrator{$dim} to start.{$r}\n\n";

        return Command::SUCCESS;
    }
}

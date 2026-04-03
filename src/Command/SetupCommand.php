<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\UI\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive first-run wizard for selecting a provider, model, and authentication method.
 */
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
        $configSettings = $this->container->make(SettingsManager::class);
        $configSettings->setProjectRoot(\Kosmokrator\Agent\InstructionLoader::gitRoot() ?? getcwd());
        $providers = $this->container->make(ProviderCatalog::class);
        $codexAuth = $this->container->make(CodexAuthFlow::class);

        $r = Theme::reset();
        $white = Theme::white();
        $dim = Theme::text();
        $accent = Theme::accent();
        $primary = Theme::primary();

        echo "\n{$accent}  ⚡ KosmoKrator Setup{$r}\n\n";

        $currentProvider = $configSettings->get('agent.default_provider')
            ?? (string) $this->container->make('config')->get('kosmokrator.agent.default_provider', 'z');

        echo "{$dim}  Available providers:{$r}\n";
        foreach ($providers->providers() as $definition) {
            $marker = $definition->id === $currentProvider ? "{$accent}→{$r}" : ' ';
            $status = $providers->authStatus($definition->id);
            echo "  {$marker} {$white}{$definition->id}{$r} — {$dim}{$definition->description} · {$status}{$r}\n";
        }
        echo "\n";

        $provider = readline("{$dim}  Provider [{$currentProvider}]: {$r}") ?: $currentProvider;
        $provider = trim($provider);
        $definition = $providers->provider($provider);

        if ($definition === null) {
            echo "\n{$primary}  ✗ Unknown provider: {$provider}{$r}\n\n";

            return Command::FAILURE;
        }

        echo "{$dim}  {$definition->label}: {$definition->description}{$r}\n";
        echo "{$dim}  Endpoint: {$definition->url}{$r}\n";
        echo "{$dim}  Auth: {$providers->authStatus($provider)}{$r}\n\n";

        $providerModels = array_map(static fn ($model) => $model->id, $definition->models);
        echo "{$dim}  Available models for {$white}{$provider}{$r}{$dim}:{$r}\n";
        foreach ($definition->models as $model) {
            $thinking = $model->thinking ? ' · thinking' : '';
            echo "    {$white}{$model->id}{$r}{$dim} — {$model->displayName} · {$model->contextWindow} ctx · {$model->maxOutput} out{$thinking}{$r}\n";
        }
        echo "\n";

        $savedModel = $configSettings->get('agent.default_model');
        $providerSavedModel = $configSettings->getProviderLastModel($provider);
        $currentModel = $providerSavedModel ?? $savedModel;
        if ($currentModel === null || ! $providers->supportsModel($provider, $currentModel)) {
            $currentModel = $definition->defaultModel !== '' ? $definition->defaultModel : ($providerModels[0] ?? '');
        }
        $model = readline("{$dim}  Model [{$currentModel}]: {$r}") ?: $currentModel;
        $model = trim($model);

        $configSettings->set('agent.default_provider', $provider, 'global');
        $configSettings->set('agent.default_model', $model, 'global');
        $configSettings->setProviderLastModel($provider, $model, 'global');

        if ($definition->authMode === 'oauth') {
            echo "{$dim}  Codex uses your ChatGPT login, not an API key.{$r}\n";
            $action = strtolower(trim(readline("{$dim}  Authenticate now? [browser/device/skip] {$r}")));

            try {
                if ($action === 'browser' || $action === '') {
                    $token = $codexAuth->browserLogin(fn (string $message) => $output->writeln("  {$message}"));
                    echo "\n{$accent}  ✓ Codex authenticated for {$white}".($token->email ?? 'your ChatGPT account')."{$r}\n";
                } elseif ($action === 'device') {
                    $token = $codexAuth->deviceLogin(fn (string $message) => $output->writeln("  {$message}"));
                    echo "\n{$accent}  ✓ Codex authenticated for {$white}".($token->email ?? 'your ChatGPT account')."{$r}\n";
                } else {
                    echo "{$dim}  Skipped authentication. Run {$white}kosmokrator codex:login{$dim} later if needed.{$r}\n";
                }
            } catch (\Throwable $e) {
                echo "\n{$primary}  ✗ {$e->getMessage()}{$r}\n";
                echo "{$dim}  You can retry later with {$white}kosmokrator codex:login{$dim}.{$r}\n";
            }

            echo "\n{$accent}  ✓ Settings saved.{$r}\n";
            echo "{$dim}  Run {$white}php bin/kosmokrator{$dim} to start.{$r}\n\n";

            return Command::SUCCESS;
        }

        if ($definition->authMode === 'api_key') {
            $currentKey = $providers->apiKey($provider);
            $maskedKey = $currentKey !== '' ? substr($currentKey, 0, 8).'...'.substr($currentKey, -4) : '';
            $keyPrompt = $maskedKey !== '' ? " [{$maskedKey}]" : '';

            $apiKey = readline("{$dim}  API key{$keyPrompt}: {$r}") ?: $currentKey;
            $apiKey = trim($apiKey);

            if ($apiKey === '') {
                echo "\n{$primary}  ✗ API key is required.{$r}\n\n";

                return Command::FAILURE;
            }

            $settings->set('global', "provider.{$provider}.api_key", $apiKey);
        }

        echo "\n{$accent}  ✓ Settings saved.{$r}\n";
        echo "{$dim}  Run {$white}php bin/kosmokrator{$dim} to start.{$r}\n\n";

        return Command::SUCCESS;
    }
}

<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\ProviderConfigurator;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\UI\Theme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive first-run wizard for selecting a provider, model, and authentication method.
 */
#[AsCommand(name: 'setup', description: 'Configure KosmoKrator (API keys, provider, model)')]
class SetupCommand extends Command
{
    use InteractsWithHeadlessOutput;

    /** @var (callable(string): string)|null */
    private $promptReader;

    public function __construct(
        private readonly Container $container,
        ?callable $promptReader = null,
    ) {
        $this->promptReader = $promptReader;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsRepositoryInterface::class);
        $configSettings = $this->container->make(SettingsManager::class);
        $configSettings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $providers = $this->container->make(ProviderCatalog::class);
        $codexAuth = $this->container->make(CodexAuthFlow::class);

        if ($this->isHeadlessSetup($input)) {
            return $this->headlessSetup($input, $output, $configSettings, $providers, $codexAuth);
        }

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

        $provider = $this->prompt("{$dim}  Provider [{$currentProvider}]: {$r}") ?: $currentProvider;
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
        $model = $this->prompt("{$dim}  Model [{$currentModel}]: {$r}") ?: $currentModel;
        $model = trim($model);

        $configSettings->set('agent.default_provider', $provider, 'global');
        $configSettings->set('agent.default_model', $model, 'global');
        $configSettings->setProviderLastModel($provider, $model, 'global');

        if ($definition->authMode === 'oauth') {
            echo "{$dim}  Codex uses your ChatGPT login, not an API key.{$r}\n";
            $action = strtolower(trim($this->prompt("{$dim}  Authenticate now? [browser/device/skip] {$r}")));

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

            $apiKey = $this->prompt("{$dim}  API key{$keyPrompt}: {$r}") ?: $currentKey;
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

    protected function configure(): void
    {
        $this
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider ID')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model ID')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for api_key providers')
            ->addOption('api-key-stdin', null, InputOption::VALUE_NONE, 'Read API key from stdin')
            ->addOption('api-key-env', null, InputOption::VALUE_REQUIRED, 'Read API key from an environment variable')
            ->addOption('device', null, InputOption::VALUE_NONE, 'Use device OAuth for oauth providers')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project config')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    private function isHeadlessSetup(InputInterface $input): bool
    {
        return $input->getOption('provider') !== null
            || $input->getOption('model') !== null
            || $input->getOption('api-key') !== null
            || $input->getOption('api-key-stdin')
            || $input->getOption('api-key-env') !== null
            || $input->getOption('device')
            || $input->getOption('json')
            || $input->getOption('no-interaction');
    }

    private function headlessSetup(
        InputInterface $input,
        OutputInterface $output,
        SettingsManager $settings,
        ProviderCatalog $providers,
        CodexAuthFlow $codexAuth,
    ): int {
        $provider = (string) ($input->getOption('provider') ?: $settings->get('agent.default_provider') ?: 'z');
        $definition = $providers->provider($provider);
        if ($definition === null) {
            return $this->headlessFail($input, $output, "Unknown provider [{$provider}].");
        }

        try {
            if ($definition->authMode === 'oauth' && $input->getOption('device')) {
                $token = $codexAuth->deviceLogin(fn (string $message) => $output->writeln($message));
                $settings->set('agent.default_provider', $provider, $this->scope($input));
                if (is_string($input->getOption('model')) && $input->getOption('model') !== '') {
                    $settings->set('agent.default_model', (string) $input->getOption('model'), $this->scope($input));
                }
                $result = [
                    'success' => true,
                    'provider' => $provider,
                    'authenticated' => true,
                    'account' => $token->email ?? $token->accountId,
                ];
            } else {
                $apiKey = $this->resolveSecretOption(
                    inline: is_string($input->getOption('api-key')) ? $input->getOption('api-key') : null,
                    stdin: (bool) $input->getOption('api-key-stdin'),
                    env: is_string($input->getOption('api-key-env')) ? $input->getOption('api-key-env') : null,
                );
                $result = ['success' => true] + $this->container->make(ProviderConfigurator::class)->configure(
                    provider: $provider,
                    model: is_string($input->getOption('model')) ? $input->getOption('model') : null,
                    apiKey: $apiKey,
                    scope: $this->scope($input),
                );
            }
        } catch (\Throwable $e) {
            return $this->headlessFail($input, $output, $e->getMessage());
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, $result);
        } else {
            $output->writeln("<info>Configured {$provider}.</info>");
        }

        return Command::SUCCESS;
    }

    private function headlessFail(InputInterface $input, OutputInterface $output, string $message): int
    {
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => false, 'error' => $message]);
        } else {
            $output->writeln("<error>{$message}</error>");
        }

        return Command::FAILURE;
    }

    private function resolveSecretOption(?string $inline, bool $stdin, ?string $env): ?string
    {
        if ($stdin) {
            $value = trim((string) stream_get_contents(STDIN));

            return $value === '' ? null : $value;
        }

        if ($env !== null && $env !== '') {
            $value = getenv($env);

            return is_string($value) && $value !== '' ? $value : null;
        }

        return $inline;
    }

    private function prompt(string $message): string
    {
        if ($this->promptReader !== null) {
            return (string) ($this->promptReader)($message);
        }

        if (\function_exists('readline')) {
            $input = \readline($message);

            return $input === false ? '' : $input;
        }

        echo $message;

        $input = fgets(STDIN);

        return $input === false ? '' : rtrim($input, "\r\n");
    }
}

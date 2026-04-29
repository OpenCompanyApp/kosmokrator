<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\ProviderConfigurator;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Setup\SetupFlowInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'setup', description: 'Open setup-focused settings for provider and model configuration')]
class SetupCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('renderer', null, InputOption::VALUE_REQUIRED, 'Force renderer (tui or ansi)', 'auto')
            ->addOption('no-animation', null, InputOption::VALUE_NONE, 'Skip any intro animation before opening setup')
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->isHeadlessSetup($input)) {
            $settings = $this->container->make(SettingsManager::class);
            $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());

            return $this->headlessSetup(
                $input,
                $output,
                $settings,
                $this->container->make(ProviderCatalog::class),
                $this->container->make(CodexAuthFlow::class),
            );
        }

        $completed = $this->container->make(SetupFlowInterface::class)->open(
            rendererPref: (string) $input->getOption('renderer'),
            animated: ! $input->getOption('no-animation'),
            showIntro: false,
            notice: 'Open settings to configure your default provider, model, and credentials.',
        );

        if (! $completed) {
            $output->writeln('<error>Setup incomplete. Configure a provider before continuing.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Setup complete. Run `kosmokrator` to start.</info>');

        return Command::SUCCESS;
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
            || ($input->hasOption('no-interaction') && $input->getOption('no-interaction'));
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
}

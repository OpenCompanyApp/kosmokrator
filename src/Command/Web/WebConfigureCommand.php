<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Web;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Web\WebProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'web:configure', description: 'Configure an optional web provider headlessly')]
final class WebConfigureCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider name')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key to store in the encrypted settings database')
            ->addOption('api-key-stdin', null, InputOption::VALUE_NONE, 'Read API key from stdin')
            ->addOption('api-key-env', null, InputOption::VALUE_REQUIRED, 'Environment variable name to read at runtime')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Provider base URL')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable this provider')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable this provider')
            ->addOption('search', null, InputOption::VALUE_NONE, 'Use this provider as web.search.provider and enable search')
            ->addOption('fetch', null, InputOption::VALUE_NONE, 'Use this provider as web.fetch.provider and allow external fetch')
            ->addOption('crawl', null, InputOption::VALUE_NONE, 'Use this provider as web.crawl.provider and enable crawl')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project config')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->container->make(WebProviderRegistry::class);
        $provider = str_replace('-', '_', strtolower((string) $input->getArgument('provider')));
        if (! in_array($provider, $registry->names(), true)) {
            return $this->fail($input, $output, "Unknown web provider [{$provider}].");
        }

        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $scope = $this->scope($input);

        $secret = $this->resolveSecretOption(
            inline: is_string($input->getOption('api-key')) ? $input->getOption('api-key') : null,
            stdin: (bool) $input->getOption('api-key-stdin'),
        );

        if ($secret !== null && $secret !== '') {
            $this->container->make(SettingsRepositoryInterface::class)->set('global', "provider.{$provider}.api_key", $secret);
        }
        if (is_string($input->getOption('api-key-env')) && $input->getOption('api-key-env') !== '') {
            $settings->setRaw("kosmokrator.web.providers.{$provider}.api_key_env", $input->getOption('api-key-env'), $scope);
        }
        if (is_string($input->getOption('base-url')) && $input->getOption('base-url') !== '') {
            $settings->setRaw("kosmokrator.web.providers.{$provider}.base_url", $input->getOption('base-url'), $scope);
        }

        if ($input->getOption('enable') || (! $input->getOption('disable') && ($input->getOption('search') || $input->getOption('fetch') || $input->getOption('crawl')))) {
            $settings->setRaw("kosmokrator.web.providers.{$provider}.enabled", 'on', $scope);
        }
        if ($input->getOption('disable')) {
            $settings->setRaw("kosmokrator.web.providers.{$provider}.enabled", 'off', $scope);
        }
        if ($input->getOption('search')) {
            $settings->setRaw('kosmokrator.web.search.enabled', 'on', $scope);
            $settings->setRaw('kosmokrator.web.search.provider', $provider, $scope);
        }
        if ($input->getOption('fetch')) {
            $settings->setRaw('kosmokrator.web.fetch.allow_external', 'on', $scope);
            $settings->setRaw('kosmokrator.web.fetch.provider', $provider, $scope);
        }
        if ($input->getOption('crawl')) {
            $settings->setRaw('kosmokrator.web.crawl.enabled', 'on', $scope);
            $settings->setRaw('kosmokrator.web.crawl.provider', $provider, $scope);
        }

        $payload = ['success' => true, 'provider' => $provider, 'scope' => $scope, 'stored_secret' => $secret !== null && $secret !== ''];
        $input->getOption('json') ? $this->writeJson($output, $payload) : $output->writeln("<info>Configured web provider {$provider}.</info>");

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }

    private function resolveSecretOption(?string $inline, bool $stdin): ?string
    {
        if ($stdin) {
            $value = trim((string) stream_get_contents(STDIN));

            return $value === '' ? null : $value;
        }

        return $inline;
    }
}

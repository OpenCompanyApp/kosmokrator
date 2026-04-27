<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Provider;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'providers:logout', description: 'Clear provider credentials')]
final class ProvidersLogoutCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = (string) $input->getArgument('provider');
        $catalog = $this->container->make(ProviderCatalog::class);
        $definition = $catalog->provider($provider);
        if ($definition === null) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => "Unknown provider [{$provider}]."]);
            } else {
                $output->writeln("<error>Unknown provider [{$provider}].</error>");
            }

            return Command::FAILURE;
        }

        $authMode = $definition->authMode;

        if ($authMode === 'oauth') {
            $this->container->make(CodexAuthFlow::class)->logout();
        } elseif ($authMode === 'api_key') {
            $this->container->make(SettingsRepositoryInterface::class)->delete('global', "provider.{$provider}.api_key");
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'provider' => $provider, 'auth_mode' => $authMode]);
        } else {
            $output->writeln("<info>Cleared credentials for {$provider}.</info>");
        }

        return Command::SUCCESS;
    }
}

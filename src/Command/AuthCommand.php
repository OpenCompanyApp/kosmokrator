<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'auth', description: 'Manage provider authentication')]
final class AuthCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'status|login|logout', 'status')
            ->addArgument('provider', InputArgument::OPTIONAL, 'Provider ID')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for api_key providers')
            ->addOption('device', null, InputOption::VALUE_NONE, 'Use device auth for oauth providers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->container->make(ProviderCatalog::class);
        $settings = $this->container->make(SettingsRepository::class);
        $codex = $this->container->make(CodexAuthFlow::class);

        $action = (string) $input->getArgument('action');
        $provider = (string) ($input->getArgument('provider') ?: '');

        return match ($action) {
            'status' => $this->status($catalog, $output, $provider),
            'login' => $this->login($catalog, $settings, $codex, $input, $output, $provider),
            'logout' => $this->logout($catalog, $settings, $codex, $output, $provider),
            default => Command::INVALID,
        };
    }

    private function status(ProviderCatalog $catalog, OutputInterface $output, string $provider): int
    {
        if ($provider !== '') {
            $output->writeln(sprintf('%s: %s', $provider, $catalog->authStatus($provider)));

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($catalog->providers() as $definition) {
            $rows[] = [$definition->id, $definition->authMode, $catalog->authStatus($definition->id)];
        }

        (new Table($output))
            ->setHeaders(['Provider', 'Auth Mode', 'Status'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }

    private function login(ProviderCatalog $catalog, SettingsRepository $settings, CodexAuthFlow $codex, InputInterface $input, OutputInterface $output, string $provider): int
    {
        if ($provider === '') {
            $output->writeln('<error>Provide a provider name.</error>');

            return Command::INVALID;
        }

        $authMode = $catalog->authMode($provider);
        if ($authMode === 'oauth') {
            try {
                $token = $input->getOption('device')
                    ? $codex->deviceLogin(fn (string $message) => $output->writeln($message))
                    : $codex->browserLogin(fn (string $message) => $output->writeln($message));
            } catch (\Throwable $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");

                return Command::FAILURE;
            }

            $output->writeln(sprintf('<info>Authenticated %s</info>', $token->email ?? $provider));

            return Command::SUCCESS;
        }

        if ($authMode !== 'api_key') {
            $output->writeln('<comment>This provider does not require login.</comment>');

            return Command::SUCCESS;
        }

        $key = (string) ($input->getOption('api-key') ?: readline("API key for {$provider}: "));
        $key = trim($key);
        if ($key === '') {
            $output->writeln('<error>No API key provided.</error>');

            return Command::FAILURE;
        }

        $settings->set('global', "provider.{$provider}.api_key", $key);
        $output->writeln(sprintf('<info>Stored API key for %s.</info>', $provider));

        return Command::SUCCESS;
    }

    private function logout(ProviderCatalog $catalog, SettingsRepository $settings, CodexAuthFlow $codex, OutputInterface $output, string $provider): int
    {
        if ($provider === '') {
            $output->writeln('<error>Provide a provider name.</error>');

            return Command::INVALID;
        }

        $authMode = $catalog->authMode($provider);
        if ($authMode === 'oauth') {
            $codex->logout();
            $output->writeln(sprintf('<info>Logged out from %s.</info>', $provider));

            return Command::SUCCESS;
        }

        if ($authMode === 'api_key') {
            $settings->delete('global', "provider.{$provider}.api_key");
            $output->writeln(sprintf('<info>Removed API key for %s.</info>', $provider));

            return Command::SUCCESS;
        }

        $output->writeln('<comment>This provider does not require auth.</comment>');

        return Command::SUCCESS;
    }
}

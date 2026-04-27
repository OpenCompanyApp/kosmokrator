<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manages provider authentication — shows status, performs login (API key or OAuth), and logout.
 */
#[AsCommand(name: 'auth', description: 'Manage provider authentication')]
final class AuthCommand extends Command
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
            ->addArgument('action', InputArgument::OPTIONAL, 'status|login|logout', 'status')
            ->addArgument('provider', InputArgument::OPTIONAL, 'Provider ID')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for api_key providers')
            ->addOption('api-key-stdin', null, InputOption::VALUE_NONE, 'Read API key from stdin')
            ->addOption('api-key-env', null, InputOption::VALUE_REQUIRED, 'Read API key from an environment variable')
            ->addOption('device', null, InputOption::VALUE_NONE, 'Use device auth for oauth providers')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->container->make(ProviderCatalog::class);
        $settings = $this->container->make(SettingsRepositoryInterface::class);
        $codex = $this->container->make(CodexAuthFlow::class);

        $action = (string) $input->getArgument('action');
        $provider = (string) ($input->getArgument('provider') ?: '');

        return match ($action) {
            'status' => $this->status($catalog, $input, $output, $provider),
            'login' => $this->login($catalog, $settings, $codex, $input, $output, $provider),
            'logout' => $this->logout($catalog, $settings, $codex, $input, $output, $provider),
            default => $this->fail($input, $output, "Unknown auth action [{$action}].", Command::INVALID),
        };
    }

    /**
     * Displays authentication status for one or all providers.
     */
    private function status(ProviderCatalog $catalog, InputInterface $input, OutputInterface $output, string $provider): int
    {
        if ($provider !== '') {
            $definition = $catalog->provider($provider);
            if ($definition === null) {
                return $this->fail($input, $output, "Unknown provider [{$provider}].");
            }

            if ($input->getOption('json')) {
                $this->writeJson($output, [
                    'success' => true,
                    'provider' => $provider,
                    'auth_mode' => $definition->authMode,
                    'auth_status' => $catalog->authStatus($provider),
                ]);

                return Command::SUCCESS;
            }

            $output->writeln(sprintf('%s: %s', $provider, $catalog->authStatus($provider)));

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($catalog->providers() as $definition) {
            $rows[] = [$definition->id, $definition->authMode, $catalog->authStatus($definition->id)];
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'success' => true,
                'providers' => array_map(
                    static fn (array $row): array => [
                        'id' => $row[0],
                        'auth_mode' => $row[1],
                        'auth_status' => $row[2],
                    ],
                    $rows,
                ),
            ]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Provider', 'Auth Mode', 'Status'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }

    /**
     * Authenticates a provider via OAuth browser/device flow or stores an API key.
     */
    private function login(ProviderCatalog $catalog, SettingsRepositoryInterface $settings, CodexAuthFlow $codex, InputInterface $input, OutputInterface $output, string $provider): int
    {
        if ($provider === '') {
            return $this->fail($input, $output, 'Provide a provider name.', Command::INVALID);
        }

        $definition = $catalog->provider($provider);
        if ($definition === null) {
            return $this->fail($input, $output, "Unknown provider [{$provider}].");
        }

        $authMode = $definition->authMode;
        if ($authMode === 'oauth') {
            try {
                $token = $input->getOption('device')
                    ? $codex->deviceLogin(fn (string $message) => $output->writeln($message))
                    : $codex->browserLogin(fn (string $message) => $output->writeln($message));
            } catch (\Throwable $e) {
                return $this->fail($input, $output, $e->getMessage());
            }

            if ($input->getOption('json')) {
                $this->writeJson($output, [
                    'success' => true,
                    'provider' => $provider,
                    'auth_mode' => $authMode,
                    'account' => $token->email ?? $token->accountId ?? null,
                ]);

                return Command::SUCCESS;
            }

            $output->writeln(sprintf('<info>Authenticated %s</info>', $token->email ?? $provider));

            return Command::SUCCESS;
        }

        if ($authMode !== 'api_key') {
            if ($input->getOption('json')) {
                $this->writeJson($output, [
                    'success' => true,
                    'provider' => $provider,
                    'auth_mode' => $authMode,
                    'message' => 'This provider does not require login.',
                ]);

                return Command::SUCCESS;
            }

            $output->writeln('<comment>This provider does not require login.</comment>');

            return Command::SUCCESS;
        }

        $key = $this->resolveSecretOption(
            inline: is_string($input->getOption('api-key')) ? $input->getOption('api-key') : null,
            stdin: (bool) $input->getOption('api-key-stdin'),
            env: is_string($input->getOption('api-key-env')) ? $input->getOption('api-key-env') : null,
        );

        if (($key === null || $key === '') && ! $input->getOption('json')) {
            $key = readline("API key for {$provider}: ");
        }

        $key = trim($key ?? '');
        if ($key === '') {
            return $this->fail($input, $output, 'No API key provided.');
        }

        $settings->set('global', "provider.{$provider}.api_key", $key);
        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'success' => true,
                'provider' => $provider,
                'auth_mode' => $authMode,
                'auth_status' => $catalog->authStatus($provider),
            ]);

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Stored API key for %s.</info>', $provider));

        return Command::SUCCESS;
    }

    /**
     * Removes stored credentials for a provider (OAuth token or API key).
     */
    private function logout(ProviderCatalog $catalog, SettingsRepositoryInterface $settings, CodexAuthFlow $codex, InputInterface $input, OutputInterface $output, string $provider): int
    {
        if ($provider === '') {
            return $this->fail($input, $output, 'Provide a provider name.', Command::INVALID);
        }

        $definition = $catalog->provider($provider);
        if ($definition === null) {
            return $this->fail($input, $output, "Unknown provider [{$provider}].");
        }

        $authMode = $definition->authMode;
        if ($authMode === 'oauth') {
            $codex->logout();
            if ($input->getOption('json')) {
                $this->writeJson($output, [
                    'success' => true,
                    'provider' => $provider,
                    'auth_mode' => $authMode,
                ]);

                return Command::SUCCESS;
            }

            $output->writeln(sprintf('<info>Logged out from %s.</info>', $provider));

            return Command::SUCCESS;
        }

        if ($authMode === 'api_key') {
            $settings->delete('global', "provider.{$provider}.api_key");
            if ($input->getOption('json')) {
                $this->writeJson($output, [
                    'success' => true,
                    'provider' => $provider,
                    'auth_mode' => $authMode,
                ]);

                return Command::SUCCESS;
            }

            $output->writeln(sprintf('<info>Removed API key for %s.</info>', $provider));

            return Command::SUCCESS;
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'success' => true,
                'provider' => $provider,
                'auth_mode' => $authMode,
                'message' => 'This provider does not require auth.',
            ]);

            return Command::SUCCESS;
        }

        $output->writeln('<comment>This provider does not require auth.</comment>');

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message, int $code = Command::FAILURE): int
    {
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => false, 'error' => $message]);
        } else {
            $output->writeln("<error>{$message}</error>");
        }

        return $code;
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

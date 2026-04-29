<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Gateway;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SecretStore;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\SettingValueParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gateway:telegram:configure', description: 'Configure Telegram gateway headlessly')]
final class TelegramConfigureCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Telegram bot token')
            ->addOption('token-stdin', null, InputOption::VALUE_NONE, 'Read Telegram bot token from stdin')
            ->addOption('token-env', null, InputOption::VALUE_REQUIRED, 'Read Telegram bot token from an environment variable')
            ->addOption('enabled', null, InputOption::VALUE_REQUIRED, 'on/off')
            ->addOption('session-mode', null, InputOption::VALUE_REQUIRED, 'thread|thread_user|chat|chat_user')
            ->addOption('allowed-users', null, InputOption::VALUE_REQUIRED, 'Comma/space-separated users')
            ->addOption('allowed-chats', null, InputOption::VALUE_REQUIRED, 'Comma/space-separated chats')
            ->addOption('require-mention', null, InputOption::VALUE_REQUIRED, 'on/off')
            ->addOption('free-response-chats', null, InputOption::VALUE_REQUIRED, 'Comma/space-separated chats')
            ->addOption('poll-timeout-seconds', null, InputOption::VALUE_REQUIRED, 'Poll timeout')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project config')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsManager::class);
        $schema = $this->container->make(SettingsSchema::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $scope = $this->scope($input);
        $parser = new SettingValueParser;
        $map = [
            'enabled' => 'gateway.telegram.enabled',
            'session-mode' => 'gateway.telegram.session_mode',
            'allowed-users' => 'gateway.telegram.allowed_users',
            'allowed-chats' => 'gateway.telegram.allowed_chats',
            'require-mention' => 'gateway.telegram.require_mention',
            'free-response-chats' => 'gateway.telegram.free_response_chats',
            'poll-timeout-seconds' => 'gateway.telegram.poll_timeout_seconds',
        ];
        $updated = [];

        try {
            foreach ($map as $option => $key) {
                $value = $input->getOption($option);
                if (! is_string($value)) {
                    continue;
                }
                $definition = $schema->definition($key);
                if ($definition === null) {
                    continue;
                }
                $parsed = $parser->parse($definition, $value);
                $settings->set($key, $parsed, $scope);
                $updated[$key] = $parsed;
            }

            $token = $this->resolveSecretOption(
                inline: is_string($input->getOption('token')) ? $input->getOption('token') : null,
                stdin: (bool) $input->getOption('token-stdin'),
                env: is_string($input->getOption('token-env')) ? $input->getOption('token-env') : null,
            );
            if ($token !== null && $token !== '') {
                $this->container->make(SecretStore::class)->set('gateway.telegram.token', $token);
                $updated['gateway.telegram.token'] = 'configured';
            }
        } catch (\Throwable $e) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['success' => false, 'error' => $e->getMessage()]);
            } else {
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'scope' => $scope, 'updated' => $updated]);
        } else {
            $output->writeln('<info>Telegram gateway configuration updated.</info>');
        }

        return Command::SUCCESS;
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

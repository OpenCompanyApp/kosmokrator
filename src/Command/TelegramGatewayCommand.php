<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Gateway\GatewayApprovalStore;
use Kosmokrator\Gateway\GatewayCheckpointStore;
use Kosmokrator\Gateway\GatewayMessageStore;
use Kosmokrator\Gateway\GatewayPendingInputStore;
use Kosmokrator\Gateway\GatewaySessionStore;
use Kosmokrator\Gateway\Telegram\SymfonyProcessTelegramWorkerLauncher;
use Kosmokrator\Gateway\Telegram\TelegramClient;
use Kosmokrator\Gateway\Telegram\TelegramGatewayConfig;
use Kosmokrator\Gateway\Telegram\TelegramGatewayRuntime;
use Kosmokrator\Gateway\Telegram\TelegramPollerLock;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gateway:telegram', description: 'Run the Telegram gateway worker')]
final class TelegramGatewayCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $settingsRepository = $this->container->make(SettingsRepositoryInterface::class);
        $config = TelegramGatewayConfig::fromSettings($settings, $this->container->make('config'), $settingsRepository);
        $secretToken = $settingsRepository->get('global', 'gateway.telegram.token');
        if (is_string($secretToken) && $secretToken !== '') {
            $config = new TelegramGatewayConfig(
                enabled: $config->enabled,
                token: $secretToken,
                sessionMode: $config->sessionMode,
                allowedUsers: $config->allowedUsers,
                allowedChats: $config->allowedChats,
                requireMention: $config->requireMention,
                freeResponseChats: $config->freeResponseChats,
                pollTimeoutSeconds: $config->pollTimeoutSeconds,
                adminUsers: $config->adminUsers,
                replyToMode: $config->replyToMode,
                disableLinkPreviews: $config->disableLinkPreviews,
                freshFinalAfterSeconds: $config->freshFinalAfterSeconds,
                progressNoticeIntervalSeconds: $config->progressNoticeIntervalSeconds,
                reactions: $config->reactions,
            );
        }

        try {
            $config->validate();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        try {
            $lock = TelegramPollerLock::acquire($config->token);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $client = new TelegramClient($this->container->make('http'), $config->token, $config->disableLinkPreviews);
        $me = $client->getMe();
        $botUsername = ltrim((string) ($me['username'] ?? ''), '@');
        $checkpoint = $this->container->make(GatewayCheckpointStore::class)->get('telegram', 'last_update_id') ?? 'none';

        $output->writeln('<info>Starting Telegram gateway…</info>');
        $output->writeln(sprintf('  Bot: @%s', $botUsername !== '' ? $botUsername : 'unknown'));
        $output->writeln(sprintf('  Session mode: %s', $config->sessionMode));
        $output->writeln(sprintf('  Mention gating: %s', $config->requireMention ? 'required in groups' : 'off'));
        $output->writeln(sprintf('  Allowed users: %s', $config->allowedUsers === [] ? 'all' : (string) count($config->allowedUsers)));
        $output->writeln(sprintf('  Allowed chats: %s', $config->allowedChats === [] ? 'all' : (string) count($config->allowedChats)));
        $output->writeln(sprintf('  Checkpoint: %s', $checkpoint));

        $runtime = new TelegramGatewayRuntime(
            container: $this->container,
            client: $client,
            config: $config,
            sessionLinks: $this->container->make(GatewaySessionStore::class),
            messages: $this->container->make(GatewayMessageStore::class),
            approvals: $this->container->make(GatewayApprovalStore::class),
            checkpoints: $this->container->make(GatewayCheckpointStore::class),
            pendingInputs: $this->container->make(GatewayPendingInputStore::class),
            log: $this->container->make(LoggerInterface::class),
            launcher: new SymfonyProcessTelegramWorkerLauncher(InstructionLoader::gitRoot() ?? getcwd()),
        );
        $runtime->setBotUsername($botUsername);
        $runtime->registerBotCommands();

        return $runtime->run();
    }
}

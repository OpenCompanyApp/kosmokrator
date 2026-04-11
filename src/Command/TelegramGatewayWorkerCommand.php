<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Amp\DeferredCancellation;
use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentSession;
use Kosmokrator\Agent\AgentSessionBuilder;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Gateway\GatewayApprovalStore;
use Kosmokrator\Gateway\GatewayMessageEvent;
use Kosmokrator\Gateway\GatewayMessageStore;
use Kosmokrator\Gateway\GatewaySessionContextPromptBuilder;
use Kosmokrator\Gateway\GatewaySessionStore;
use Kosmokrator\Gateway\Telegram\TelegramClient;
use Kosmokrator\Gateway\Telegram\TelegramGatewayConfig;
use Kosmokrator\Gateway\Telegram\TelegramGatewayRenderer;
use Kosmokrator\Gateway\Telegram\TelegramSlashCommandBridge;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gateway:telegram:worker', description: 'Run a single Telegram gateway turn', hidden: true)]
final class TelegramGatewayWorkerCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('event', null, InputOption::VALUE_REQUIRED, 'Base64-encoded gateway event payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $encoded = $input->getOption('event');
        if (! is_string($encoded) || $encoded === '') {
            $output->writeln('<error>Missing --event payload.</error>');

            return Command::FAILURE;
        }

        try {
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                throw new \RuntimeException('Invalid event payload encoding.');
            }

            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new \RuntimeException('Invalid event payload.');
            }

            $event = GatewayMessageEvent::fromArray($payload);
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $settings = $this->container->make(SettingsManager::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $config = TelegramGatewayConfig::fromSettings($settings, $this->container->make('config'));
        $secretToken = $this->container->make(SettingsRepositoryInterface::class)->get('global', 'gateway.telegram.token');
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
            );
        }

        $cancellation = new DeferredCancellation;
        $cancelled = false;
        /** @var AgentSession|null $session */
        $session = null;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $handler = function () use (&$cancelled, $cancellation, &$session): void {
                $cancelled = true;
                $cancellation->cancel(new \RuntimeException('Telegram gateway run cancelled.'));
                if ($session instanceof AgentSession) {
                    $session->orchestrator?->cancelAll();
                }
                $this->container->make(ShellSessionManager::class)->killAll();
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }

        $sessionLinks = $this->container->make(GatewaySessionStore::class);
        $link = $sessionLinks->find('telegram', $event->routeKey);
        $renderer = new TelegramGatewayRenderer(
            client: new TelegramClient($this->container->make('http'), $config->token),
            messages: $this->container->make(GatewayMessageStore::class),
            approvals: $this->container->make(GatewayApprovalStore::class),
            routeKey: $event->routeKey,
            sessionId: $link?->sessionId ?? '',
            chatId: $event->chatId,
            threadId: $event->threadId,
            approvalCallback: fn (): string => 'deny',
            cancellation: fn () => $cancellation->getCancellation(),
        );

        $builder = $this->container->make(AgentSessionBuilder::class);
        $session = $builder->buildGateway($renderer, [
            'append_system_prompt' => GatewaySessionContextPromptBuilder::build($event, $link?->sessionId),
        ]);

        try {
            if ($link !== null) {
                $renderer->setSessionId($link->sessionId);
                $session->sessionManager->setCurrentSession($link->sessionId);
                $history = $session->sessionManager->loadHistory($link->sessionId);
                if ($history->count() > 0) {
                    $session->agentLoop->setHistory($history);
                }
            }

            $slashResult = (new TelegramSlashCommandBridge(
                $this->container,
                $this->getApplication()?->getVersion() ?? 'dev',
            ))->dispatch($event->text, new SlashCommandContext(
                $renderer,
                $session->agentLoop,
                $session->permissions,
                $session->sessionManager,
                $session->llm,
                $this->container->make(TaskStore::class),
                $this->container->make('config'),
                $this->container->make(SettingsRepositoryInterface::class),
                $session->orchestrator,
                $this->container->make(ModelCatalog::class),
                $this->container->make(ProviderCatalog::class),
            ));

            if ($slashResult !== null) {
                if ($slashResult !== '') {
                    if ($link === null) {
                        $sessionId = $session->sessionManager->createSession($session->llm->getProvider().'/'.$session->llm->getModel());
                        $sessionLinks->save('telegram', $event->routeKey, $sessionId, $event->chatId, $event->threadId, $event->userId, [
                            'username' => $event->username,
                        ]);
                        $renderer->setSessionId($sessionId);
                        $session->sessionManager->setCurrentSession($sessionId);
                    }

                    $session->agentLoop->run($slashResult);
                }

                return Command::SUCCESS;
            }

            if ($link === null) {
                $sessionId = $session->sessionManager->createSession($session->llm->getProvider().'/'.$session->llm->getModel());
                $sessionLinks->save('telegram', $event->routeKey, $sessionId, $event->chatId, $event->threadId, $event->userId, [
                    'username' => $event->username,
                ]);
                $renderer->setSessionId($sessionId);
                $session->sessionManager->setCurrentSession($sessionId);
            }

            $session->agentLoop->run($event->text);

            if ($cancelled) {
                $renderer->showNotice('Cancelled.');
            }
        } finally {
            $session?->orchestrator?->cancelAll();
            $this->container->make(ShellSessionManager::class)->killAll();
        }

        return Command::SUCCESS;
    }
}

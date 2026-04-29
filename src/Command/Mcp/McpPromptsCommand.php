<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationArgumentMapper;
use Kosmokrator\Mcp\McpClientManager;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class McpPromptsCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container, private readonly bool $get)
    {
        parent::__construct($get ? 'mcp:prompt' : 'mcp:prompts');
        $this->setDescription($get ? 'Get an MCP prompt' : 'List MCP prompts');
    }

    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server name')
            ->addArgument('prompt', InputArgument::OPTIONAL, 'Prompt name')
            ->addArgument('payload', InputArgument::OPTIONAL, 'Prompt JSON arguments')
            ->addArgument('extra', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Additional loose args')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass MCP trust and read permission policy')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        try {
            $serverName = (string) $input->getArgument('server');
            $server = $store->get($serverName);
            if ($server === null) {
                throw new \RuntimeException("Unknown MCP server: {$serverName}");
            }
            $this->container->make(McpPermissionEvaluator::class)->assertReadAllowed($server, (bool) $input->getOption('force'));
            $client = $this->container->make(McpClientManager::class)->client($serverName);
            if (! $this->get) {
                $this->writeJson($output, ['success' => true, 'prompts' => $client->listPrompts()]);

                return Command::SUCCESS;
            }

            $payload = is_string($input->getArgument('payload')) ? $input->getArgument('payload') : null;
            $args = $this->container->make(IntegrationArgumentMapper::class)->map($this->rawTokens($input), $payload);
            $this->writeJson($output, ['success' => true, 'prompt' => $client->getPrompt((string) $input->getArgument('prompt'), $args)]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->writeJson($output, ['success' => false, 'error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpCatalog;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:schema', description: 'Show the schema for an MCP function')]
final class McpSchemaCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('function', InputArgument::REQUIRED, 'server.tool')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass project MCP trust check for schema discovery')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        try {
            $serverName = explode('.', (string) $input->getArgument('function'), 2)[0] ?? '';
            $server = $store->get($serverName);
            if ($server !== null) {
                $this->container->make(McpPermissionEvaluator::class)->assertTrusted($server, (bool) $input->getOption('force'));
            }
            $tool = $this->container->make(McpCatalog::class)->find((string) $input->getArgument('function'));
        } catch (\Throwable $e) {
            $this->writeJson($output, ['success' => false, 'error' => $e->getMessage()]);

            return Command::FAILURE;
        }
        if ($tool === null) {
            $this->writeJson($output, ['success' => false, 'error' => 'Unknown MCP function: '.$input->getArgument('function')]);

            return Command::FAILURE;
        }

        $data = $this->container->make(McpRuntime::class)->toolRow($tool);
        $input->getOption('json') ? $this->writeJson($output, ['success' => true, 'tool' => $data]) : $this->writeJson($output, $data['schema']);

        return Command::SUCCESS;
    }
}

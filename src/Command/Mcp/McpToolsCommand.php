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

#[AsCommand(name: 'mcp:tools', description: 'List tools exposed by an MCP server')]
final class McpToolsCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::OPTIONAL, 'Server name')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass project MCP trust check for tool discovery')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $runtime = $this->container->make(McpRuntime::class);

        try {
            $serverName = is_string($input->getArgument('server')) ? $input->getArgument('server') : null;
            if ($serverName !== null && $serverName !== '') {
                $server = $store->get($serverName);
                if ($server === null) {
                    throw new \RuntimeException("Unknown MCP server: {$serverName}");
                }
                $this->container->make(McpPermissionEvaluator::class)->assertTrusted($server, (bool) $input->getOption('force'));
            }
            $tools = array_map(
                fn ($tool): array => $runtime->toolRow($tool),
                $this->container->make(McpCatalog::class)->tools($serverName),
            );
        } catch (\Throwable $e) {
            return $this->fail($input, $output, $e->getMessage());
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'tools' => $tools]);
        } else {
            foreach ($tools as $tool) {
                $output->writeln($tool['function'].' - '.$tool['description']);
            }
        }

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }
}

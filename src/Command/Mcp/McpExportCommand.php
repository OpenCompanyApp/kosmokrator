<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\IO\AtomicFileWriter;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:export', description: 'Export effective MCP servers to mcpServers or VS Code servers JSON')]
final class McpExportCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'mcpServers or vscode', 'mcpServers')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Optional output file')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Wrap output in a JSON envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $key = $input->getOption('format') === 'vscode' ? 'servers' : 'mcpServers';
        $data = [$key => []];
        foreach ($store->effectiveServers() as $name => $server) {
            $data[$key][$name] = $server->toPortableArray();
        }

        $path = $input->getOption('path');
        if (is_string($path) && $path !== '') {
            AtomicFileWriter::write($path, $this->jsonEncode($data)."\n", 0700);
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'path' => $path ?: null, 'config' => $data]);
        } else {
            $this->writeJson($output, $data);
        }

        return Command::SUCCESS;
    }
}

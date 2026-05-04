<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpServerConfig;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:import', description: 'Import MCP servers from .mcp.json, VS Code mcp.json, or mcpServers JSON')]
final class McpImportCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'JSON file to import')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Import into global ~/.kosmo/mcp.json')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Import into project .mcp.json')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('path');
        if (! is_file($path)) {
            return $this->fail($input, $output, "File not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return $this->fail($input, $output, 'Input must be a JSON object.');
        }

        $rawServers = is_array($decoded['mcpServers'] ?? null)
            ? $decoded['mcpServers']
            : (is_array($decoded['servers'] ?? null) ? $decoded['servers'] : []);
        if ($rawServers === []) {
            return $this->fail($input, $output, 'No mcpServers or servers object found.');
        }

        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $imported = [];
        foreach ($rawServers as $name => $raw) {
            if (! is_string($name) || ! is_array($raw)) {
                continue;
            }
            $server = McpServerConfig::fromArray($name, $raw);
            $store->writeServer($server, $this->scope($input));
            $imported[] = $name;
        }

        $this->writeJson($output, ['success' => true, 'imported' => $imported, 'scope' => $this->scope($input)]);

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }
}

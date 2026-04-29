<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpServerConfig;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:preset', description: 'Install a known MCP server preset')]
final class McpPresetCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: list or add', 'list')
            ->addArgument('preset', InputArgument::OPTIONAL, 'Preset name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Server name override')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable the server')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable the server')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write ~/.kosmokrator/mcp.json')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project .mcp.json')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $presets = $this->presets();
        $action = strtolower((string) $input->getArgument('action'));

        if ($action === 'list') {
            return $this->list($input, $output, $presets);
        }

        if ($action !== 'add') {
            return $this->fail($input, $output, "Unknown action [{$action}]. Use list or add.");
        }

        $presetName = str_replace('-', '_', strtolower((string) $input->getArgument('preset')));
        if (! isset($presets[$presetName])) {
            return $this->fail($input, $output, "Unknown MCP preset [{$presetName}].");
        }

        $store = $this->container->make(McpConfigStore::class);
        $settings = $this->container->make(SettingsManager::class);
        $this->configureProjectRoot($store, $settings);

        $definition = $presets[$presetName];
        $server = new McpServerConfig(
            name: is_string($input->getOption('name')) && $input->getOption('name') !== '' ? $input->getOption('name') : $definition['name'],
            type: $definition['type'],
            command: $definition['command'] ?? null,
            args: $definition['args'] ?? [],
            url: $definition['url'] ?? null,
            env: $definition['env'] ?? [],
            headers: $definition['headers'] ?? [],
            enabled: ! $input->getOption('disable'),
            timeoutSeconds: $definition['timeout'] ?? 30,
        );

        $path = $store->writeServer($server, $this->scope($input));
        $payload = ['success' => true, 'preset' => $presetName, 'server' => $server->name, 'path' => $path, 'scope' => $this->scope($input)];
        $input->getOption('json') ? $this->writeJson($output, $payload) : $output->writeln("Added MCP preset {$presetName} as {$server->name} to {$path}");

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $presets
     */
    private function list(InputInterface $input, OutputInterface $output, array $presets): int
    {
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'presets' => $presets]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Preset', 'Transport', 'Command/URL', 'Env'])
            ->setRows(array_map(static fn (array $preset): array => [
                $preset['name'],
                $preset['type'],
                $preset['type'] === 'stdio' ? trim(($preset['command'] ?? '').' '.implode(' ', $preset['args'] ?? [])) : ($preset['url'] ?? ''),
                implode(', ', array_keys($preset['env'] ?? [])),
            ], $presets))
            ->render();

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }

    /**
     * @return array<string, array{name: string, type: string, command?: string, args?: list<string>, url?: string, env?: array<string, string>, headers?: array<string, string>, timeout?: int}>
     */
    private function presets(): array
    {
        return [
            'tavily' => [
                'name' => 'tavily',
                'type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', 'tavily-mcp'],
                'env' => ['TAVILY_API_KEY' => '${TAVILY_API_KEY}'],
            ],
            'firecrawl' => [
                'name' => 'firecrawl',
                'type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', 'firecrawl-mcp'],
                'env' => ['FIRECRAWL_API_KEY' => '${FIRECRAWL_API_KEY}'],
            ],
            'exa' => [
                'name' => 'exa',
                'type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', 'exa-mcp-server'],
                'env' => ['EXA_API_KEY' => '${EXA_API_KEY}'],
            ],
            'fetch' => [
                'name' => 'fetch',
                'type' => 'stdio',
                'command' => 'uvx',
                'args' => ['mcp-server-fetch'],
            ],
            'parallel' => [
                'name' => 'parallel',
                'type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', 'https://search.parallel.ai/mcp'],
                'env' => ['PARALLEL_API_KEY' => '${PARALLEL_API_KEY}'],
            ],
        ];
    }
}

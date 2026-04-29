<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\Server\KosmokratorMcpGateway;
use Kosmokrator\Mcp\Server\McpJsonRpcStdioServer;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:serve', description: 'Run KosmoKrator as a stdio MCP gateway for selected integrations and MCP servers')]
final class McpServeCommand extends Command
{
    use InteractsWithMcpOutput;
    use ResolvesMcpGatewayOptions;

    public function __construct(
        private readonly Container $container,
        private readonly string $version,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureGatewayOptions($this)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass KosmoKrator MCP trust/read/write checks for explicitly selected upstream MCP servers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsManager::class);
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $settings);

        $profile = $this->profileFromInput($input, $settings, force: (bool) $input->getOption('force'));

        return (new McpJsonRpcStdioServer(
            $this->container->make(KosmokratorMcpGateway::class),
            $profile,
            $this->version,
        ))->run(STDIN, STDOUT);
    }
}

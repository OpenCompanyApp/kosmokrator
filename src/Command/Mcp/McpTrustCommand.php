<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpPermissionEvaluator;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:trust', description: 'Trust a project MCP server after reviewing its command')]
final class McpTrustCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server name')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write trust to global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write trust to project config')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $settings = $this->container->make(SettingsManager::class);
        $this->configureProjectRoot($store, $settings);
        $server = $store->get((string) $input->getArgument('server'));
        if ($server === null) {
            $data = ['success' => false, 'error' => 'Unknown MCP server: '.$input->getArgument('server')];
            $input->getOption('json') ? $this->writeJson($output, $data) : $output->writeln('<error>'.$data['error'].'</error>');

            return Command::FAILURE;
        }

        $fingerprint = $this->container->make(McpPermissionEvaluator::class)->trust($server, $this->scope($input));
        $data = ['success' => true, 'server' => $server->name, 'fingerprint' => $fingerprint, 'scope' => $this->scope($input)];
        $input->getOption('json') ? $this->writeJson($output, $data) : $output->writeln("Trusted {$server->name}");

        return Command::SUCCESS;
    }
}

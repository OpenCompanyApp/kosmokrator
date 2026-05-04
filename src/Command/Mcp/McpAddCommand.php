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

#[AsCommand(name: 'mcp:add', description: 'Add or update an MCP server in portable mcp.json format')]
final class McpAddCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Server name')
            ->addArgument('command_or_url', InputArgument::OPTIONAL, 'Command for stdio or URL for HTTP')
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Command arguments')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Transport type: stdio or http')
            ->addOption('command', null, InputOption::VALUE_REQUIRED, 'Stdio command')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Streamable HTTP URL')
            ->addOption('arg', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Command argument; repeatable')
            ->addOption('env', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Environment variable KEY or KEY=value; repeatable')
            ->addOption('header', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'HTTP header Name: value; repeatable')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Request timeout in seconds')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable the server')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable the server')
            ->addOption('read', null, InputOption::VALUE_REQUIRED, 'Read permission: allow, ask, deny')
            ->addOption('write', null, InputOption::VALUE_REQUIRED, 'Write permission: allow, ask, deny')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write ~/.kosmo/mcp.json')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project .mcp.json')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $settings = $this->container->make(SettingsManager::class);
        $this->configureProjectRoot($store, $settings);

        $name = (string) $input->getArgument('name');
        $commandOrUrl = $input->getArgument('command_or_url');
        $type = (string) ($input->getOption('type') ?: '');
        $command = is_string($input->getOption('command')) ? $input->getOption('command') : null;
        $url = is_string($input->getOption('url')) ? $input->getOption('url') : null;

        if (is_string($commandOrUrl) && $commandOrUrl !== '') {
            if ($type === '' && preg_match('/^https?:\/\//', $commandOrUrl) === 1) {
                $type = 'http';
                $url = $commandOrUrl;
            } elseif ($command === null && $url === null) {
                $type = $type ?: 'stdio';
                $command = $commandOrUrl;
            }
        }

        $type = $type ?: ($url !== null ? 'http' : 'stdio');
        $args = array_values(array_map('strval', array_merge(
            is_array($input->getOption('arg')) ? $input->getOption('arg') : [],
            is_array($input->getArgument('args')) ? $input->getArgument('args') : [],
        )));

        $server = new McpServerConfig(
            name: $name,
            type: $type,
            command: $command,
            args: $args,
            url: $url,
            env: $this->envMap($input->getOption('env')),
            headers: $this->headerMap($input->getOption('header')),
            enabled: ! $input->getOption('disable'),
            timeoutSeconds: max(1, (int) ($input->getOption('timeout') ?: 30)),
        );

        $path = $store->writeServer($server, $this->scope($input));
        foreach (['read', 'write'] as $operation) {
            $permission = $input->getOption($operation);
            if (is_string($permission) && $permission !== '') {
                if (! in_array($permission, ['allow', 'ask', 'deny'], true)) {
                    return $this->fail($input, $output, "Invalid {$operation} permission: {$permission}");
                }
                $settings->setRaw("kosmo.mcp.servers.{$name}.permissions.{$operation}", $permission, $this->scope($input));
            }
        }

        $data = ['success' => true, 'server' => $name, 'path' => $path, 'scope' => $this->scope($input)];
        $input->getOption('json') ? $this->writeJson($output, $data) : $output->writeln("Added MCP server {$name} to {$path}");

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function envMap(mixed $values): array
    {
        $result = [];
        foreach (is_array($values) ? $values : [] as $entry) {
            $entry = (string) $entry;
            if (str_contains($entry, '=')) {
                [$key, $value] = explode('=', $entry, 2);
                $result[$key] = $value;
            } elseif ($entry !== '') {
                $result[$entry] = '${'.$entry.'}';
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function headerMap(mixed $values): array
    {
        $result = [];
        foreach (is_array($values) ? $values : [] as $entry) {
            [$key, $value] = array_pad(explode(':', (string) $entry, 2), 2, '');
            if (trim($key) !== '') {
                $result[trim($key)] = trim($value);
            }
        }

        return $result;
    }
}

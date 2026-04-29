<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpSecretStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class McpSecretCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container, private readonly string $action)
    {
        parent::__construct("mcp:secret:{$action}");
        $this->setDescription("MCP secret {$action}");
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::OPTIONAL, 'Server name')
            ->addArgument('key', InputArgument::OPTIONAL, 'Secret key, e.g. env.GITHUB_TOKEN')
            ->addArgument('value', InputArgument::OPTIONAL, 'Secret value')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read value from stdin')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Read value from environment variable')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpSecretStore::class);
        $server = is_string($input->getArgument('server')) ? $input->getArgument('server') : null;
        $key = is_string($input->getArgument('key')) ? $input->getArgument('key') : null;

        if ($this->action === 'list') {
            $this->writeJson($output, ['success' => true, 'secrets' => $store->list($server)]);

            return Command::SUCCESS;
        }

        if ($server === null || $server === '' || $key === null || $key === '') {
            return $this->fail($input, $output, 'Server and key are required.');
        }

        if ($this->action === 'unset') {
            $store->unset($server, $key);
            $this->writeJson($output, ['success' => true, 'server' => $server, 'key' => "mcp.{$server}.{$key}", 'configured' => false]);

            return Command::SUCCESS;
        }

        $value = $this->value($input);
        if ($value === null) {
            return $this->fail($input, $output, 'Provide a value, --stdin, or --env.');
        }

        $store->set($server, $key, $value);
        $this->writeJson($output, ['success' => true, 'server' => $server, 'key' => "mcp.{$server}.{$key}", 'configured' => true]);

        return Command::SUCCESS;
    }

    private function value(InputInterface $input): ?string
    {
        if ($input->getOption('stdin')) {
            return trim((string) stream_get_contents(STDIN));
        }

        $env = $input->getOption('env');
        if (is_string($env) && $env !== '') {
            $value = getenv($env);

            return $value === false ? null : (string) $value;
        }

        $value = $input->getArgument('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }
}

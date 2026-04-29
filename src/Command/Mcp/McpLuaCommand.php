<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:lua', description: 'Execute Lua against configured MCP servers')]
final class McpLuaCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Lua file to execute')
            ->addOption('eval', 'e', InputOption::VALUE_REQUIRED, 'Lua code to execute')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass MCP trust and read/write permission policy')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit in bytes')
            ->addOption('cpu-limit', null, InputOption::VALUE_REQUIRED, 'CPU limit in seconds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $code = $this->code($input);
        if ($code === null || trim($code) === '') {
            $output->writeln('<error>Provide a Lua file, --eval code, or pipe Lua code on stdin.</error>');

            return Command::INVALID;
        }

        $options = [];
        if ($input->getOption('memory-limit') !== null) {
            $options['memoryLimit'] = (int) $input->getOption('memory-limit');
        }
        if ($input->getOption('cpu-limit') !== null) {
            $options['cpuLimit'] = (float) $input->getOption('cpu-limit');
        }
        if ($input->getOption('force')) {
            $options['force'] = true;
        }

        $result = $this->container->make(McpRuntime::class)->executeLua($code, $options);
        if ($input->getOption('json')) {
            $this->writeJson($output, $result->toArray());
        } else {
            if ($result->lua->output !== '') {
                $output->writeln($result->lua->output);
            }
            if ($result->lua->result !== null && $result->lua->result !== []) {
                $output->writeln(is_string($result->lua->result)
                    ? $result->lua->result
                    : (json_encode($result->lua->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
            }
            if ($result->lua->error !== null) {
                $output->writeln('<error>'.$result->lua->error.'</error>');
            }
        }

        return $result->succeeded() ? Command::SUCCESS : Command::FAILURE;
    }

    private function code(InputInterface $input): ?string
    {
        $eval = $input->getOption('eval');
        if (is_string($eval) && $eval !== '') {
            return $eval;
        }

        $file = $input->getArgument('file');
        if (is_string($file) && $file !== '') {
            if (! is_file($file)) {
                throw new \RuntimeException("Lua file not found: {$file}");
            }

            $content = file_get_contents($file);

            return $content === false ? null : $content;
        }

        return $this->readStdinIfPiped();
    }
}

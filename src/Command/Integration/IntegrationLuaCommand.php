<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:lua', description: 'Execute Lua against configured integrations')]
final class IntegrationLuaCommand extends Command
{
    use InteractsWithIntegrationOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Lua file to execute')
            ->addOption('eval', 'e', InputOption::VALUE_REQUIRED, 'Lua code to execute')
            ->addOption('repl', null, InputOption::VALUE_NONE, 'Run an integration Lua REPL')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Memory limit in bytes')
            ->addOption('cpu-limit', null, InputOption::VALUE_REQUIRED, 'CPU limit in seconds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('repl')) {
            return $this->repl($output);
        }

        $code = $this->resolveCode($input);
        if ($code === null || trim($code) === '') {
            $output->writeln('<error>Provide a Lua file, --eval code, or pipe Lua code on stdin.</error>');

            return Command::INVALID;
        }

        $result = $this->container->make(IntegrationRuntime::class)->executeLua($code, $this->options($input));

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

    private function repl(OutputInterface $output): int
    {
        $output->writeln('KosmoKrator integration Lua REPL. Type :quit to exit.');

        while (true) {
            fwrite(STDOUT, 'lua> ');
            $line = fgets(STDIN);
            if ($line === false || trim($line) === ':quit') {
                break;
            }

            $result = $this->container->make(IntegrationRuntime::class)->executeLua($line);
            if ($result->lua->output !== '') {
                $output->writeln($result->lua->output);
            }
            if ($result->lua->error !== null) {
                $output->writeln('<error>'.$result->lua->error.'</error>');
            }
        }

        return Command::SUCCESS;
    }

    private function resolveCode(InputInterface $input): ?string
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

    /**
     * @return array{memoryLimit?: int, cpuLimit?: float}
     */
    private function options(InputInterface $input): array
    {
        $options = [];
        if ($input->getOption('memory-limit') !== null) {
            $options['memoryLimit'] = (int) $input->getOption('memory-limit');
        }
        if ($input->getOption('cpu-limit') !== null) {
            $options['cpuLimit'] = (float) $input->getOption('cpu-limit');
        }

        return $options;
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Acp\AcpAgentServer;
use Kosmokrator\Acp\AcpConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'acp', description: 'Start an ACP (Agent Client Protocol) stdio server')]
final class AcpCommand extends Command
{
    public function __construct(
        private readonly Container $container,
        private readonly string $version = 'dev',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory for ACP sessions', getcwd() ?: '.')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Override model')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Agent mode: edit, plan, ask')
            ->addOption('permission-mode', null, InputOption::VALUE_REQUIRED, 'Permission mode: guardian, argus, prometheus')
            ->addOption('yolo', null, InputOption::VALUE_NONE, 'Alias for --permission-mode prometheus');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = (string) ($input->getOption('cwd') ?: (getcwd() ?: '.'));
        $realCwd = realpath($cwd);
        if ($realCwd !== false) {
            chdir($realCwd);
            $cwd = $realCwd;
        }

        $permissionMode = $input->getOption('permission-mode');
        if ($input->getOption('yolo')) {
            $permissionMode = 'prometheus';
        }

        $connection = new AcpConnection(STDIN, STDOUT);
        $server = new AcpAgentServer(
            $this->container,
            $connection,
            $cwd,
            $this->version,
            is_string($input->getOption('model')) ? $input->getOption('model') : null,
            is_string($input->getOption('mode')) ? $input->getOption('mode') : null,
            is_string($permissionMode) ? $permissionMode : null,
        );

        return $server->run();
    }
}

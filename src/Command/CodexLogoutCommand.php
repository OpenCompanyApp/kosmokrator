<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'codex:logout', description: 'Remove stored Codex authentication tokens')]
final class CodexLogoutCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tokens = $this->container->make(CodexTokenStore::class);

        if ($tokens->current() === null) {
            $output->writeln('<comment>No Codex tokens stored.</comment>');

            return Command::SUCCESS;
        }

        $tokens->clear();
        $output->writeln('<info>Codex tokens removed.</info>');

        return Command::SUCCESS;
    }
}

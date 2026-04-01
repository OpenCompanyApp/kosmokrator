<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'codex:status', description: 'Show Codex authentication status')]
final class CodexStatusCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $this->container->make(CodexTokenStore::class)->current();

        if ($token === null) {
            $output->writeln('<comment>Codex is not configured. Run `kosmokrator codex:login`.</comment>');

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Property', 'Value'])
            ->setRows([
                ['Status', $token->isExpired() ? 'Expired' : 'Active'],
                ['Email', $token->email ?? 'N/A'],
                ['Account ID', $token->accountId ?? 'N/A'],
                ['Token Expires', $token->expiresAt->format('Y-m-d H:i:s')],
                ['Valid', $token->isExpiringSoon() ? 'Needs refresh' : 'Yes'],
                ['Last Updated', $token->updatedAt?->format('Y-m-d H:i:s') ?? 'N/A'],
            ])
            ->render();

        return Command::SUCCESS;
    }
}

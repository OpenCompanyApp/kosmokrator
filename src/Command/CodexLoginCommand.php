<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'codex:login', description: 'Authenticate with ChatGPT for the Codex provider')]
final class CodexLoginCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('device', null, InputOption::VALUE_NONE, 'Use the device authorization flow');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $auth = $this->container->make(CodexAuthFlow::class);

        return $input->getOption('device')
            ? $this->deviceFlow($auth, $output)
            : $this->browserFlow($auth, $output);
    }

    private function browserFlow(CodexAuthFlow $auth, OutputInterface $output): int
    {
        try {
            $token = $auth->browserLogin(fn (string $message) => $output->writeln($message));
        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<error>  '.$e->getMessage().'</error>');
            $output->writeln('');
            $output->writeln('Try the device authorization flow instead: <info>php bin/kosmokrator codex:login --device</info>');

            return Command::FAILURE;
        }

        $this->renderTokenSummary($output, $token->email, $token->accountId);

        return Command::SUCCESS;
    }

    private function deviceFlow(CodexAuthFlow $auth, OutputInterface $output): int
    {
        try {
            $token = $auth->deviceLogin(fn (string $message) => $output->writeln($message));
        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<error>  '.$e->getMessage().'</error>');
            $output->writeln('');

            return Command::FAILURE;
        }

        $this->renderTokenSummary($output, $token->email, $token->accountId);

        return Command::SUCCESS;
    }

    private function renderTokenSummary(OutputInterface $output, ?string $email, ?string $accountId): void
    {
        $rows = [['Status', 'Authenticated']];
        if ($email !== null) {
            $rows[] = ['Account', $email];
        }
        if ($accountId !== null) {
            $rows[] = ['Account ID', $accountId];
        }

        $output->writeln('');
        (new Table($output))
            ->setHeaders(['Property', 'Value'])
            ->setRows($rows)
            ->render();
    }
}

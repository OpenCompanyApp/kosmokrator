<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Update\SelfUpdater;
use Kosmokrator\Update\SelfUpdaterInterface;
use Kosmokrator\Update\UpdateChecker;
use Kosmokrator\Update\UpdateCheckerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update', description: 'Check for updates and install them when supported')]
class UpdateCommand extends Command
{
    /**
     * @param  null|\Closure(string): UpdateCheckerInterface  $checkerFactory
     * @param  null|\Closure(): SelfUpdaterInterface  $updaterFactory
     */
    public function __construct(
        private readonly Container $container,
        private readonly string $currentVersion = 'dev',
        private readonly ?\Closure $checkerFactory = null,
        private readonly ?\Closure $updaterFactory = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only check whether an update is available')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply the update without interactive confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $checker = $this->makeChecker();
        $updater = $this->makeUpdater();
        $method = $updater->installationMethod();

        $io->writeln(sprintf(
            'Install method: <info>%s</info>',
            match ($method) {
                'binary' => 'static binary',
                'phar' => 'PHAR',
                'source' => 'source checkout',
                default => 'unknown',
            },
        ));

        $checker->clearCache();
        $latest = $checker->fetchLatestVersion();
        if ($latest === null) {
            $io->error('Could not reach GitHub. Try again later.');

            return Command::FAILURE;
        }

        $currentNormalized = ltrim($this->currentVersion, 'v');
        $latestNormalized = ltrim($latest, 'v');

        if (! version_compare($latestNormalized, $currentNormalized, '>')) {
            $io->success("Already on the latest version (v{$currentNormalized}).");

            return Command::SUCCESS;
        }

        $io->note("Update available: v{$latestNormalized} (current: v{$currentNormalized})");

        if ($input->getOption('check')) {
            return Command::SUCCESS;
        }

        if ($method === 'source') {
            $io->warning("Source installs are updated manually.\n\n".$updater->sourceUpdateInstructions());

            return Command::SUCCESS;
        }

        if (! $input->getOption('yes') && (! $input->isInteractive() || ! $io->confirm("Install v{$latestNormalized} now?", true))) {
            $io->text('Skipped.');

            return Command::SUCCESS;
        }

        try {
            $io->text('Downloading and replacing the current executable...');
            $message = $updater->update($latestNormalized);
            $io->success($message);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Update failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    protected function makeChecker(): UpdateCheckerInterface
    {
        return $this->checkerFactory !== null
            ? ($this->checkerFactory)($this->currentVersion)
            : new UpdateChecker($this->currentVersion);
    }

    protected function makeUpdater(): SelfUpdaterInterface
    {
        return $this->updaterFactory !== null
            ? ($this->updaterFactory)()
            : new SelfUpdater;
    }
}

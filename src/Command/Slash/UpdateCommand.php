<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Update\SelfUpdater;
use Kosmokrator\Update\UpdateChecker;

/**
 * Checks for updates and performs an in-place self-update of the KosmoKrator binary.
 */
class UpdateCommand implements SlashCommand
{
    public function __construct(
        private readonly string $currentVersion,
    ) {}

    public function name(): string
    {
        return '/update';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Check for updates and self-update';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->ui->showNotice('Checking for updates...');

        $checker = new UpdateChecker($this->currentVersion);
        $checker->clearCache();
        $latest = $checker->fetchLatestVersion();

        if ($latest === null) {
            $ctx->ui->showNotice('Could not reach GitHub. Try again later.');

            return SlashCommandResult::continue();
        }

        $currentNormalized = ltrim($this->currentVersion, 'v');
        $latestNormalized = ltrim($latest, 'v');

        if (! version_compare($latestNormalized, $currentNormalized, '>')) {
            $ctx->ui->showNotice("Already on the latest version (v{$currentNormalized}).");

            return SlashCommandResult::continue();
        }

        $ctx->ui->showNotice("New version available: v{$latestNormalized} (current: v{$currentNormalized})");

        $confirm = $ctx->ui->askChoice('Update now?', [
            ['label' => 'Yes', 'detail' => "Download and install v{$latestNormalized}.", 'recommended' => true],
            ['label' => 'No', 'detail' => 'Skip this update.', 'recommended' => false],
        ]);

        if ($confirm !== 'Yes') {
            return SlashCommandResult::continue();
        }

        try {
            $updater = new SelfUpdater;
            $message = $updater->update($latestNormalized);
            $ctx->ui->showNotice($message);
        } catch (\Throwable $e) {
            $ctx->ui->showError('Update failed: '.$e->getMessage());
        }

        return SlashCommandResult::continue();
    }
}

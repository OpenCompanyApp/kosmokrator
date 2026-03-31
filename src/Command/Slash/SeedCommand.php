<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class SeedCommand implements SlashCommand
{
    public function name(): string
    {
        return '/seed';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Seed mock session (dev)';
    }

    public function immediate(): bool
    {
        return false;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->ui->seedMockSession();

        return SlashCommandResult::continue();
    }
}

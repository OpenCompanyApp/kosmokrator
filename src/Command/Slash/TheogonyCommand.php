<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class TheogonyCommand implements SlashCommand
{
    public function name(): string
    {
        return '/theogony';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return ['/cosmogony'];
    }

    public function description(): string
    {
        return 'Play the Theogony';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->ui->playTheogony();

        return SlashCommandResult::continue();
    }
}

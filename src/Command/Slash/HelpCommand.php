<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\PowerCommandRegistry;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandRegistry;
use Kosmokrator\Command\SlashCommandResult;

/**
 * Lists all available slash and power commands with descriptions.
 */
class HelpCommand implements SlashCommand
{
    public function __construct(
        private readonly SlashCommandRegistry $slashRegistry,
        private readonly PowerCommandRegistry $powerRegistry,
    ) {}

    public function name(): string
    {
        return '/help';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return ['/?', '/commands'];
    }

    public function description(): string
    {
        return 'List available commands';
    }

    public function immediate(): bool
    {
        return true;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $lines = [];

        // Slash commands
        $lines[] = 'Slash commands:';
        foreach ($this->slashRegistry->all() as $command) {
            $aliases = $command->aliases();
            $aliasStr = $aliases !== [] ? ' ('.implode(', ', $aliases).')' : '';
            $lines[] = sprintf('  %-20s %s%s', $command->name(), $command->description(), $aliasStr);
        }

        // Power commands
        $lines[] = '';
        $lines[] = 'Power commands:';
        foreach ($this->powerRegistry->all() as $command) {
            $aliases = $command->aliases();
            $aliasStr = $aliases !== [] ? ' ('.implode(', ', $aliases).')' : '';
            $lines[] = sprintf('  %-20s %s%s', ':'.$command->name(), $command->description(), $aliasStr);
        }

        $ctx->ui->showNotice(implode("\n", $lines));

        return SlashCommandResult::continue();
    }
}

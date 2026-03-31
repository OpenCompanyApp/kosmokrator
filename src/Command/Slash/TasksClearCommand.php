<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class TasksClearCommand implements SlashCommand
{
    public function name(): string
    {
        return '/tasks clear';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Remove all tasks';
    }

    public function immediate(): bool
    {
        return false;
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $count = count($ctx->taskStore->all());
        $ctx->taskStore->clearAll();
        $ctx->ui->refreshTaskBar();
        $ctx->ui->showNotice($count > 0 ? "Cleared {$count} tasks." : 'No tasks to clear.');

        return SlashCommandResult::continue();
    }
}

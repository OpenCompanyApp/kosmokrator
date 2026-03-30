<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class MemoriesCommand implements SlashCommand
{
    public function name(): string
    {
        return '/memories';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'List stored memories';
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $memories = $ctx->sessionManager->getMemories();

        if ($memories === []) {
            $ctx->ui->showNotice('No memories stored yet.');
        } else {
            $lines = [];
            foreach ($memories as $m) {
                $lines[] = "  [{$m['id']}] ({$m['type']}) {$m['title']}";
            }
            $ctx->ui->showNotice("Memories:\n" . implode("\n", $lines));
        }

        return SlashCommandResult::continue();
    }
}

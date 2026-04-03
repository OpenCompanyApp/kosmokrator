<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiReplay;

class ReplayCommand implements PowerCommand
{
    public function name(): string
    {
        return ':replay';
    }

    public function aliases(): array
    {
        return [':redo'];
    }

    public function description(): string
    {
        return 'Replay and optionally modify a previous workflow';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiReplay::class;
    }

    public function buildPrompt(string $args): string
    {
        $modification = $args !== '' ? "\n\nModification: {$args}" : '';

        return <<<PROMPT
            REPLAY MODE — TIME REWIND.{$modification}

            Find and re-execute a previous workflow from this conversation, optionally with modifications.

            ## Process:

            1. **SCAN**: Look back through the conversation for previous power command executions, plans, or significant task runs.

            2. **LIST**: Show what can be replayed:
               - The command/workflow that was run
               - What it produced
               - A brief summary of the outcome

            3. **SELECT**: If args were provided, use them to identify which workflow to replay and how to modify it. If no args, show the list and ask the user which to replay.

            4. **REPLAY**: Re-execute the selected workflow:
               - Apply any modifications the user specified
               - Use the same overall structure but with fresh execution
               - If the original failed, note what went wrong and adjust

            ## Rules:
            - Don't blindly repeat — if something failed before, try a different approach
            - Preserve the spirit of the modification while being practical about implementation
            - If the original workflow spawned agents, re-spawn them with updated instructions
            PROMPT;
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiCancel;

class CancelCommand implements PowerCommand
{
    public function name(): string
    {
        return ':cancel';
    }

    public function aliases(): array
    {
        return [':stop'];
    }

    public function description(): string
    {
        return 'Gracefully cancel any active workflow or swarm';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiCancel::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<'PROMPT'
            CANCEL PROTOCOL — CONTROLLED SHUTDOWN.

            Detect and gracefully tear down any active workflows, swarms, or long-running processes.

            ## Steps:

            1. **DETECT**: Check what's currently active:
               - Running subagents (check /agents dashboard)
               - Active task lists
               - Any loop or retry mode in progress

            2. **REPORT**: List everything found:
               - For each active item: what it is, how long it's been running, current status
               - Estimate how much work would be lost by cancelling

            3. **TEARDOWN**: Cancel active work:
               - Signal all running subagents to stop
               - Preserve any partial results that are useful
               - Clean up any temporary state

            4. **SUMMARY**: Report what was cancelled and what (if anything) was preserved.

            This is a graceful shutdown — preserve useful partial work where possible, but don't let anything keep running.
            PROMPT;
    }
}

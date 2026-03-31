<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

interface SlashCommand
{
    public function name(): string;

    /** @return string[] */
    public function aliases(): array;

    public function description(): string;

    /**
     * Whether this command can execute while the agent is running.
     * Immediate commands are dispatched inline; non-immediate ones are queued.
     */
    public function immediate(): bool;

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult;
}

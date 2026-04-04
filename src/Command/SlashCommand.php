<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

/**
 * Contract for all slash-commands available inside the agent REPL (e.g. /quit, /compact, /mode).
 */
interface SlashCommand
{
    /** @return string Primary command name including the leading slash (e.g. "/quit") */
    public function name(): string;

    /** @return string[] */
    public function aliases(): array;

    /** @return string One-line human-readable description shown in help listings */
    public function description(): string;

    /**
     * Whether this command can execute while the agent is running.
     * Immediate commands are dispatched inline; non-immediate ones are queued.
     */
    public function immediate(): bool;

    /**
     * Execute the slash command.
     *
     * @param  string  $args  Raw text after the command name (may be empty)
     * @param  SlashCommandContext  $ctx  Shared access to UI, agent loop, sessions, etc.
     */
    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult;
}

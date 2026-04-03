<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

/**
 * Name/alias → SlashCommand lookup used by the REPL to dispatch user-entered slash commands.
 */
class SlashCommandRegistry
{
    /** @var array<string, SlashCommand> name → command */
    private array $commands = [];

    /** @var array<string, string> alias → name */
    private array $aliases = [];

    public function register(SlashCommand $command): void
    {
        $this->commands[$command->name()] = $command;

        foreach ($command->aliases() as $alias) {
            $this->aliases[$alias] = $command->name();
        }
    }

    /**
     * Resolve a slash command from user input.
     * Matches the command name or alias at the start of the input.
     */
    public function resolve(string $input): ?SlashCommand
    {
        $lower = strtolower($input);

        // Try exact match or prefix match (for commands with args like "/resume <id>")
        foreach ($this->commands as $name => $command) {
            if ($lower === $name || str_starts_with($lower, $name.' ')) {
                return $command;
            }
        }

        // Try aliases
        foreach ($this->aliases as $alias => $name) {
            if ($lower === $alias || str_starts_with($lower, $alias.' ')) {
                return $this->commands[$name];
            }
        }

        return null;
    }

    /**
     * Extract the args portion from input, given the matched command name.
     */
    public function extractArgs(string $input, SlashCommand $command): string
    {
        $lower = strtolower($input);
        $name = $command->name();

        // Check name
        if ($lower === $name) {
            return '';
        }
        if (str_starts_with($lower, $name.' ')) {
            return trim(substr($input, strlen($name)));
        }

        // Check aliases
        foreach ($command->aliases() as $alias) {
            if ($lower === $alias) {
                return '';
            }
            if (str_starts_with($lower, $alias.' ')) {
                return trim(substr($input, strlen($alias)));
            }
        }

        return '';
    }

    /** @return SlashCommand[] */
    public function all(): array
    {
        return array_values($this->commands);
    }
}

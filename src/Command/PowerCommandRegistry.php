<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

/**
 * Registry for power workflow commands (`:` prefix).
 *
 * Supports combinability: `:unleash audit :trace bug` is parsed into
 * an ordered chain of (PowerCommand, args) pairs. Animations play
 * sequentially, prompts are injected as a combined message.
 */
class PowerCommandRegistry
{
    /** @var array<string, PowerCommand> name → command */
    private array $commands = [];

    /** @var array<string, string> alias → primary name */
    private array $aliases = [];

    public function register(PowerCommand $command): void
    {
        $name = strtolower($command->name());
        $this->commands[$name] = $command;

        foreach ($command->aliases() as $alias) {
            $this->aliases[strtolower($alias)] = $name;
        }
    }

    /**
     * Resolve a single command name (with or without colon prefix).
     */
    public function resolve(string $name): ?PowerCommand
    {
        $lower = strtolower($name);

        if (isset($this->commands[$lower])) {
            return $this->commands[$lower];
        }

        if (isset($this->aliases[$lower])) {
            return $this->commands[$this->aliases[$lower]];
        }

        return null;
    }

    /**
     * Parse input into an ordered chain of (PowerCommand, args) pairs.
     *
     * Supports single commands and chains:
     *   ":unleash audit"           → [[UnleashCommand, "audit"]]
     *   ":unleash audit :trace bug" → [[UnleashCommand, "audit"], [TraceCommand, "bug"]]
     *
     * @return array<array{PowerCommand, string}>|null Null if any command is unknown
     */
    public function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '' || $input[0] !== ':') {
            return null;
        }

        // Build a set of all known command words (without colon prefix)
        $knownWords = [];
        foreach ($this->commands as $name => $cmd) {
            $knownWords[substr($name, 1)] = true; // strip leading ':'
        }
        foreach ($this->aliases as $alias => $name) {
            $knownWords[substr($alias, 1)] = true;
        }

        // Find all positions where a known :command starts
        $segments = [];
        $pattern = '/(^|(?<=\s)):(\w+)/';
        if (preg_match_all($pattern, $input, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $fullMatch = ltrim($match[0]); // remove leading space if any
                $word = $matches[2][$i][0];
                $offset = $match[1];

                // Adjust offset past any leading whitespace
                if ($match[0] !== $fullMatch) {
                    $offset += strlen($match[0]) - strlen($fullMatch);
                }

                if (isset($knownWords[strtolower($word)])) {
                    $command = $this->resolve(':'.$word);
                    if ($command !== null) {
                        $segments[] = [
                            'command' => $command,
                            'start' => $offset,
                            'nameLen' => strlen($fullMatch),
                        ];
                    }
                }
            }
        }

        if ($segments === []) {
            return null;
        }

        // Extract args for each segment
        $chain = [];
        $segmentCount = count($segments);
        for ($i = 0; $i < $segmentCount; $i++) {
            $argsStart = $segments[$i]['start'] + $segments[$i]['nameLen'];
            $argsEnd = $i + 1 < $segmentCount ? $segments[$i + 1]['start'] : strlen($input);
            $args = trim(substr($input, $argsStart, $argsEnd - $argsStart));
            $chain[] = [$segments[$i]['command'], $args];
        }

        return $chain;
    }

    /**
     * Quick check: does the input start with ':' prefix?
     */
    public function isPowerInput(string $input): bool
    {
        return str_starts_with(trim($input), ':');
    }

    /** @return PowerCommand[] */
    public function all(): array
    {
        return array_values($this->commands);
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Kosmokrator\UI\Ansi\AnsiAnimation;

/**
 * Contract for power workflow commands (`:` prefix).
 *
 * Power commands are declarative — they declare an animation to play and
 * build a prompt to inject. The PowerCommandRegistry orchestrates execution,
 * including combinability (`:unleash audit :trace bug`).
 */
interface PowerCommand
{
    /** Primary name including the colon prefix (e.g. ":unleash"). */
    public function name(): string;

    /** @return string[] Alternative names (e.g. [":swarm", ":nuke"]) */
    public function aliases(): array;

    /** One-line description for help/autocomplete. */
    public function description(): string;

    /** Whether this command requires arguments to function. */
    public function requiresArgs(): bool;

    /**
     * Return the FQCN of the AnsiAnimation class to play.
     *
     * @return class-string<AnsiAnimation>
     */
    public function animationClass(): string;

    /**
     * Build the prompt text to inject into the agent.
     *
     * @param  string  $args  Raw text after the command name
     * @return string The prompt to inject
     */
    public function buildPrompt(string $args): string;
}

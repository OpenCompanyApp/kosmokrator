<?php

declare(strict_types=1);

namespace Kosmokrator\Tool;

/**
 * Contract for every tool the agent can invoke.
 *
 * Each tool provides its name, description, JSON-Schema parameters, and an
 * execute() method that returns a ToolResult. Registered via ToolRegistry.
 */
interface ToolInterface
{
    /** Unique kebab-case identifier used in tool-call routing. */
    public function name(): string;

    /** Short description exposed to the LLM for tool selection. */
    public function description(): string;

    /**
     * JSON Schema properties for the tool's parameters.
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function parameters(): array;

    /**
     * @return string[]
     */
    public function requiredParameters(): array;

    /**
     * Execute the tool with the given named arguments and return the result.
     *
     * @param  array<string, mixed>  $args  Named parameters matching parameters()
     */
    public function execute(array $args): ToolResult;
}

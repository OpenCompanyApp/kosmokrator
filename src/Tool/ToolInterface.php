<?php

namespace Kosmokrator\Tool;

interface ToolInterface
{
    public function name(): string;

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

    public function execute(array $args): ToolResult;
}

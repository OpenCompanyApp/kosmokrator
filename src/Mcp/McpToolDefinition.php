<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

final readonly class McpToolDefinition
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $annotations
     */
    public function __construct(
        public string $server,
        public string $name,
        public string $luaName,
        public string $slug,
        public string $description,
        public array $inputSchema,
        public array $annotations = [],
    ) {}

    public function operation(): string
    {
        if (($this->annotations['readOnlyHint'] ?? null) === true) {
            return 'read';
        }

        if (($this->annotations['destructiveHint'] ?? null) === true || ($this->annotations['idempotentHint'] ?? null) === false) {
            return 'write';
        }

        return 'write';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parameters(): array
    {
        $properties = is_array($this->inputSchema['properties'] ?? null) ? $this->inputSchema['properties'] : [];
        $required = is_array($this->inputSchema['required'] ?? null) ? array_map('strval', $this->inputSchema['required']) : [];
        $parameters = [];

        foreach ($properties as $name => $schema) {
            if (! is_string($name)) {
                continue;
            }

            $schema = is_array($schema) ? $schema : [];
            $parameters[] = [
                'name' => $name,
                'type' => (string) ($schema['type'] ?? 'mixed'),
                'required' => in_array($name, $required, true),
                'description' => (string) ($schema['description'] ?? ''),
            ];
        }

        return $parameters;
    }
}

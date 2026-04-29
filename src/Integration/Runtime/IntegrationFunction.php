<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

use OpenCompany\IntegrationCore\Contracts\ToolProvider;

final readonly class IntegrationFunction
{
    /**
     * @param  array<string, array<string, mixed>>  $parameters
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $accounts
     * @param  array<string, mixed>  $capabilities
     */
    public function __construct(
        public string $provider,
        public string $function,
        public string $slug,
        public string $title,
        public string $description,
        public string $operation,
        public array $parameters,
        public array $meta,
        public ToolProvider $toolProvider,
        public string $toolClass,
        public bool $active,
        public bool $configured,
        public array $accounts = [],
        public array $capabilities = [],
    ) {}

    public function fullName(): string
    {
        return "{$this->provider}.{$this->function}";
    }

    /**
     * @return list<string>
     */
    public function requiredParameters(): array
    {
        $required = [];

        foreach ($this->parameters as $name => $schema) {
            if (($schema['required'] ?? false) === true) {
                $required[] = $name;
            }
        }

        return $required;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        $properties = [];

        foreach ($this->parameters as $name => $schema) {
            $property = [
                'type' => $schema['type'] ?? 'string',
                'description' => $schema['description'] ?? '',
            ];

            foreach (['enum', 'items', 'properties', 'default'] as $key) {
                if (array_key_exists($key, $schema)) {
                    $property[$key] = $schema[$key];
                }
            }

            $properties[$name] = $property;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $this->requiredParameters(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'function' => $this->function,
            'name' => $this->fullName(),
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'operation' => $this->operation,
            'active' => $this->active,
            'configured' => $this->configured,
            'accounts' => $this->accounts,
            'auth' => $this->capabilities['auth'] ?? 'none',
            'auth_strategy' => $this->capabilities['auth_strategy'] ?? 'none',
            'host_availability' => $this->capabilities['host_availability'] ?? [],
            'runtime_requirements' => $this->capabilities['runtime_requirements'] ?? [],
            'compatibility' => $this->capabilities['compatibility'] ?? [],
            'compatibility_summary' => $this->capabilities['compatibility_summary'] ?? '',
            'cli_setup_supported' => $this->capabilities['cli_setup_supported'] ?? true,
            'cli_runtime_supported' => $this->capabilities['cli_runtime_supported'] ?? true,
            'input_schema' => $this->inputSchema(),
        ];
    }
}

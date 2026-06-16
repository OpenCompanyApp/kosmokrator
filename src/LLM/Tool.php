<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Kosmokrator\LLM\ValueObjects\Concerns\HasProviderOptions;

final class Tool
{
    use HasProviderOptions;

    private string $name = '';

    private string $description = '';

    /** @var array<string, array<string, mixed>> */
    private array $parameters = [];

    /** @var list<string> */
    private array $required = [];

    /** @var callable|null */
    private $handler = null;

    public function asName(string $name): self
    {
        return $this->as($name);
    }

    public function as(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function for(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function using(callable $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function withoutErrorHandling(): self
    {
        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function parametersAsArray(): array
    {
        return $this->parameters;
    }

    /**
     * @return list<string>
     */
    public function requiredParameters(): array
    {
        return $this->required;
    }

    public function withStringParameter(string $name, string $description = '', bool $required = true): self
    {
        return $this->withParameter($name, ['type' => 'string', 'description' => $description], $required);
    }

    public function withNumberParameter(string $name, string $description = '', bool $required = true): self
    {
        return $this->withParameter($name, ['type' => 'number', 'description' => $description], $required);
    }

    public function withBooleanParameter(string $name, string $description = '', bool $required = true): self
    {
        return $this->withParameter($name, ['type' => 'boolean', 'description' => $description], $required);
    }

    /**
     * @param  list<string>  $options
     */
    public function withEnumParameter(string $name, string $description = '', array $options = [], bool $required = true): self
    {
        return $this->withParameter($name, [
            'type' => 'string',
            'description' => $description,
            'enum' => $options,
        ], $required);
    }

    public function withArrayParameter(string $name, string $description = '', mixed $items = null, bool $required = true): self
    {
        $schema = ['type' => 'array', 'description' => $description];
        if ($items !== null) {
            $schema['items'] = ['type' => 'string'];
        }

        return $this->withParameter($name, $schema, $required);
    }

    /** @param array<string, mixed> $schema */
    private function withParameter(string $name, array $schema, bool $required): self
    {
        $this->parameters[$name] = $schema;
        if ($required && ! in_array($name, $this->required, true)) {
            $this->required[] = $name;
        }

        return $this;
    }

    public function execute(array $args): mixed
    {
        if ($this->handler === null) {
            return '';
        }

        return ($this->handler)(...$args);
    }

    public function handle(mixed ...$args): mixed
    {
        return $this->execute($args);
    }
}

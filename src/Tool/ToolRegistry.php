<?php

namespace Kosmokrator\Tool;

use Prism\Prism\Tool as PrismTool;

class ToolRegistry
{
    /** @var ToolInterface[] */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return ToolInterface[]
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Convert all registered tools to Prism Tool instances.
     *
     * @return PrismTool[]
     */
    public function toPrismTools(): array
    {
        return array_map(fn (ToolInterface $tool) => $this->toPrismTool($tool), array_values($this->tools));
    }

    private function toPrismTool(ToolInterface $tool): PrismTool
    {
        // Prism calls tool handlers with named arguments matching the parameter names
        $prismTool = (new PrismTool())
            ->as($tool->name())
            ->for($tool->description())
            ->using(function (...$args) use ($tool) {
                $result = $tool->execute($args);

                return $result->output;
            });

        foreach ($tool->parameters() as $name => $schema) {
            $required = in_array($name, $tool->requiredParameters());
            $type = $schema['type'] ?? 'string';
            $description = $schema['description'] ?? '';

            match ($type) {
                'string' => $prismTool->withStringParameter($name, $description, $required),
                'number', 'integer' => $prismTool->withNumberParameter($name, $description, $required),
                'boolean' => $prismTool->withBooleanParameter($name, $description, $required),
                default => $prismTool->withStringParameter($name, $description, $required),
            };
        }

        return $prismTool;
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Tool;

use Kosmokrator\Agent\AgentContext;
use Kosmokrator\LLM\Schema\StringSchema;
use Kosmokrator\LLM\Tool as LlmTool;
use Kosmokrator\LLM\ToolCallMapper;

/**
 * Central registry of all available ToolInterface implementations.
 *
 * Supports lookup, scoped filtering by agent type, and conversion to LLM Tool
 * instances for the LLM provider layer.
 */
class ToolRegistry
{
    /** @var ToolInterface[] */
    private array $tools = [];

    /** Register a tool instance keyed by its name. */
    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** Look up a tool by name, or return null if not registered. */
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
     * Create a filtered copy of this registry for the given agent context.
     * Only includes tools allowed by the agent type.
     * Does NOT include SubagentTool — that's added externally per-context.
     */
    public function scoped(AgentContext $context): self
    {
        $allowed = $context->type->allowedTools();
        $scoped = new self;

        foreach ($this->tools as $name => $tool) {
            if ($name === 'subagent') {
                continue;
            }
            if (in_array($name, $allowed, true)) {
                $scoped->register($tool);
            }
        }

        return $scoped;
    }

    /**
     * Convert all registered tools to LLM Tool instances.
     *
     * @return LlmTool[]
     */
    public function toLlmTools(): array
    {
        return array_map(fn (ToolInterface $tool) => $this->toLlmTool($tool), array_values($this->tools));
    }

    /**
     * Convert a single ToolInterface into a LLM Tool instance with mapped parameters.
     */
    private function toLlmTool(ToolInterface $tool): LlmTool
    {
        // LLM transport calls tool handlers with named arguments matching the parameter names
        $llmTool = (new LlmTool)
            ->as($tool->name())
            ->for($tool->description())
            ->using(function (...$args) use ($tool) {
                $result = $tool->execute($args);

                return $result->success ? $result->output : ToolCallMapper::ERROR_PREFIX.$result->output;
            });

        foreach ($tool->parameters() as $name => $schema) {
            $required = in_array($name, $tool->requiredParameters());
            $type = $schema['type'] ?? 'string';
            $description = $schema['description'] ?? '';

            match ($type) {
                'string' => $llmTool->withStringParameter($name, $description, $required),
                'number', 'integer' => $llmTool->withNumberParameter($name, $description, $required),
                'boolean' => $llmTool->withBooleanParameter($name, $description, $required),
                'enum' => $llmTool->withEnumParameter($name, $description, $schema['options'] ?? [], $required),
                'array' => $llmTool->withArrayParameter($name, $description, new StringSchema('item', 'Array item'), $required),
                default => $llmTool->withStringParameter($name, $description, $required),
            };
        }

        return $llmTool;
    }
}

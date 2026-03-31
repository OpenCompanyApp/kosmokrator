<?php

namespace Kosmokrator\Tests\Unit\Tool;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolRegistryEnumArrayTest extends TestCase
{
    public function test_enum_parameter_maps_to_prism_enum(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'test_enum';
            }

            public function description(): string
            {
                return 'Test';
            }

            public function parameters(): array
            {
                return [
                    'color' => [
                        'type' => 'enum',
                        'description' => 'Pick a color',
                        'options' => ['red', 'green', 'blue'],
                    ],
                ];
            }

            public function requiredParameters(): array
            {
                return ['color'];
            }

            public function execute(array $args): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $prismTools = $registry->toPrismTools();
        $this->assertCount(1, $prismTools);

        // Verify the tool was created without error and has the name
        $this->assertSame('test_enum', $prismTools[0]->name());
    }

    public function test_array_parameter_maps_to_prism_array(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'test_array';
            }

            public function description(): string
            {
                return 'Test';
            }

            public function parameters(): array
            {
                return [
                    'items' => [
                        'type' => 'array',
                        'description' => 'List of items',
                        'items' => ['type' => 'string'],
                    ],
                ];
            }

            public function requiredParameters(): array
            {
                return ['items'];
            }

            public function execute(array $args): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $prismTools = $registry->toPrismTools();
        $this->assertCount(1, $prismTools);
        $this->assertSame('test_array', $prismTools[0]->name());
    }

    public function test_unknown_type_falls_back_to_string(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'test_unknown';
            }

            public function description(): string
            {
                return 'Test';
            }

            public function parameters(): array
            {
                return [
                    'data' => ['type' => 'object', 'description' => 'Some object'],
                ];
            }

            public function requiredParameters(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                return ToolResult::success('ok');
            }
        });

        $prismTools = $registry->toPrismTools();
        $this->assertCount(1, $prismTools);
        $this->assertSame('test_unknown', $prismTools[0]->name());
    }
}

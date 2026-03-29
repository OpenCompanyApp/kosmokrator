<?php

namespace Kosmokrator\Tests\Unit\Tool;

use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\Tool\ToolResult;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Tool as PrismTool;

class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    public function test_register_and_get(): void
    {
        $tool = $this->makeTool('foo');
        $this->registry->register($tool);

        $this->assertSame($tool, $this->registry->get('foo'));
    }

    public function test_get_returns_null_for_unknown(): void
    {
        $this->assertNull($this->registry->get('nonexistent'));
    }

    public function test_all_returns_all_registered(): void
    {
        $this->registry->register($this->makeTool('a'));
        $this->registry->register($this->makeTool('b'));
        $this->registry->register($this->makeTool('c'));

        $all = $this->registry->all();

        $this->assertCount(3, $all);
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
        $this->assertArrayHasKey('c', $all);
    }

    public function test_register_overwrites_duplicate_name(): void
    {
        $tool1 = $this->makeTool('dup', 'First');
        $tool2 = $this->makeTool('dup', 'Second');

        $this->registry->register($tool1);
        $this->registry->register($tool2);

        $this->assertCount(1, $this->registry->all());
        $this->assertSame($tool2, $this->registry->get('dup'));
    }

    public function test_to_prism_tools_returns_prism_tool_array(): void
    {
        $this->registry->register($this->makeTool('a'));
        $this->registry->register($this->makeTool('b'));

        $prismTools = $this->registry->toPrismTools();

        $this->assertCount(2, $prismTools);
        $this->assertInstanceOf(PrismTool::class, $prismTools[0]);
        $this->assertInstanceOf(PrismTool::class, $prismTools[1]);
    }

    public function test_to_prism_tools_maps_name_and_description(): void
    {
        $this->registry->register($this->makeTool('my_tool', 'My description'));

        $prismTools = $this->registry->toPrismTools();

        $this->assertSame('my_tool', $prismTools[0]->name());
        $this->assertSame('My description', $prismTools[0]->description());
    }

    public function test_to_prism_tools_maps_string_parameter(): void
    {
        $tool = $this->makeToolWithParams('t', [
            'path' => ['type' => 'string', 'description' => 'File path'],
        ], ['path']);

        $this->registry->register($tool);
        $prismTools = $this->registry->toPrismTools();

        $params = $prismTools[0]->parametersAsArray();
        $this->assertArrayHasKey('path', $params);
        $this->assertSame('string', $params['path']['type']);
    }

    public function test_to_prism_tools_maps_number_parameter(): void
    {
        $tool = $this->makeToolWithParams('t', [
            'count' => ['type' => 'number', 'description' => 'Count'],
        ], ['count']);

        $this->registry->register($tool);
        $prismTools = $this->registry->toPrismTools();

        $params = $prismTools[0]->parametersAsArray();
        $this->assertArrayHasKey('count', $params);
        $this->assertSame('number', $params['count']['type']);
    }

    public function test_to_prism_tools_maps_integer_parameter(): void
    {
        $tool = $this->makeToolWithParams('t', [
            'offset' => ['type' => 'integer', 'description' => 'Offset'],
        ], ['offset']);

        $this->registry->register($tool);
        $prismTools = $this->registry->toPrismTools();

        $params = $prismTools[0]->parametersAsArray();
        $this->assertArrayHasKey('offset', $params);
        // integer maps through withNumberParameter, which sets type to 'number'
        $this->assertSame('number', $params['offset']['type']);
    }

    public function test_to_prism_tools_maps_boolean_parameter(): void
    {
        $tool = $this->makeToolWithParams('t', [
            'verbose' => ['type' => 'boolean', 'description' => 'Verbose'],
        ], ['verbose']);

        $this->registry->register($tool);
        $prismTools = $this->registry->toPrismTools();

        $params = $prismTools[0]->parametersAsArray();
        $this->assertArrayHasKey('verbose', $params);
        $this->assertSame('boolean', $params['verbose']['type']);
    }

    public function test_to_prism_tools_maps_unknown_type_as_string(): void
    {
        $tool = $this->makeToolWithParams('t', [
            'data' => ['type' => 'object', 'description' => 'Some data'],
        ], ['data']);

        $this->registry->register($tool);
        $prismTools = $this->registry->toPrismTools();

        $params = $prismTools[0]->parametersAsArray();
        $this->assertArrayHasKey('data', $params);
        $this->assertSame('string', $params['data']['type']);
    }

    public function test_to_prism_tools_marks_required_parameters(): void
    {
        $tool = $this->makeToolWithParams('t', [
            'path' => ['type' => 'string', 'description' => 'Required'],
            'limit' => ['type' => 'integer', 'description' => 'Optional'],
        ], ['path']);

        $this->registry->register($tool);
        $prismTools = $this->registry->toPrismTools();

        $required = $prismTools[0]->requiredParameters();
        $this->assertContains('path', $required);
        $this->assertNotContains('limit', $required);
    }

    public function test_to_prism_tool_handler_calls_execute(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn('test_tool');
        $tool->method('description')->willReturn('Test');
        $tool->method('parameters')->willReturn([
            'input' => ['type' => 'string', 'description' => 'Input'],
        ]);
        $tool->method('requiredParameters')->willReturn(['input']);
        $tool->expects($this->once())
            ->method('execute')
            ->with(['input' => 'hello'])
            ->willReturn(ToolResult::success('result'));

        $this->registry->register($tool);
        $prismTools = $this->registry->toPrismTools();

        $output = $prismTools[0]->handle(input: 'hello');
        $this->assertSame('result', $output);
    }

    public function test_to_prism_tools_empty_registry(): void
    {
        $this->assertEmpty($this->registry->toPrismTools());
    }

    private function makeTool(string $name, string $description = 'Test tool'): ToolInterface
    {
        return $this->makeToolWithParams($name, [], [], $description);
    }

    private function makeToolWithParams(string $name, array $params, array $required = [], string $description = 'Test tool'): ToolInterface
    {
        return new class($name, $description, $params, $required) implements ToolInterface {
            public function __construct(
                private readonly string $toolName,
                private readonly string $desc,
                private readonly array $params,
                private readonly array $required,
            ) {}

            public function name(): string { return $this->toolName; }
            public function description(): string { return $this->desc; }
            public function parameters(): array { return $this->params; }
            public function requiredParameters(): array { return $this->required; }
            public function execute(array $args): ToolResult { return ToolResult::success('ok'); }
        };
    }
}

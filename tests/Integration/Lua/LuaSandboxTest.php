<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\Lua;

use Kosmokrator\Lua\LuaResult;
use Kosmokrator\Lua\LuaSandboxService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the Lua sandbox execution engine.
 * Tests real Lua code execution via the lua-sandbox extension.
 *
 * Note: Lua return values are always arrays (Lua supports multiple returns).
 * $result->result is [42] not 42.
 */
class LuaSandboxTest extends TestCase
{
    private LuaSandboxService $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = new LuaSandboxService;
    }

    // ── Basic execution ────────────────────────────────────────────────

    public function test_simple_print(): void
    {
        $result = $this->sandbox->execute('print("hello world")');

        $this->assertTrue($result->succeeded());
        $this->assertNull($result->error);
        $this->assertSame('hello world', $result->output);
    }

    public function test_return_value(): void
    {
        $result = $this->sandbox->execute('return 42');

        $this->assertTrue($result->succeeded());
        $this->assertSame([42], $result->result);
    }

    public function test_return_string(): void
    {
        $result = $this->sandbox->execute('return "hello"');

        $this->assertTrue($result->succeeded());
        $this->assertSame(['hello'], $result->result);
    }

    public function test_return_table(): void
    {
        $result = $this->sandbox->execute('return {x = 1, y = 2}');

        $this->assertTrue($result->succeeded());
        $this->assertIsArray($result->result);
        $this->assertSame(1, $result->result[0]['x'] ?? null);
        $this->assertSame(2, $result->result[0]['y'] ?? null);
    }

    public function test_execution_time_measured(): void
    {
        $result = $this->sandbox->execute('return 1');

        $this->assertTrue($result->succeeded());
        $this->assertGreaterThanOrEqual(0, $result->executionTime);
    }

    public function test_memory_usage_reported(): void
    {
        $result = $this->sandbox->execute('return 1');

        $this->assertTrue($result->succeeded());
        $this->assertNotNull($result->memoryUsage);
        $this->assertGreaterThan(0, $result->memoryUsage);
    }

    // ── Print/dump output ──────────────────────────────────────────────

    public function test_print_multiple_args(): void
    {
        $result = $this->sandbox->execute('print("a", "b", "c")');

        $this->assertTrue($result->succeeded());
        $this->assertSame("a\tb\tc", $result->output);
    }

    public function test_print_table_serialization(): void
    {
        $result = $this->sandbox->execute('print({name = "test", value = 42})');

        $this->assertTrue($result->succeeded());
        $this->assertStringContainsString('name', $result->output);
        $this->assertStringContainsString('test', $result->output);
        $this->assertStringContainsString('value', $result->output);
    }

    public function test_dump_function(): void
    {
        $result = $this->sandbox->execute('dump({1, 2, 3})');

        $this->assertTrue($result->succeeded());
        $this->assertStringContainsString('1', $result->output);
        $this->assertStringContainsString('2', $result->output);
        $this->assertStringContainsString('3', $result->output);
    }

    // ── Arithmetic and control flow ────────────────────────────────────

    public function test_arithmetic(): void
    {
        $result = $this->sandbox->execute('return 10 * 5 + 3');

        $this->assertSame([53], $result->result);
    }

    public function test_string_concatenation(): void
    {
        $result = $this->sandbox->execute('return "hello" .. " " .. "world"');

        $this->assertSame(['hello world'], $result->result);
    }

    public function test_for_loop(): void
    {
        $result = $this->sandbox->execute('local sum = 0; for i = 1, 10 do sum = sum + i end; return sum');

        $this->assertSame([55], $result->result);
    }

    public function test_function_definition(): void
    {
        $result = $this->sandbox->execute('
            local function fibonacci(n)
                if n <= 1 then return n end
                return fibonacci(n - 1) + fibonacci(n - 2)
            end
            return fibonacci(10)
        ');

        $this->assertSame([55], $result->result);
    }

    // ── Globals injection ──────────────────────────────────────────────

    public function test_inject_string_global(): void
    {
        $result = $this->sandbox->execute('print(greeting)', [], null, ['greeting' => 'hello']);

        $this->assertSame('hello', $result->output);
    }

    public function test_inject_number_global(): void
    {
        $result = $this->sandbox->execute('return count * 2', [], null, ['count' => 21]);

        $this->assertSame([42], $result->result);
    }

    public function test_inject_array_global(): void
    {
        $result = $this->sandbox->execute('
            local result = {}
            for i, v in ipairs(items) do
                result[i] = v * 2
            end
            return result
        ', [], null, ['items' => [1, 2, 3]]);

        $this->assertTrue($result->succeeded());
        $this->assertSame([2, 4, 6], $result->result[0]);
    }

    public function test_inject_boolean_global(): void
    {
        $result = $this->sandbox->execute('if enabled then return "on" else return "off" end', [], null, ['enabled' => true]);

        $this->assertSame(['on'], $result->result);
    }

    public function test_inject_null_global(): void
    {
        $result = $this->sandbox->execute('if value == nil then return "null" end', [], null, ['value' => null]);

        $this->assertSame(['null'], $result->result);
    }

    public function test_inject_associative_array(): void
    {
        $result = $this->sandbox->execute('return config.host .. ":" .. config.port', [], null, [
            'config' => ['host' => 'localhost', 'port' => '8080'],
        ]);

        $this->assertSame(['localhost:8080'], $result->result);
    }

    // ── Error handling ─────────────────────────────────────────────────

    public function test_runtime_error(): void
    {
        $result = $this->sandbox->execute('error("something went wrong")');

        $this->assertFalse($result->succeeded());
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('something went wrong', $result->error);
    }

    public function test_syntax_error(): void
    {
        $result = $this->sandbox->execute('function(');

        $this->assertFalse($result->succeeded());
        $this->assertNotNull($result->error);
    }

    public function test_undefined_variable_returns_nil(): void
    {
        $result = $this->sandbox->execute('return nonexistent_var');

        // Lua returns nil for undefined globals (not an error)
        $this->assertTrue($result->succeeded());
        $this->assertContains(null, $result->result);
    }

    public function test_type_error(): void
    {
        $result = $this->sandbox->execute('return "hello" + 5');

        $this->assertFalse($result->succeeded());
        $this->assertNotNull($result->error);
    }

    // ── Resource limits ────────────────────────────────────────────────

    public function test_custom_memory_limit(): void
    {
        $result = $this->sandbox->execute('local t = {}; for i = 1, 1000000 do t[i] = i end', [
            'memoryLimit' => 1024 * 1024,
        ]);

        $this->assertFalse($result->succeeded());
    }

    public function test_custom_cpu_limit(): void
    {
        $result = $this->sandbox->execute('local x = 0; while true do x = x + 1 end', [
            'cpuLimit' => 0.1,
        ]);

        $this->assertFalse($result->succeeded());
    }

    // ── LuaResult value object ─────────────────────────────────────────

    public function test_to_array(): void
    {
        $result = $this->sandbox->execute('return 42');

        $array = $result->toArray();
        $this->assertArrayHasKey('output', $array);
        $this->assertArrayHasKey('error', $array);
        $this->assertArrayHasKey('result', $array);
        $this->assertArrayHasKey('executionTime', $array);
        $this->assertArrayHasKey('memoryUsage', $array);
    }

    // ── Edge cases ─────────────────────────────────────────────────────

    public function test_empty_code(): void
    {
        $result = $this->sandbox->execute('');

        $this->assertTrue($result->succeeded());
    }

    public function test_multiline_code(): void
    {
        $result = $this->sandbox->execute(<<<'LUA'
            local function greet(name)
                return "Hello, " .. name .. "!"
            end
            print(greet("Lua"))
        LUA);

        $this->assertTrue($result->succeeded());
        $this->assertSame('Hello, Lua!', $result->output);
    }

    public function test_table_as_array(): void
    {
        $result = $this->sandbox->execute('return {10, 20, 30}');

        $this->assertSame([[10, 20, 30]], $result->result);
    }

    public function test_nested_tables(): void
    {
        $result = $this->sandbox->execute('return {outer = {inner = "deep"}}');

        $this->assertSame('deep', $result->result[0]['outer']['inner'] ?? null);
    }

    public function test_empty_table(): void
    {
        $result = $this->sandbox->execute('return {}');

        $this->assertSame([[]], $result->result);
    }

    public function test_print_and_return(): void
    {
        $result = $this->sandbox->execute('print("logged"); return "result"');

        $this->assertSame('logged', $result->output);
        $this->assertSame(['result'], $result->result);
    }

    public function test_multiple_prints(): void
    {
        $result = $this->sandbox->execute('print("line1"); print("line2"); print("line3")');

        $this->assertSame("line1\nline2\nline3", $result->output);
    }
}

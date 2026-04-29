<?php

declare(strict_types=1);

namespace Kosmokrator\Lua;

use Lua\Exception as LuaException;
use Lua\Sandbox;
use OpenCompany\IntegrationCore\Lua\LuaBridge;

class LuaSandboxService
{
    /**
     * Execute Lua code in a sandboxed environment.
     *
     * @param  array{memoryLimit?: int, cpuLimit?: float}  $options
     * @param  array<string, mixed>  $globals  Named globals to inject as Lua tables
     * @param  array<string, callable>  $phpFunctions  Dotted helper names exposed as Lua functions
     */
    public function execute(string $code, array $options = [], ?LuaBridge $bridge = null, array $globals = [], ?NativeToolBridge $nativeBridge = null, array $phpFunctions = []): LuaResult
    {
        $memoryLimit = $options['memoryLimit'] ?? 32 * 1024 * 1024; // 32 MB
        $cpuLimit = $options['cpuLimit'] ?? 30.0; // 30 seconds

        $sandbox = new Sandbox(
            memory_limit: $memoryLimit,
            cpu_limit: $cpuLimit,
        );

        $output = [];
        $this->setupPrintCapture($sandbox, $output);

        if ($bridge !== null) {
            $this->setupAppNamespace($sandbox, $bridge);
        }

        if ($nativeBridge !== null) {
            $nativeBridge->register($sandbox);
        }

        if ($phpFunctions !== []) {
            $this->registerPhpFunctions($sandbox, $phpFunctions);
        }

        foreach ($globals as $name => $value) {
            $sandbox->load("{$name} = ".$this->phpToLua($value))->call();
        }

        $this->registerJsonGlobals($sandbox);

        $start = microtime(true);

        try {
            $fn = $sandbox->load($code);
            $result = $fn();
            $elapsed = round((microtime(true) - $start) * 1000, 1);

            return new LuaResult(
                output: implode("\n", $output),
                error: null,
                result: $result,
                executionTime: $elapsed,
                memoryUsage: $sandbox->memoryUsage(),
            );
        } catch (LuaException $e) {
            $elapsed = round((microtime(true) - $start) * 1000, 1);

            return new LuaResult(
                output: implode("\n", $output),
                error: $e->getMessage(),
                result: null,
                executionTime: $elapsed,
                memoryUsage: null,
            );
        }
    }

    /**
     * Register simple dotted PHP functions as Lua functions, e.g.
     * `docs.read = function(...) return __php_globals.call("docs.read", ...) end`.
     *
     * @param  array<string, callable>  $phpFunctions
     */
    private function registerPhpFunctions(Sandbox $sandbox, array $phpFunctions): void
    {
        $sandbox->register('__php_globals', [
            'call' => function (string $name, mixed ...$args) use ($phpFunctions): mixed {
                try {
                    if (! isset($phpFunctions[$name])) {
                        throw new \RuntimeException("Unknown PHP-backed Lua helper: {$name}");
                    }

                    return $phpFunctions[$name](...$args);
                } catch (\Throwable $e) {
                    return ['__error' => $e->getMessage()];
                }
            },
        ]);

        $lines = [];
        foreach (array_keys($phpFunctions) as $name) {
            $parts = explode('.', $name);
            if (count($parts) !== 2) {
                continue;
            }

            [$table, $function] = $parts;
            $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
            $function = preg_replace('/[^A-Za-z0-9_]/', '', $function) ?? '';
            if ($table === '' || $function === '') {
                continue;
            }

            $escaped = addcslashes($name, '"\\');
            $lines[] = "{$table} = {$table} or {}";
            $lines[] = "{$table}.{$function} = function(...)
                local result = __php_globals.call(\"{$escaped}\", ...)
                if type(result) == \"table\" and result.__error then
                    error(result.__error, 2)
                end
                return result
            end";
        }

        if ($lines !== []) {
            $sandbox->load(implode("\n", $lines))->call();
        }
    }

    /**
     * Override Lua's print() and add dump() with table-aware serialization.
     *
     * Standard Lua's tostring() outputs "table: 0x..." for tables, which is
     * useless for agents trying to inspect bridge call results. The __serialize
     * helper recursively converts tables to a readable JSON-like format.
     *
     * @param  array<int, string>  $output
     */
    private function setupPrintCapture(Sandbox $sandbox, array &$output): void
    {
        $sandbox->register('__php', [
            'capture' => function ($line) use (&$output) {
                $output[] = (string) $line;
            },
        ]);

        $sandbox->load('
            local _tostring = tostring

            local function __serialize(val, indent, seen)
                local t = type(val)
                if t ~= "table" then return _tostring(val) end
                if seen[val] then return "<circular>" end
                seen[val] = true
                indent = indent or 0
                local is_array = true
                local n = 0
                for _ in pairs(val) do n = n + 1 end
                if n == 0 then return "{}" end
                for i = 1, n do
                    if val[i] == nil then is_array = false; break end
                end
                local pad = string.rep("  ", indent + 1)
                local endpad = string.rep("  ", indent)
                local parts = {}
                if is_array then
                    for i = 1, n do
                        parts[i] = pad .. __serialize(val[i], indent + 1, seen)
                    end
                    return "[\n" .. table.concat(parts, ",\n") .. "\n" .. endpad .. "]"
                else
                    for k, v in pairs(val) do
                        parts[#parts + 1] = pad .. _tostring(k) .. ": " .. __serialize(v, indent + 1, seen)
                    end
                    return "{\n" .. table.concat(parts, ",\n") .. "\n" .. endpad .. "}"
                end
            end

            tostring = function(val)
                if type(val) == "table" then return __serialize(val, 0, {}) end
                return _tostring(val)
            end

            function print(...)
                local parts = {}
                for i = 1, select("#", ...) do
                    local v = select(i, ...)
                    parts[i] = type(v) == "table" and __serialize(v, 0, {}) or tostring(v)
                end
                __php.capture(table.concat(parts, "\t"))
            end

            function dump(val)
                local s = type(val) == "table" and __serialize(val, 0, {}) or tostring(val)
                __php.capture(s)
                return val
            end
        ')->call();
    }

    /**
     * Register the app.* namespace using metatables to route calls to PHP via LuaBridge.
     *
     * Creates an infinitely nested proxy table where any app.X.Y.Z(args) call
     * is intercepted and routed to __app.call("X.Y.Z", args).
     */
    private function setupAppNamespace(Sandbox $sandbox, LuaBridge $bridge): void
    {
        $sandbox->register('__app', [
            'call' => function (string $path, mixed ...$args) use ($bridge) {
                try {
                    return $bridge->call($path, ...$args);
                } catch (\Throwable $e) {
                    return ['__error' => $e->getMessage()];
                }
            },
        ]);

        $sandbox->load('
            local function make_namespace(path)
                return setmetatable({}, {
                    __index = function(self, key)
                        local child = path == "" and key or (path .. "." .. key)
                        local ns = make_namespace(child)
                        rawset(self, key, ns)
                        return ns
                    end,
                    __call = function(self, ...)
                        local result = __app.call(path, ...)
                        if type(result) == "table" and result.__error then
                            error(result.__error, 2)
                        end
                        return result
                    end
                })
            end
            app = make_namespace("")
        ')->call();
    }

    /**
     * Serialize a PHP value to a Lua literal.
     */
    private function phpToLua(mixed $value): string
    {
        if ($value === null) {
            return 'nil';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return '"'.addcslashes($value, "\"\\\n\r\t").'"';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '{}';
            }

            $parts = [];
            $isSequential = array_keys($value) === range(0, count($value) - 1);

            foreach ($value as $k => $v) {
                if ($isSequential) {
                    $parts[] = $this->phpToLua($v);
                } else {
                    $key = is_int($k) ? "[{$k}]" : $k;
                    $parts[] = "{$key} = ".$this->phpToLua($v);
                }
            }

            return '{'.implode(', ', $parts).'}';
        }

        return '"'.addcslashes((string) $value, "\"\\\n\r\t").'"';
    }

    /**
     * Register `json.decode()`, `json.encode()`, and `regex.*` as Lua globals.
     *
     * JSON bridges PHP's json_decode/json_encode so Lua scripts can parse
     * JSON output from bash commands and other string sources.
     *
     * Regex bridges PHP's PCRE so Lua scripts can use lookaheads, non-greedy
     * quantifiers, Unicode properties, and other patterns that Lua's built-in
     * string matching doesn't support.
     */
    private function registerJsonGlobals(Sandbox $sandbox): void
    {
        $sandbox->register('__json', [
            'decode' => function (string $json): mixed {
                return json_decode($json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
            },
            'encode' => function (mixed $value): string {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            },
        ]);

        $sandbox->register('__regex', [
            'match' => function (string $subject, string $pattern, int $flags = 0): mixed {
                $pregFlags = match ($flags) {
                    0,
                    PREG_OFFSET_CAPTURE,
                    PREG_UNMATCHED_AS_NULL,
                    PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL => $flags,
                    default => 0,
                };

                if (preg_match($pattern, $subject, $matches, $pregFlags) === 1) {
                    return $matches;
                }

                return null;
            },
            'match_all' => function (string $subject, string $pattern, int $flags = PREG_PATTERN_ORDER): array {
                if (preg_match_all($pattern, $subject, $matches, $flags) > 0) {
                    return $matches;
                }

                return [];
            },
            'gsub' => function (string $subject, string $pattern, string $replacement, int $limit = -1): string {
                return preg_replace($pattern, $replacement, $subject, $limit) ?? $subject;
            },
        ]);

        $sandbox->load('
            json = {
                decode = function(s)
                    if type(s) ~= "string" then
                        error("json.decode: expected string, got " .. type(s), 2)
                    end
                    return __json.decode(s)
                end,
                encode = function(v)
                    return __json.encode(v)
                end
            }

            regex = {
                match = function(subject, pattern, flags)
                    if type(subject) ~= "string" then
                        error("regex.match: expected string subject, got " .. type(subject), 2)
                    end
                    if type(pattern) ~= "string" then
                        error("regex.match: expected string pattern, got " .. type(pattern), 2)
                    end
                    return __regex.match(subject, pattern, flags or 0)
                end,
                match_all = function(subject, pattern, flags)
                    if type(subject) ~= "string" then
                        error("regex.match_all: expected string subject, got " .. type(subject), 2)
                    end
                    if type(pattern) ~= "string" then
                        error("regex.match_all: expected string pattern, got " .. type(pattern), 2)
                    end
                    return __regex.match_all(subject, pattern, flags or 0)
                end,
                gsub = function(subject, pattern, replacement, limit)
                    if type(subject) ~= "string" then
                        error("regex.gsub: expected string subject, got " .. type(subject), 2)
                    end
                    if type(pattern) ~= "string" then
                        error("regex.gsub: expected string pattern, got " .. type(pattern), 2)
                    end
                    if type(replacement) ~= "string" then
                        error("regex.gsub: expected string replacement, got " .. type(replacement), 2)
                    end
                    return __regex.gsub(subject, pattern, replacement, limit or -1)
                end,
            }
        ')->call();
    }
}

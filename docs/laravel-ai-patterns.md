# Design Patterns Worth Adopting from Laravel AI SDK

Laravel AI SDK (`laravel/ai`) is a layer built on top of Prism-PHP. KosmoKrator uses Prism directly (lighter, no web-app assumptions), but several of laravel/ai's architectural patterns are worth adopting.

---

## 1. Tool Interface Pattern (Schema via JSON Schema)

### What laravel/ai does

Tools implement a `schema()` method that uses `illuminate/json-schema` — a fluent builder that produces valid JSON Schema objects:

```php
// laravel/ai approach
public function schema(JsonSchema $schema): array
{
    return [
        'path' => $schema->string()
            ->description('Absolute path to the file')
            ->required(),
        'offset' => $schema->integer()
            ->description('Line number to start reading from'),
        'limit' => $schema->integer()
            ->description('Max lines to read')
            ->default(200),
    ];
}
```

This produces the exact `input_schema` JSON Schema that LLM providers expect, without hand-writing JSON arrays.

### Why this matters

- **Type safety**: The builder prevents invalid schemas at compile time (e.g., you can't set `minimum` on a string parameter).
- **Self-documenting**: Tool definitions read like API docs.
- **Provider-agnostic**: JSON Schema is the universal format — Anthropic, OpenAI, and MCP all use it.
- **Lua bridge compatibility**: When auto-generating Lua API docs from tool schemas (for code mode), a structured schema object is far easier to traverse than a raw array.

### How to adopt in KosmoKrator

`illuminate/json-schema` is already available — it ships with `laravel/framework` v13 (transitive via Prism). Use it in `ToolInterface`:

```php
namespace Kosmokrator\Tool;

use Illuminate\JsonSchema\JsonSchema;

interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function schema(JsonSchema $schema): array;
    public function execute(array $args): ToolResult;
}
```

The `ToolRegistry` converts these schemas to Prism's `Tool` format when building LLM requests, and to Lua function signatures when generating API docs for code mode.

---

## 2. Middleware Pipeline for Agents

### What laravel/ai does

Agents can declare middleware that wraps every tool call or LLM interaction:

```php
class MyAgent extends Agent
{
    public function middleware(): array
    {
        return [
            new RateLimitMiddleware(maxPerMinute: 60),
            new LoggingMiddleware(),
            new ApprovalMiddleware(tools: ['bash', 'file_write']),
        ];
    }
}
```

Each middleware gets the request/context, can modify it, pass it through (`$next($request)`), or short-circuit (e.g., deny execution, ask for approval).

### Why this matters

KosmoKrator needs several cross-cutting concerns that are best modeled as middleware:

| Concern | Without middleware | With middleware |
|---------|-------------------|----------------|
| **Tool approval** | if/else in AgentLoop | `ApprovalMiddleware` wraps dangerous tools |
| **Cost tracking** | Manual token counting | `CostTrackingMiddleware` intercepts every LLM call |
| **Rate limiting** | Ad-hoc sleep/retry | `RateLimitMiddleware` with token bucket |
| **Audit logging** | Scattered log calls | `AuditMiddleware` logs every tool execution |
| **Sandboxing policy** | Hardcoded in BashTool | `SandboxMiddleware` enforces blocked commands |

### How to adopt in KosmoKrator

Implement a simple pipeline — no need for Laravel's full `Pipeline` class:

```php
namespace Kosmokrator\Agent;

interface AgentMiddleware
{
    public function handle(AgentContext $context, callable $next): mixed;
}
```

The `AgentLoop` runs the middleware stack around each tool execution:

```php
$pipeline = array_reduce(
    array_reverse($this->middleware),
    fn ($next, $middleware) => fn ($ctx) => $middleware->handle($ctx, $next),
    fn ($ctx) => $this->executeTool($ctx)
);

$result = $pipeline($context);
```

This keeps the `AgentLoop` clean — tool approval, logging, cost tracking are all separate, composable middleware classes.

---

## 3. Provider Failover / Retry Strategy

### What laravel/ai does

Agents can declare fallback providers that activate automatically on failure:

```php
class MyAgent extends Agent
{
    public function provider(): array|string
    {
        return [
            'anthropic/claude-sonnet-4-20250514',     // primary
            'openai/gpt-4.1',                          // fallback 1
            'groq/llama-3.3-70b-versatile',            // fallback 2
        ];
    }
}
```

On rate limit (429), server error (5xx), or timeout, laravel/ai automatically retries with the next provider in the list. It handles provider-specific error codes (Anthropic's 529 overloaded, OpenAI's 413 context too long).

### Why this matters

- **Reliability**: API rate limits and outages are common. Automatic failover keeps the agent running without user intervention.
- **Cost optimization**: Primary provider can be the best model; fallback can be cheaper/faster for when the primary is down.
- **Graceful degradation**: Better to get a response from a weaker model than to error out entirely.

### How to adopt in KosmoKrator

Wrap `PrismService` with retry logic:

```php
namespace Kosmokrator\LLM;

class PrismService
{
    private array $providers; // from config('kosmokrator.agent.providers')

    public function stream(array $messages, array $tools): \Generator
    {
        $lastException = null;

        foreach ($this->providers as $provider) {
            try {
                yield from $this->buildRequest($provider, $messages, $tools)->asStream();
                return;
            } catch (PrismRateLimitedException|PrismServerException $e) {
                $lastException = $e;
                // Log failover, continue to next provider
            }
        }

        throw $lastException;
    }
}
```

Config in `kosmokrator.yaml`:

```yaml
agent:
  providers:
    - provider: anthropic
      model: claude-sonnet-4-20250514
    - provider: openai
      model: gpt-4.1
    - provider: ollama
      model: llama3.3
```

This gives you automatic failover with zero changes to `AgentLoop` — it just calls `PrismService::stream()` and gets responses regardless of which provider served them.

---

## Summary

| Pattern | Complexity to adopt | Value for KosmoKrator |
|---------|--------------------|-----------------------|
| JSON Schema tool definitions | Low (dependency already available) | High — cleaner tools, Lua doc generation |
| Agent middleware pipeline | Medium (20-30 lines of pipeline code) | High — keeps AgentLoop clean, enables approval/logging/cost tracking |
| Provider failover | Low (wrap PrismService) | Medium — reliability for daily use |

All three patterns can be adopted incrementally without pulling in laravel/ai as a dependency. They're architectural ideas, not library lock-in.

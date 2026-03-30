# Streaming LLM Responses

## Context

Both renderers currently buffer full LLM responses before displaying. The TUI renderer has live `MarkdownWidget` rendering ready (`streamChunk()` + `processRender()`), but `AgentLoop` always calls `chat()` which blocks until the complete response arrives. Streaming would improve perceived responsiveness in `/ask` and `/plan` modes where the LLM produces longer text output. Lower priority for tool-heavy `/edit` mode.

## Current Architecture

```
AgentLoop.run()
  → $llm->chat(messages, tools)     ← blocks until complete response
  → $ui->streamChunk($fullText)     ← dumps entire text at once
  → $ui->streamComplete()
```

**PrismService** has `stream()` returning `Generator<StreamEvent>` — never called.
**AsyncLlmClient** has no streaming — `$response->getBody()->buffer()` reads entire body.
**TUI renderer** has live widget updating — built and waiting.
**ANSI renderer** buffers chunks then renders markdown at the end.

## Design

### Three layers need changes

**1. LlmClientInterface — add `stream()` method**

```php
/**
 * @return Generator<StreamEvent>
 */
public function stream(array $messages, array $tools = [], ?Cancellation $cancellation = null): Generator;
```

PrismService already has this. AsyncLlmClient needs it.

**2. AsyncLlmClient — SSE parsing**

Replace `$body->buffer()` with line-by-line SSE reading from Amp's async body stream.

SSE format (Anthropic):
```
event: content_block_delta
data: {"delta":{"type":"text_delta","text":"Hello"}}

event: message_delta
data: {"delta":{"stop_reason":"end_turn"},"usage":{"input_tokens":123,"output_tokens":45}}
```

Yield typed `StreamEvent` objects (reuse Prism's event classes):
- `TextDeltaEvent` — incremental text chunk
- `ToolCallDeltaEvent` — incremental tool call JSON fragment
- `StreamEndEvent` — finish reason, usage, final tool calls

**3. AgentLoop — stream-aware run loop**

Replace:
```php
$response = $this->llm->chat(...);
$this->ui->streamChunk($response->text);
```

With:
```php
$text = '';
$toolCallBuffers = [];  // id → accumulated JSON
$usage = null;
$finishReason = null;

foreach ($this->llm->stream($messages, $tools, $cancellation) as $event) {
    if ($event instanceof TextDeltaEvent) {
        $text .= $event->delta;
        $this->ui->streamChunk($event->delta);  // live incremental display
    }
    if ($event instanceof ToolCallDeltaEvent) {
        $toolCallBuffers[$event->toolId] = ($toolCallBuffers[$event->toolId] ?? '') . $event->delta;
    }
    if ($event instanceof StreamEndEvent) {
        $finishReason = $event->finishReason;
        $usage = $event->usage;
    }
}

$this->ui->streamComplete();
// Parse accumulated tool call JSON buffers into ToolCall objects
// Continue with tool execution as before
```

Tool calls only complete at stream end — execution logic unchanged.

### RetryableLlmClient

Wrap `stream()` with retry on initial connection failure only. Mid-stream failures cannot be retried (partial response already displayed). On mid-stream error, yield an error event or throw — AgentLoop handles it.

### Fallback

If provider doesn't support streaming (`supportsStreaming() === false`), fall back to `chat()` with the current buffer-then-display behavior. No regression for non-streaming providers.

## Tool Call Streaming Behavior

Tool arguments arrive as JSON fragments:
```
{"path":              ← ToolCallDeltaEvent
"src/file.php"}       ← ToolCallDeltaEvent
```

Must accumulate and parse at `content_block_stop`. The final `ToolCall` objects are only reliable at stream end. This means tool execution timing is unchanged — streaming only speeds up text display, not tool execution.

## Files

| Action | File |
|--------|------|
| **Modify** | `src/LLM/LlmClientInterface.php` — add `stream()` |
| **Modify** | `src/LLM/AsyncLlmClient.php` — implement SSE parsing + `stream()` |
| **Modify** | `src/LLM/RetryableLlmClient.php` — wrap `stream()` with connection retry |
| **Modify** | `src/Agent/AgentLoop.php` — stream-aware `run()` loop |
| **Modify** | `src/UI/Ansi/AnsiRenderer.php` — optional: incremental echo instead of buffer |
| **None** | `src/UI/Tui/TuiRenderer.php` — already has live widget updating |

## Edge Cases

- **Non-streaming providers** — fallback to `chat()`, no visual change
- **Mid-stream disconnect** — show partial text, log error, don't retry
- **Empty stream** (immediate tool calls, no text) — skip streaming, go straight to tool execution
- **Thinking tokens** (Claude extended thinking) — `ThinkingEvent` can show a "thinking" indicator, discard content before `TextDeltaEvent` begins
- **Mixed text + tool calls** — text streams live, tool call JSON accumulates silently

## Effort Estimate

- SSE parser in AsyncLlmClient: medium (line protocol + provider-specific JSON shapes)
- AgentLoop stream loop: small (iterate events, dispatch to UI)
- RetryableLlmClient wrapper: small
- ANSI incremental rendering: small (optional)
- Testing: medium (mock SSE streams, partial responses, error cases)

## Priority

Medium-low. Biggest impact in `/ask` and `/plan` modes. Minimal impact in `/edit` mode where tool calls dominate response time. Implement after web tools, cost tracking, and deduplication.

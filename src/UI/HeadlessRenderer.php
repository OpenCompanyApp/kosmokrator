<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiAnimation;

/**
 * Headless renderer for non-interactive CLI execution.
 *
 * Writes structured output to stdout/stderr depending on the output format:
 *
 * - **text**: Final response on stdout, progress/tool diagnostics on stderr.
 * - **json**: Single JSON blob on stdout at teardown, progress on stderr.
 * - **stream-json**: NDJSON events on stdout as they happen.
 *
 * The stdout/stderr split ensures `result=$(kosmokrator -p "task")` captures
 * only the final response, while diagnostics are visible in the terminal.
 */
class HeadlessRenderer implements RendererInterface
{
    private readonly bool $useColor;

    /** @var list<array{name: string, args: array, output?: string, success?: bool}> Tool call log for JSON output */
    private array $toolCalls = [];

    /** @var list<array{type: string, timestamp: float, ...}> Collected events for JSON mode */
    private array $events = [];

    private int $tokensIn = 0;

    private int $tokensOut = 0;

    private float $startTime;

    /** @param (\Closure(): ?Cancellation)|Cancellation|null $cancellation */
    public function __construct(
        private readonly OutputFormat $format = OutputFormat::Text,
        private readonly \Closure|Cancellation|null $cancellation = null,
    ) {
        $this->startTime = microtime(true);
        $this->useColor = function_exists('posix_isatty') && posix_isatty(STDERR);
    }

    // ─── CoreRendererInterface ───────────────────────────────────────────

    public function initialize(): void
    {
        // No-op: no terminal setup needed for headless
    }

    public function renderIntro(bool $animated): void {}

    public function prompt(): string
    {
        // Headless mode never calls prompt() — the task comes from CLI args
        return '';
    }

    public function showUserMessage(string $text): void
    {
        if ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('user_message', ['text' => $text]);
        }
    }

    public function setPhase(AgentPhase $phase): void
    {
        if ($this->format === OutputFormat::Text) {
            $label = $phase->label();
            $this->writeStderr($this->dim("  [{$label}]"));
        } elseif ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('phase', ['phase' => $phase->value]);
        }
    }

    public function showThinking(): void {}

    public function clearThinking(): void {}

    public function showCompacting(): void
    {
        if ($this->format === OutputFormat::Text) {
            $this->writeStderr($this->dim('  Compacting context...'));
        }
    }

    public function clearCompacting(): void {}

    public function getCancellation(): ?Cancellation
    {
        if ($this->cancellation instanceof \Closure) {
            return ($this->cancellation)();
        }

        return $this->cancellation;
    }

    public function showReasoningContent(string $content): void
    {
        if ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('reasoning', ['content' => $content]);
        }
        // Text/JSON modes skip reasoning output in headless
    }

    public function streamChunk(string $text): void
    {
        if ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('text_delta', ['delta' => $text]);
        }
        // Text mode: don't stream to stdout (we write the final result at the end)
        // JSON mode: collect for final blob
    }

    public function streamComplete(): void
    {
        if ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('stream_end', []);
        }
    }

    public function showToolCall(string $name, array $args): void
    {
        $entry = ['name' => $name, 'args' => $args];
        $this->toolCalls[] = $entry;

        if ($this->format === OutputFormat::Text) {
            $preview = $this->formatToolCallPreview($name, $args);
            $this->writeStderr($this->dim("  → {$preview}"));
        } elseif ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('tool_call', $entry);
        }
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        // Update last tool call with result
        foreach (array_reverse($this->toolCalls) as $i => $tc) {
            if ($tc['name'] === $name && ! isset($tc['output'])) {
                $this->toolCalls[count($this->toolCalls) - 1 - $i]['output'] = $output;
                $this->toolCalls[count($this->toolCalls) - 1 - $i]['success'] = $success;
                break;
            }
        }

        if ($this->format === OutputFormat::Text) {
            $lines = explode("\n", $output);
            $preview = implode("\n", array_slice($lines, 0, 3));
            if (count($lines) > 3) {
                $preview .= $this->dim(' ... ('.count($lines).' lines)');
            }
            $status = $success ? '✓' : '✗';
            $this->writeStderr($this->dim("  {$status} {$name}: {$preview}"));
        } elseif ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('tool_result', [
                'name' => $name,
                'output' => $output,
                'success' => $success,
            ]);
        }
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        // Auto-approve all tool permissions in headless mode
        return 'allow';
    }

    public function showAutoApproveIndicator(string $toolName): void {}

    public function showToolExecuting(string $name): void {}

    public function updateToolExecuting(string $output): void {}

    public function clearToolExecuting(): void {}

    public function showNotice(string $message): void
    {
        if ($this->format === OutputFormat::Text) {
            $this->writeStderr($this->dim("  ℹ {$message}"));
        }
    }

    public function showMode(string $label, string $color = ''): void {}

    public function setPermissionMode(string $label, string $color): void {}

    public function consumeQueuedMessage(): ?string
    {
        return null;
    }

    public function setImmediateCommandHandler(?\Closure $handler): void {}

    public function showError(string $message): void
    {
        if ($this->format === OutputFormat::Text) {
            $this->writeStderr("  Error: {$message}");
        } elseif ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('error', ['message' => $message]);
        }
        // JSON mode: errors are collected in $this->events for the final blob
        if ($this->format === OutputFormat::Json) {
            $this->events[] = ['type' => 'error', 'timestamp' => (int) (microtime(true) * 1000), 'message' => $message];
        }
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->tokensIn = $tokensIn;
        $this->tokensOut = $tokensOut;

        if ($this->format === OutputFormat::Text) {
            $this->writeStderr($this->dim("  tokens: {$tokensIn}→{$tokensOut}  cost: \${$cost}"));
        }
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void {}

    public function teardown(): void {}

    public function showWelcome(): void {}

    public function setTaskStore(TaskStore $store): void {}

    public function refreshTaskBar(): void {}

    public function playTheogony(): void {}

    public function playPrometheus(): void {}

    public function playUnleash(): void {}

    public function playAnimation(AnsiAnimation $animation): void {}

    public function setSkillCompletions(array $completions): void {}

    // ─── DialogRendererInterface ─────────────────────────────────────────

    public function showSettings(array $currentSettings): array
    {
        return [];
    }

    public function pickSession(array $items): ?string
    {
        return null;
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        // Headless mode cannot show plan approval dialogs
        return null;
    }

    public function askUser(string $question): string
    {
        // Headless mode cannot answer questions — return empty
        return '';
    }

    public function askChoice(string $question, array $choices): string
    {
        return 'dismissed';
    }

    // ─── ConversationRendererInterface ───────────────────────────────────

    public function clearConversation(): void {}

    public function replayHistory(array $messages): void {}

    // ─── SubagentRendererInterface ───────────────────────────────────────

    public function showSubagentStatus(array $stats): void {}

    public function clearSubagentStatus(): void {}

    public function showSubagentRunning(array $entries): void {}

    public function showSubagentSpawn(array $entries): void
    {
        if ($this->format === OutputFormat::Text) {
            foreach ($entries as $entry) {
                $id = $entry['id'] ?? '?';
                $task = $entry['task'] ?? '';
                $preview = mb_substr($task, 0, 60);
                $this->writeStderr($this->dim("  ⟐ spawn {$id}: {$preview}"));
            }
        } elseif ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('subagent_spawn', ['entries' => $entries]);
        }
    }

    public function showSubagentBatch(array $entries): void
    {
        if ($this->format === OutputFormat::Text) {
            foreach ($entries as $entry) {
                $id = $entry['id'] ?? '?';
                $status = ($entry['success'] ?? false) ? '✓' : '✗';
                $this->writeStderr($this->dim("  {$status} agent {$id}"));
            }
        } elseif ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('subagent_batch', ['entries' => $entries]);
        }
    }

    public function refreshSubagentTree(array $tree): void {}

    public function setAgentTreeProvider(?\Closure $provider): void {}

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void {}

    // ─── Public helpers for AgentCommand ─────────────────────────────────

    /**
     * Emit the final result to stdout based on the output format.
     *
     * For text mode, writes the raw text. For JSON mode, writes a structured
     * blob. For stream-json, emits the result event.
     */
    public function emitResult(string $text, int $turns, int $tokensIn, int $tokensOut): void
    {
        $duration = (int) ((microtime(true) - $this->startTime) * 1000);

        match ($this->format) {
            OutputFormat::Text => $this->writeStdout($text),
            OutputFormat::Json => $this->writeStdout($this->jsonEncode([
                'type' => 'result',
                'text' => $text,
                'duration_ms' => $duration,
                'turns' => $turns,
                'usage' => [
                    'tokens_in' => $tokensIn ?: $this->tokensIn,
                    'tokens_out' => $tokensOut ?: $this->tokensOut,
                ],
                'errors' => array_values(array_filter($this->events, fn ($e) => ($e['type'] ?? '') === 'error')),
                'tool_calls' => $this->toolCalls,
            ], JSON_PRETTY_PRINT)),
            OutputFormat::StreamJson => $this->emitEvent('result', [
                'text' => $text,
                'duration_ms' => $duration,
                'turns' => $turns,
                'usage' => [
                    'tokens_in' => $tokensIn ?: $this->tokensIn,
                    'tokens_out' => $tokensOut ?: $this->tokensOut,
                ],
            ]),
        };
    }

    /**
     * Emit an error result to stdout.
     */
    public function emitError(string $message, int $exitCode = 1): void
    {
        if ($this->format === OutputFormat::Text) {
            $this->writeStderr("Error: {$message}");
        } elseif ($this->format === OutputFormat::StreamJson) {
            $this->emitEvent('error', ['message' => $message, 'code' => $exitCode]);
        } elseif ($this->format === OutputFormat::Json) {
            // JSON mode: errors go to stderr. The result blob (if any) goes to stdout.
            $this->writeStderr("Error: {$message}");
        }
    }

    // ─── Private helpers ─────────────────────────────────────────────────

    private function writeStdout(string $text): void
    {
        fwrite(STDOUT, $text."\n");
    }

    private function writeStderr(string $text): void
    {
        fwrite(STDERR, $text."\n");
    }

    private function dim(string $text): string
    {
        return $this->useColor ? "\033[2m{$text}\033[0m" : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emitEvent(string $type, array $data): void
    {
        $event = array_merge(['type' => $type, 'timestamp' => (int) (microtime(true) * 1000)], $data);
        fwrite(STDOUT, $this->jsonEncode($event)."\n");
    }

    /**
     * Encode data as JSON, handling invalid UTF-8 in tool output gracefully.
     */
    private function jsonEncode(mixed $data, int $flags = 0): string
    {
        $result = json_encode($data, $flags | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $result !== false ? $result : '{"error":"json_encode failed"}';
    }

    private function formatToolCallPreview(string $name, array $args): string
    {
        $preview = $name;
        $firstKey = array_key_first($args);
        if ($firstKey !== null) {
            $val = $args[$firstKey];
            $valStr = is_string($val) ? mb_substr($val, 0, 80) : json_encode($val);
            $preview .= "({$firstKey}: {$valStr})";
        }

        return $preview;
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Anti-corruption layer for Prism PHP tool call/result value objects.
 *
 * Centralizes all construction and field extraction for ToolCall, ToolResult,
 * ToolOutput, and ToolError so the rest of the codebase is insulated from
 * Prism SDK signature changes.
 */
final class ToolCallMapper
{
    /**
     * Create a ToolResult representing a successful tool execution.
     *
     * @param  string  $toolCallId  The tool call ID from the LLM response
     * @param  string  $toolName  Name of the tool that was executed
     * @param  array<string, mixed>  $args  Arguments the tool was called with
     * @param  string  $output  The tool's output text
     */
    public static function toToolResult(string $toolCallId, string $toolName, array $args, string $output): ToolResult
    {
        return new ToolResult(
            toolCallId: $toolCallId,
            toolName: $toolName,
            args: $args,
            result: $output,
        );
    }

    /**
     * Create a ToolResult representing an error from tool execution.
     *
     * @param  string  $toolCallId  The tool call ID from the LLM response
     * @param  string  $toolName  Name of the tool that failed
     * @param  array<string, mixed>  $args  Arguments the tool was called with
     * @param  string  $error  The error message
     */
    public static function toErrorResult(string $toolCallId, string $toolName, array $args, string $error): ToolResult
    {
        return new ToolResult(
            toolCallId: $toolCallId,
            toolName: $toolName,
            args: $args,
            result: $error,
        );
    }

    /**
     * Create a ToolResult with replaced content (for pruning/deduplication).
     *
     * Preserves all metadata from the original result, substituting only the content.
     *
     * @param  ToolResult  $original  The result to copy metadata from
     * @param  string  $replacementContent  New content to substitute
     */
    public static function withReplacedContent(ToolResult $original, string $replacementContent): ToolResult
    {
        return new ToolResult(
            toolCallId: $original->toolCallId,
            toolName: $original->toolName,
            args: $original->args,
            result: $replacementContent,
        );
    }

    /**
     * Extract the tool name and decoded arguments from a Prism ToolCall.
     *
     * @return array{name: string, args: array<string, mixed>, id: string}
     */
    public static function extractCall(ToolCall $call): array
    {
        return [
            'name' => $call->name,
            'args' => $call->arguments(),
            'id' => $call->id,
        ];
    }

    /**
     * Normalize raw tool handler output into a string.
     *
     * Handles the various return types a Prism tool handler may produce:
     * string, ToolOutput, ToolError, or other scalars.
     *
     * @param  mixed  $output  Raw return value from Tool::handle()
     */
    public static function normalizeToolOutput(mixed $output): string
    {
        return match (true) {
            is_string($output) => $output,
            $output instanceof ToolOutput => $output->result,
            $output instanceof ToolError => "Error: {$output->message}",
            default => (string) $output,
        };
    }
}

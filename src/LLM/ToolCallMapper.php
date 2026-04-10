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
     * Unique prefix used to mark error results inside Prism's ToolResult.
     * The \x01 byte cannot appear in normal tool output, preventing false positives.
     */
    public const ERROR_PREFIX = "\x01ERROR:";

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
     * @param  string  $error  The error message (will be prefixed with ERROR_PREFIX)
     */
    public static function toErrorResult(string $toolCallId, string $toolName, array $args, string $error): ToolResult
    {
        return new ToolResult(
            toolCallId: $toolCallId,
            toolName: $toolName,
            args: $args,
            result: self::ERROR_PREFIX.$error,
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
     * Create a ToolResult with replaced content and nulled-out args (for pruning).
     *
     * Frees memory retained in large tool call arguments after the result has been
     * superseded by a placeholder summary.
     */
    public static function withPrunedContent(ToolResult $original, string $replacementContent): ToolResult
    {
        return new ToolResult(
            toolCallId: $original->toolCallId,
            toolName: $original->toolName,
            args: $original->args,
            result: $replacementContent,
        );
    }

    /**
     * Decode tool call arguments, tolerating malformed JSON payloads from providers.
     *
     * @return array<string, mixed>
     */
    public static function safeArguments(ToolCall $call): array
    {
        return self::tryExtractCall($call)['args'];
    }

    /**
     * Extract a ToolCall while preserving decode errors for callers that want to report them.
     *
     * @return array{name: string, args: array<string, mixed>, id: string, argumentsError: ?string}
     */
    public static function tryExtractCall(ToolCall $call): array
    {
        try {
            $args = $call->arguments();
            $argumentsError = null;
        } catch (\JsonException $e) {
            $args = [];
            $argumentsError = $e->getMessage();
        }

        return [
            'name' => $call->name,
            'args' => $args,
            'id' => $call->id,
            'argumentsError' => $argumentsError,
        ];
    }

    /**
     * Extract the tool name and decoded arguments from a Prism ToolCall.
     *
     * @return array{name: string, args: array<string, mixed>, id: string}
     */
    public static function extractCall(ToolCall $call): array
    {
        $decoded = self::tryExtractCall($call);

        return [
            'name' => $decoded['name'],
            'args' => $decoded['args'],
            'id' => $decoded['id'],
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
            $output instanceof ToolError => self::ERROR_PREFIX.$output->message,
            default => (string) $output,
        };
    }

    /**
     * Check whether a ToolResult was created by toErrorResult() or carries an error marker.
     */
    public static function isErrorResult(ToolResult $result): bool
    {
        return is_string($result->result) && str_starts_with($result->result, self::ERROR_PREFIX);
    }

    /**
     * Strip the error prefix from a ToolResult, returning the human-readable message.
     *
     * Returns the raw string unchanged if it doesn't carry the error prefix.
     */
    public static function cleanErrorResult(ToolResult $result): string
    {
        $str = (string) $result->result;
        if (str_starts_with($str, self::ERROR_PREFIX)) {
            return substr($str, strlen(self::ERROR_PREFIX));
        }

        return $str;
    }
}

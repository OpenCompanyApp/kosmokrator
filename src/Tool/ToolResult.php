<?php

declare(strict_types=1);

namespace Kosmokrator\Tool;

/**
 * Immutable value object returned by every tool execution.
 *
 * Carries a string output and a success flag so callers can distinguish
 * normal results from tool-side errors without throwing exceptions.
 */
class ToolResult
{
    public function __construct(
        public readonly string $output,
        public readonly bool $success = true,
        public readonly ?array $metadata = null,
    ) {}

    /** Create a successful result with the given output text. */
    public static function success(string $output): self
    {
        return new self($output, true);
    }

    /** Create a successful result with structured metadata for programmatic consumers (Lua, etc.). */
    public static function successWithMetadata(string $output, array $metadata): self
    {
        return new self($output, true, $metadata);
    }

    /** Create an error result with the given message. */
    public static function error(string $message): self
    {
        return new self($message, false);
    }
}

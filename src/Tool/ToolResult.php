<?php

namespace Kosmokrator\Tool;

class ToolResult
{
    public function __construct(
        public readonly string $output,
        public readonly bool $success = true,
    ) {}

    public static function success(string $output): self
    {
        return new self($output, true);
    }

    public static function error(string $message): self
    {
        return new self($message, false);
    }
}

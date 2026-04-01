<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

/**
 * Tool execution display and permission prompts.
 */
interface ToolRendererInterface
{
    public function showToolCall(string $name, array $args): void;

    public function showToolResult(string $name, string $output, bool $success): void;

    /**
     * Ask the user for permission to execute a tool.
     *
     * @return string 'allow', 'deny', 'always', 'guardian', or 'prometheus'
     */
    public function askToolPermission(string $toolName, array $args): string;

    /**
     * Show a dimmed auto-approve indicator after a tool call line.
     */
    public function showAutoApproveIndicator(string $toolName): void;
}

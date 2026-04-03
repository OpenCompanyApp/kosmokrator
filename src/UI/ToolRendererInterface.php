<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

/**
 * Tool execution display and permission prompts.
 *
 * Handles rendering of tool invocations, results, and the interactive
 * permission flow. Extended by RendererInterface.
 */
interface ToolRendererInterface
{
    /**
     * Display a tool invocation header.
     *
     * @param string               $name Tool identifier
     * @param array<string, mixed> $args Tool call arguments
     */
    public function showToolCall(string $name, array $args): void;

    /**
     * Display the result of a completed tool call.
     *
     * @param string $name    Tool identifier
     * @param string $output  Raw tool output or error text
     * @param bool   $success Whether the tool call succeeded
     */
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

    /**
     * Show an executing spinner below the tool call header.
     */
    public function showToolExecuting(string $name): void;

    /**
     * Update the executing spinner with streaming output (e.g., bash chunks).
     */
    public function updateToolExecuting(string $output): void;

    /**
     * Remove the executing spinner.
     */
    public function clearToolExecuting(): void;
}

<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

/**
 * Interactive dialog and settings display methods.
 */
interface DialogRendererInterface
{
    /**
     * Show the settings panel and block until the user closes it.
     *
     * @param  array<string, mixed>  $currentSettings
     * @return array<string, string> Changed settings (id => new value)
     */
    public function showSettings(array $currentSettings): array;

    /**
     * Show an interactive session picker. Returns selected session ID or null.
     *
     * @param  array<array{value: string, label: string, description?: string}>  $items
     */
    public function pickSession(array $items): ?string;

    /**
     * Show the plan approval dialog after a plan-mode run completes.
     *
     * @return array{permission: string, context: string}|null Settings on accept, null on dismiss
     */
    public function approvePlan(string $currentPermissionMode): ?array;

    /**
     * Ask the user a free-text question mid-run. Blocks until they respond.
     */
    public function askUser(string $question): string;

    /**
     * Present multiple-choice options to the user. Each choice can have a detail
     * block (ASCII art / mockup) shown when that option is highlighted.
     * A "Dismiss" option is always appended. Returns selected label or 'dismissed'.
     *
     * @param  array<array{label: string, detail: string|null, recommended?: bool}>  $choices
     */
    public function askChoice(string $question, array $choices): string;
}

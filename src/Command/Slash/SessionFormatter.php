<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

/**
 * Shared formatting utilities for session display in slash commands.
 */
final class SessionFormatter
{
    /**
     * Format a timestamp as a human-readable relative age.
     *
     * @return string E.g. "just now", "5m ago", "2h ago", "3d ago"
     */
    public static function formatAge(string $timestamp): string
    {
        if ($timestamp === '') {
            return '?';
        }

        $seconds = time() - (int) ((float) $timestamp);

        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return (int) ($seconds / 60).'m ago';
        }
        if ($seconds < 86400) {
            return (int) ($seconds / 3600).'h ago';
        }

        return (int) ($seconds / 86400).'d ago';
    }
}

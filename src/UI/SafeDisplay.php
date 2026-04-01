<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Psr\Log\LoggerInterface;

/**
 * Fire-and-forget wrapper for display-only UI calls.
 *
 * Prevents cascading failures where a UI rendering error (widget construction,
 * container layout, ANSI output) crashes the agent execution pipeline.
 * Never use for interactive/blocking calls (askToolPermission, prompt, etc.).
 */
final class SafeDisplay
{
    /**
     * Execute a display-only UI call, logging and swallowing any exception.
     *
     * @param  callable(): void  $fn  The display call to execute
     * @param  ?LoggerInterface  $log  Logger for recording failures
     */
    public static function call(callable $fn, ?LoggerInterface $log = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $log?->warning('Display call failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}

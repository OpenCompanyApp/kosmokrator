<?php

declare(strict_types=1);

namespace Kosmokrator\Logging;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creates named sub-loggers that inherit the main Monolog handler stack
 * but carry a distinct channel name for easy filtering.
 *
 * Usage: Log::channel('llm')->warning('Rate limited', [...])
 * Produces: [timestamp] kosmokrator.llm.WARNING: Rate limited {...}
 */
final class Log
{
    private static ?Logger $root = null;

    /** Set the root logger during boot. Called by LoggingServiceProvider. */
    public static function setRoot(Logger $logger): void
    {
        self::$root = $logger;
    }

    /**
     * Create a named sub-logger (e.g. 'llm', 'subagent', 'tool').
     *
     * Uses Monolog's withName() which returns a new Logger instance
     * sharing the same handlers and processors.
     */
    public static function channel(string $name): LoggerInterface
    {
        if (self::$root === null) {
            return new NullLogger;
        }

        return self::$root->withName(self::$root->getName().'.'.$name);
    }

    /** Get the root logger. */
    public static function root(): LoggerInterface
    {
        return self::$root ?? new NullLogger;
    }
}

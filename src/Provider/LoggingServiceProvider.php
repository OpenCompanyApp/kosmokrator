<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Kosmokrator\Logging\CorrelationIdProcessor;
use Kosmokrator\Logging\Log;
use Monolog\Handler\DeduplicationHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Psr\Log\LoggerInterface;

/**
 * Creates a rotating file logger under ~/.kosmokrator/logs with:
 *  - Correlation ID for session-level grouping
 *  - Introspection (file:line) on WARNING+
 *  - Deduplication to suppress repeated messages within 60s
 *
 * Bound as 'log', LoggerInterface, and Logger.
 */
class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
        $logDir = $home.'/.kosmokrator/logs';

        if (! is_dir($logDir) && ! @mkdir($logDir, 0700, true) && ! is_dir($logDir)) {
            throw new \RuntimeException("Unable to create log directory: {$logDir}");
        }

        $logger = new Logger('kosmokrator');

        // Core rotating file handler — 7 days retention, DEBUG level
        $rotating = new RotatingFileHandler($logDir.'/kosmokrator.log', 7, Logger::DEBUG);

        // Deduplication wrapper: suppresses identical messages within 60s window.
        // This prevents floods like 546 identical "Display call failed" lines.
        $dedup = new DeduplicationHandler($rotating, null, Logger::DEBUG, 60, true);
        $logger->pushHandler($dedup);

        // Add correlation ID to every record
        $logger->pushProcessor(new CorrelationIdProcessor);

        // Add file:line info to WARNING and above — helps debug issues without
        // needing to reproduce them. Skip internal Monolog/Logger frames.
        $logger->pushProcessor(new IntrospectionProcessor(Logger::WARNING, [
            'Monolog\\',
            'Psr\\Log\\',
            'Kosmokrator\\Logging\\',
            'Kosmokrator\\UI\\SafeDisplay',
        ]));

        $this->container->instance('log', $logger);
        $this->container->alias('log', LoggerInterface::class);
        $this->container->alias('log', Logger::class);

        // Wire the static Log facade
        Log::setRoot($logger);
    }
}

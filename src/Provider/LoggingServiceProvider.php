<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Creates a rotating file logger under ~/.kosmokrator/logs and binds it
 * as 'log', LoggerInterface, and Logger.
 */
class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
        $logDir = $home.'/.kosmokrator/logs';

        if (! is_dir($logDir)) {
            mkdir($logDir, 0700, true);
        }

        $logger = new Logger('kosmokrator');
        $logger->pushHandler(new RotatingFileHandler($logDir.'/kosmokrator.log', 7, Logger::DEBUG));

        $this->container->instance('log', $logger);
        $this->container->alias('log', LoggerInterface::class);
        $this->container->alias('log', Logger::class);
    }
}

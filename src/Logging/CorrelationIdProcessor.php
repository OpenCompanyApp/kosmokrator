<?php

declare(strict_types=1);

namespace Kosmokrator\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds a correlation ID to every log record so all lines from one
 * agent session can be grep'd together.
 *
 * The ID is a short 8-char hex string generated once per process.
 */
final class CorrelationIdProcessor implements ProcessorInterface
{
    private readonly string $correlationId;

    public function __construct()
    {
        $this->correlationId = bin2hex(random_bytes(4));
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['correlation_id'] = $this->correlationId;

        return $record;
    }
}

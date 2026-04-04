<?php

declare(strict_types=1);

namespace Kosmokrator\Exception;

/**
 * Base exception for all KosmoKrator-specific errors.
 */
class KosmokratorException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

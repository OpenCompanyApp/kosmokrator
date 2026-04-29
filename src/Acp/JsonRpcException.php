<?php

declare(strict_types=1);

namespace Kosmokrator\Acp;

final class JsonRpcException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $jsonRpcCode = -32000,
        public readonly mixed $data = null,
    ) {
        parent::__construct($message);
    }

    public static function parseError(string $message): self
    {
        return new self($message, -32700);
    }

    public static function invalidRequest(string $message): self
    {
        return new self($message, -32600);
    }

    public static function methodNotFound(string $method): self
    {
        return new self("Method not found: {$method}", -32601);
    }

    public static function invalidParams(string $message): self
    {
        return new self($message, -32602);
    }

    public static function internalError(string $message): self
    {
        return new self($message, -32603);
    }

    public static function authRequired(string $message = 'Authentication required'): self
    {
        return new self($message, -32001, ['code' => 'auth_required']);
    }
}

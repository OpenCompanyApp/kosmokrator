<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

final readonly class GatewayApproval
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public int $id,
        public string $platform,
        public string $routeKey,
        public string $sessionId,
        public string $toolName,
        public array $arguments,
        public string $status,
        public string $chatId,
        public ?string $threadId,
        public ?int $requestMessageId = null,
    ) {}
}

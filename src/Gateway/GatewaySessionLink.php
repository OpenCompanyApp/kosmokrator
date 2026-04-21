<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

final readonly class GatewaySessionLink
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $platform,
        public string $routeKey,
        public string $sessionId,
        public string $chatId,
        public ?string $threadId,
        public ?string $userId,
        public array $metadata = [],
    ) {}
}

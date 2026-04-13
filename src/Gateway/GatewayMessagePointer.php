<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

final readonly class GatewayMessagePointer
{
    public function __construct(
        public string $platform,
        public string $routeKey,
        public string $messageKind,
        public string $chatId,
        public int $messageId,
        public ?string $threadId = null,
    ) {}
}

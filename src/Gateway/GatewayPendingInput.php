<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

final readonly class GatewayPendingInput
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $id,
        public string $platform,
        public string $routeKey,
        public array $payload,
        public string $createdAt,
    ) {}
}

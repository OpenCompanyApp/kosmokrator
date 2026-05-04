<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp\Server;

final readonly class McpGatewayProfile
{
    /**
     * @param  list<string>  $integrations
     * @param  list<string>  $upstreams
     */
    public function __construct(
        public array $integrations = [],
        public array $upstreams = [],
        public string $writePolicy = 'deny',
        public bool $exposeResources = true,
        public bool $exposePrompts = true,
        public int $maxResultChars = 50000,
        public bool $force = false,
    ) {}

    public function allowsWrites(): bool
    {
        return $this->writePolicy === 'allow';
    }
}

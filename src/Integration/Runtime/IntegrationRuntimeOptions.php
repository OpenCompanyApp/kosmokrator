<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

final readonly class IntegrationRuntimeOptions
{
    public function __construct(
        public ?string $account = null,
        public bool $force = false,
        public bool $dryRun = false,
    ) {}
}

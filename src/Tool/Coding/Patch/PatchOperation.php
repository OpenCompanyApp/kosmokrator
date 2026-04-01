<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding\Patch;

final class PatchOperation
{
    /**
     * @param  string[]  $bodyLines
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly array $bodyLines = [],
        public readonly ?string $moveTo = null,
    ) {}
}

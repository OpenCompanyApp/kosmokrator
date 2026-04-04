<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding\Patch;

/**
 * Immutable DTO representing a single patch file operation (add, update, or delete).
 *
 * Created by PatchParser, consumed by PatchApplier.
 */
final class PatchOperation
{
    /**
     * @param  string  $kind  One of 'add', 'update', 'delete'
     * @param  string  $path  Target file path
     * @param  string[]  $bodyLines  Lines of content (add) or hunk lines with prefixes (update)
     * @param  string|null  $moveTo  Destination path for a move-rename during update, or null
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly array $bodyLines = [],
        public readonly ?string $moveTo = null,
    ) {}
}

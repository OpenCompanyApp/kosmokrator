<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Value;

final readonly class ExtractedPage
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array{id: string, title: string, level: int}>  $outline
     * @param  array<string, string>  $sections
     */
    public function __construct(
        public ?string $title,
        public array $metadata,
        public array $outline,
        public string $fullContent,
        public array $sections,
    ) {}
}

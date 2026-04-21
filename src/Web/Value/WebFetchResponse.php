<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Value;

final readonly class WebFetchResponse
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array{id: string, title: string, level: int}>  $outline
     * @param  array<string, string>  $sections
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $provider,
        public string $url,
        public ?string $finalUrl,
        public int $statusCode,
        public string $contentType,
        public string $format,
        public ?string $title,
        public array $metadata,
        public array $outline,
        public array $sections,
        public string $content,
        public ?string $rawHtml = null,
        public bool $truncated = false,
        public ?string $nextChunkToken = null,
        public string $extractionMethod = 'direct',
        public array $meta = [],
    ) {}
}

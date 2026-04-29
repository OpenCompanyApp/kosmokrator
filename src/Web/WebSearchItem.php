<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

/**
 * @param  array<string, mixed>  $metadata
 */
final readonly class WebSearchItem
{
    public function __construct(
        public string $title,
        public string $url,
        public string $snippet = '',
        public ?string $content = null,
        public ?float $score = null,
        public ?string $publishedAt = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'snippet' => $this->snippet,
            'content' => $this->content,
            'score' => $this->score,
            'published_at' => $this->publishedAt,
            'metadata' => $this->metadata,
        ];
    }
}

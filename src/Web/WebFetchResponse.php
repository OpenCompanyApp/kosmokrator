<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

/**
 * @param  array<string, mixed>  $metadata
 */
final readonly class WebFetchResponse
{
    public function __construct(
        public string $provider,
        public string $url,
        public string $content,
        public string $format = 'markdown',
        public ?string $title = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'url' => $this->url,
            'title' => $this->title,
            'format' => $this->format,
            'content' => $this->content,
            'metadata' => $this->metadata,
        ];
    }
}

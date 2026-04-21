<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Value;

final readonly class WebFetchRequest
{
    public function __construct(
        public string $url,
        public ?string $provider = null,
        public string $mode = 'main',
        public string $format = 'markdown',
        public int $maxChars = 12000,
        public bool $summarize = false,
        public ?string $prompt = null,
        public ?string $heading = null,
        public ?string $sectionId = null,
        public ?string $match = null,
        public ?string $startAfter = null,
        public ?string $endBefore = null,
        public ?string $chunkToken = null,
        public ?int $timeout = null,
        public string $strategy = 'auto',
        public bool $includeMetadata = true,
        public bool $includeOutline = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toCachePayload(): array
    {
        return [
            'url' => $this->url,
            'provider' => $this->provider,
            'mode' => $this->mode,
            'format' => $this->format,
            'max_chars' => $this->maxChars,
            'summarize' => $this->summarize,
            'prompt' => $this->prompt,
            'heading' => $this->heading,
            'section_id' => $this->sectionId,
            'match' => $this->match,
            'start_after' => $this->startAfter,
            'end_before' => $this->endBefore,
            'chunk_token' => $this->chunkToken,
            'strategy' => $this->strategy,
            'include_metadata' => $this->includeMetadata,
            'include_outline' => $this->includeOutline,
        ];
    }
}

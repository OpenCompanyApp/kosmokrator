<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects\Messages;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\ValueObjects\Concerns\HasProviderOptions;

final class UserMessage implements Message
{
    use HasProviderOptions;

    /**
     * @param  list<mixed>  $additionalContent
     * @param  array<string, mixed>  $additionalAttributes
     */
    public function __construct(
        public string $content,
        public array $additionalContent = [],
        public array $additionalAttributes = [],
    ) {}

    public function text(): string
    {
        return $this->content;
    }

    /**
     * @return list<mixed>
     */
    public function images(): array
    {
        return array_values(array_filter(
            $this->additionalContent,
            static fn (mixed $item): bool => is_object($item) && str_contains($item::class, 'Image'),
        ));
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects\Messages;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\ValueObjects\Concerns\HasProviderOptions;
use Kosmokrator\LLM\ValueObjects\ToolCall;

final class AssistantMessage implements Message
{
    use HasProviderOptions;

    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array<string, mixed>  $additionalContent
     */
    public function __construct(
        public string $content,
        public array $toolCalls = [],
        public array $additionalContent = [],
    ) {}
}

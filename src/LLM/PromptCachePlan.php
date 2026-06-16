<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\ValueObjects\Messages\SystemMessage;

final readonly class PromptCachePlan
{
    /**
     * @param  list<SystemMessage>  $systemPrompts
     * @param  list<Message>  $messages
     * @param  array<string, mixed>  $providerOptions
     * @param  list<mixed>  $tools
     */
    public function __construct(
        public array $systemPrompts,
        public array $messages,
        public array $providerOptions = [],
        public array $tools = [],
    ) {}
}

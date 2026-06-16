<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects\Messages;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\ValueObjects\Concerns\HasProviderOptions;
use Kosmokrator\LLM\ValueObjects\ToolResult;

final class ToolResultMessage implements Message
{
    use HasProviderOptions;

    /** @param list<ToolResult> $toolResults */
    public function __construct(
        public array $toolResults,
    ) {}
}

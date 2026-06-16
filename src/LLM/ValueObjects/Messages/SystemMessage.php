<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ValueObjects\Messages;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\ValueObjects\Concerns\HasProviderOptions;

final class SystemMessage implements Message
{
    use HasProviderOptions;

    public function __construct(
        public string $content,
    ) {}
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\Tool;

interface TokenCounterInterface
{
    public function countText(string $text, string $bucket = 'text'): int;

    public function countMessage(Message $message): int;

    /**
     * @param  Message[]  $messages
     */
    public function countMessages(array $messages): int;

    /**
     * @param  Tool[]  $tools
     */
    public function countTools(array $tools): int;
}

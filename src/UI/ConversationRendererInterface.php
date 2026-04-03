<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Prism\Prism\Contracts\Message;

/**
 * Conversation history display and management.
 *
 * Handles clearing and replaying conversation context. Extended by RendererInterface.
 */
interface ConversationRendererInterface
{
    /** Clear the current conversation display. */
    public function clearConversation(): void;

    /**
     * Replay resumed conversation history as a condensed visual summary.
     *
     * @param  array<int, Message>  $messages
     */
    public function replayHistory(array $messages): void;
}

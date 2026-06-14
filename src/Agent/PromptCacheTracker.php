<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\PromptFrameBuilder;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

final class PromptCacheTracker
{
    private ?PromptCacheObservation $last = null;

    public function observe(
        int $round,
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        array $tools,
        int $promptTokens,
        int $cacheReadTokens,
        int $cacheWriteTokens,
        bool $historyRewritten = false,
    ): PromptCacheObservation {
        $frames = PromptFrameBuilder::splitSystemPrompt($systemPrompt);
        $stable = $frames[0] ?? new SystemMessage('');
        $volatile = array_slice($frames, 1);

        $stableHash = $this->hash($stable->content);
        $volatileHash = $this->hash(implode("\n", array_map(static fn (SystemMessage $m): string => $m->content, $volatile)));
        $toolsHash = $this->hash($this->serializeTools($tools));
        $messagesHash = $this->hash($this->serializeMessagePrefix($messages));

        $observation = new PromptCacheObservation(
            round: $round,
            provider: $provider,
            model: $model,
            stableHash: $stableHash,
            volatileHash: $volatileHash,
            toolsHash: $toolsHash,
            messagesHash: $messagesHash,
            promptTokens: $promptTokens,
            cacheReadTokens: $cacheReadTokens,
            cacheWriteTokens: $cacheWriteTokens,
            dropCause: $this->dropCause($provider, $model, $stableHash, $volatileHash, $toolsHash, $messagesHash, $cacheReadTokens, $promptTokens, $historyRewritten),
        );

        $this->last = $observation;

        return $observation;
    }

    public function last(): ?PromptCacheObservation
    {
        return $this->last;
    }

    private function dropCause(
        string $provider,
        string $model,
        string $stableHash,
        string $volatileHash,
        string $toolsHash,
        string $messagesHash,
        int $cacheReadTokens,
        int $promptTokens,
        bool $historyRewritten,
    ): ?string {
        $previous = $this->last;
        if ($previous === null || $previous->cacheReadTokens < 1000) {
            return null;
        }

        $promptDelta = abs($promptTokens - $previous->promptTokens);
        $promptStable = $promptDelta <= max(1000, (int) round($previous->promptTokens * 0.15));
        $cacheDropped = $cacheReadTokens < (int) floor($previous->cacheReadTokens * 0.25);
        if (! $promptStable || ! $cacheDropped) {
            return null;
        }

        if ($provider !== $previous->provider || $model !== $previous->model) {
            return 'model or provider changed';
        }
        if ($stableHash !== $previous->stableHash) {
            return 'stable system prompt changed';
        }
        if ($toolsHash !== $previous->toolsHash) {
            return 'tool schema changed';
        }
        if ($volatileHash !== $previous->volatileHash) {
            return 'volatile prompt context changed';
        }
        if ($messagesHash !== $previous->messagesHash) {
            return 'conversation message prefix changed';
        }
        if ($historyRewritten) {
            return 'conversation history was compacted, pruned, or deduplicated';
        }

        return 'provider cache TTL expired or provider evicted the entry';
    }

    private function hash(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }

    /**
     * @param  Tool[]  $tools
     */
    private function serializeTools(array $tools): string
    {
        $payload = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof Tool) {
                continue;
            }
            $payload[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parametersAsArray(),
                'required' => $tool->requiredParameters(),
            ];
        }

        return json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }

    /**
     * @param  Message[]  $messages
     */
    private function serializeMessagePrefix(array $messages): string
    {
        if (count($messages) > 1) {
            $messages = array_slice($messages, 0, -1);
        }

        return json_encode(array_map(static fn (Message $message): string => get_debug_type($message).':'.serialize($message), $messages), JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Kosmokrator\LLM\Contracts\Message;
use Kosmokrator\LLM\ValueObjects\Messages\AssistantMessage;
use Kosmokrator\LLM\ValueObjects\Messages\SystemMessage;
use Kosmokrator\LLM\ValueObjects\Messages\ToolResultMessage;
use Kosmokrator\LLM\ValueObjects\Messages\UserMessage;

final class PromptCachePlanner
{
    /**
     * @param  list<SystemMessage>  $systemPrompts
     * @param  list<Message>  $messages
     * @param  list<mixed>  $tools
     */
    public static function plan(
        string $provider,
        array $systemPrompts,
        array $messages,
        ?string $cachedContentName = null,
        int $recentMessagesToCache = 1,
        array $tools = [],
    ): PromptCachePlan {
        $systemPrompts = array_map(self::cloneSystemMessage(...), $systemPrompts);
        $messages = array_map(self::cloneMessage(...), $messages);
        $tools = array_map(static fn (mixed $tool): mixed => is_object($tool) ? clone $tool : $tool, $tools);

        $ephemeralOptions = self::ephemeralMessageOptions($provider);
        if ($ephemeralOptions === []) {
            return new PromptCachePlan($systemPrompts, $messages, self::providerOptions($provider, $cachedContentName), $tools);
        }

        if ($systemPrompts !== []) {
            $systemPrompts[0]->withProviderOptions($ephemeralOptions);
        }

        $cached = 0;
        for ($i = count($messages) - 1; $i >= 0 && $cached < $recentMessagesToCache; $i--) {
            if ($messages[$i] instanceof UserMessage
                || $messages[$i] instanceof AssistantMessage
                || $messages[$i] instanceof ToolResultMessage
                || $messages[$i] instanceof SystemMessage) {
                $messages[$i]->withProviderOptions($ephemeralOptions);
                $cached++;
            }
        }

        if ($tools !== []) {
            $last = count($tools) - 1;
            if (is_object($tools[$last]) && method_exists($tools[$last], 'withProviderOptions') && method_exists($tools[$last], 'providerOptions')) {
                $tools[$last]->withProviderOptions(array_merge($tools[$last]->providerOptions(), self::toolOptions($provider)));
            } elseif (is_array($tools[$last])) {
                $tools[$last] = array_merge($tools[$last], self::toolSchemaOptions($provider));
            }
        }

        return new PromptCachePlan($systemPrompts, $messages, self::providerOptions($provider, $cachedContentName), $tools);
    }

    private static function cloneMessage(Message $message): Message
    {
        return match (true) {
            $message instanceof UserMessage => (new UserMessage($message->content, $message->additionalContent, $message->additionalAttributes))->withProviderOptions($message->providerOptions()),
            $message instanceof AssistantMessage => (new AssistantMessage($message->content, $message->toolCalls, $message->additionalContent))->withProviderOptions($message->providerOptions()),
            $message instanceof ToolResultMessage => (new ToolResultMessage($message->toolResults))->withProviderOptions($message->providerOptions()),
            $message instanceof SystemMessage => self::cloneSystemMessage($message),
            default => $message,
        };
    }

    private static function cloneSystemMessage(SystemMessage $message): SystemMessage
    {
        return (new SystemMessage($message->content))->withProviderOptions($message->providerOptions());
    }

    /** @return array<string, mixed> */
    private static function providerOptions(string $provider, ?string $cachedContentName): array
    {
        return match ($provider) {
            'gemini' => $cachedContentName !== null ? ['cachedContent' => $cachedContentName] : [],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function ephemeralMessageOptions(string $provider): array
    {
        return match ($provider) {
            'anthropic', 'openrouter' => ['cacheType' => 'ephemeral'],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function toolOptions(string $provider): array
    {
        return self::ephemeralMessageOptions($provider);
    }

    /** @return array<string, mixed> */
    private static function toolSchemaOptions(string $provider): array
    {
        return match ($provider) {
            'anthropic', 'openrouter' => ['cache_control' => ['type' => 'ephemeral']],
            default => [],
        };
    }
}

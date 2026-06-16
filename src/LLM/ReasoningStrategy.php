<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final class ReasoningStrategy
{
    /** @return array<string, mixed> */
    public static function requestParams(string $provider, string $effort): array
    {
        return match ($provider) {
            'openai', 'xai' => $effort === 'off' ? [] : ['reasoning_effort' => self::openAiCompatibleEffort($effort)],
            'z', 'z-api' => self::glmRequestParams($effort),
            default => [],
        };
    }

    /** @param array<string, mixed> $message */
    public static function extractReasoning(string $provider, array $message): string
    {
        return match ($provider) {
            'stepfun', 'stepfun-plan' => (string) ($message['reasoning'] ?? $message['reasoning_content'] ?? ''),
            default => (string) ($message['reasoning_content'] ?? $message['reasoning'] ?? ''),
        };
    }

    /** @return array<string, mixed> */
    private static function glmRequestParams(string $effort): array
    {
        if ($effort === 'off') {
            return ['thinking' => ['type' => 'disabled']];
        }

        return [
            'thinking' => [
                'type' => 'enabled',
                'reasoning_effort' => $effort === 'max' ? 'max' : 'high',
            ],
        ];
    }

    private static function openAiCompatibleEffort(string $effort): string
    {
        return in_array($effort, ['low', 'medium', 'high'], true) ? $effort : 'high';
    }
}

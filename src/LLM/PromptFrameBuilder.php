<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Prism\Prism\ValueObjects\Messages\SystemMessage;

/**
 * Splits a monolithic system prompt into stable and volatile sections for prompt caching.
 *
 * Providers like Anthropic and OpenAI offer discounted pricing for cached prompt prefixes.
 * By separating the static system instructions from the per-turn task list, the stable
 * prefix can be reused across requests. Used by PrismService and AsyncLlmClient via Relay.
 */
final class PromptFrameBuilder
{
    private const CACHE_LIMIT = 128;

    /** Section headers that mark the start of per-turn (volatile) content. */
    private const VOLATILE_SECTION_MARKERS = [
        "\n\n## Gateway Session Context\n",
        "\n\n## Parent Brief\n",
        "\n\n## Protected Context\n",
        "\n\n## Current Tasks\n",
    ];

    /** @var array<string, SystemMessage[]> */
    private static array $cache = [];

    /** @var list<string> */
    private static array $cacheOrder = [];

    /**
     * Split a system prompt string into one or two SystemMessage objects.
     *
     * The portion before the first volatile marker becomes the cacheable prefix;
     * the portion after is treated as per-request content that changes between turns.
     *
     * @return SystemMessage[]
     */
    public static function splitSystemPrompt(string $systemPrompt): array
    {
        if ($systemPrompt === '') {
            return [];
        }

        $key = hash('sha256', $systemPrompt);
        if (isset(self::$cache[$key])) {
            self::touchCacheKey($key);

            return self::$cache[$key];
        }

        $splitOffset = self::findVolatileSectionOffset($systemPrompt);
        if ($splitOffset === null || $splitOffset <= 0) {
            return self::remember($key, [new SystemMessage($systemPrompt)]);
        }

        $stablePrefix = substr($systemPrompt, 0, $splitOffset);
        $volatileTail = substr($systemPrompt, $splitOffset + 2);

        $prompts = [];
        $prompts[] = new SystemMessage($stablePrefix);

        if ($volatileTail !== '') {
            $prompts[] = new SystemMessage($volatileTail);
        }

        return self::remember($key, $prompts);
    }

    /**
     * Find the earliest offset of any volatile section marker within the prompt string.
     */
    private static function findVolatileSectionOffset(string $systemPrompt): ?int
    {
        $offsets = [];

        foreach (self::VOLATILE_SECTION_MARKERS as $marker) {
            $offset = strpos($systemPrompt, $marker);
            if ($offset !== false) {
                $offsets[] = $offset;
            }
        }

        if ($offsets === []) {
            return null;
        }

        return min($offsets);
    }

    /**
     * @param  SystemMessage[]  $prompts
     * @return SystemMessage[]
     */
    private static function remember(string $key, array $prompts): array
    {
        self::$cache[$key] = $prompts;
        self::touchCacheKey($key);

        while (count(self::$cacheOrder) > self::CACHE_LIMIT) {
            $oldest = array_shift(self::$cacheOrder);
            if ($oldest !== null) {
                unset(self::$cache[$oldest]);
            }
        }

        return $prompts;
    }

    private static function touchCacheKey(string $key): void
    {
        $index = array_search($key, self::$cacheOrder, true);
        if ($index !== false) {
            array_splice(self::$cacheOrder, $index, 1);
        }

        self::$cacheOrder[] = $key;
    }

    public static function resetCache(): void
    {
        self::$cache = [];
        self::$cacheOrder = [];
    }
}

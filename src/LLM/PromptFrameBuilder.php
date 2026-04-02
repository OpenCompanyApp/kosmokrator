<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Prism\Prism\ValueObjects\Messages\SystemMessage;

final class PromptFrameBuilder
{
    private const VOLATILE_SECTION_MARKERS = [
        "\n\n## Current Tasks\n",
    ];

    /**
     * @return SystemMessage[]
     */
    public static function splitSystemPrompt(string $systemPrompt): array
    {
        if ($systemPrompt === '') {
            return [];
        }

        $splitOffset = self::findVolatileSectionOffset($systemPrompt);
        if ($splitOffset === null || $splitOffset <= 0) {
            return [new SystemMessage($systemPrompt)];
        }

        $stablePrefix = substr($systemPrompt, 0, $splitOffset);
        $volatileTail = substr($systemPrompt, $splitOffset + 2);

        $prompts = [];

        if ($stablePrefix !== false && $stablePrefix !== '') {
            $prompts[] = new SystemMessage($stablePrefix);
        }

        if ($volatileTail !== false && $volatileTail !== '') {
            $prompts[] = new SystemMessage($volatileTail);
        }

        return $prompts === [] ? [new SystemMessage($systemPrompt)] : $prompts;
    }

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
}

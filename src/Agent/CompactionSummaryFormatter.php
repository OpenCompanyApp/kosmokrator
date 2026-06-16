<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

final class CompactionSummaryFormatter
{
    public const START_MARKER = '## Compacted Conversation Summary';

    private const END_MARKER = '<!-- /kosmo:compacted-summary -->';

    public static function wrap(string $summary): string
    {
        $summary = trim(self::normalize($summary));
        if ($summary === '') {
            return '';
        }

        return self::START_MARKER."\n\n"
            ."This is historical reference only. The latest user message, current operational mode, current system prompt, and current tool results override this summary. Do not resume stale tasks unless the latest user asks for them.\n\n"
            .$summary."\n\n"
            .self::END_MARKER;
    }

    public static function normalize(string $summary): string
    {
        $summary = trim($summary);
        if ($summary === '') {
            return '';
        }

        if (str_starts_with($summary, self::START_MARKER)) {
            $summary = preg_replace('/^## Compacted Conversation Summary\s+This is historical reference only\..*?\n\n/s', '', $summary) ?? $summary;
            $summary = str_replace(self::END_MARKER, '', $summary);
        }

        return trim($summary);
    }

    public static function isWrapped(string $summary): bool
    {
        return str_contains($summary, self::START_MARKER);
    }
}

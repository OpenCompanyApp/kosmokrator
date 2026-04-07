<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Highlight\Lua\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * Matches Lua strings: double-quoted, single-quoted, and long strings [[...]].
 */
final readonly class LuaStringPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        // Double-quoted strings with escapes
        return '(?<match>"(?:[^"\\\\]|\\\\.)*"'
            // Single-quoted strings with escapes
            ."|'(?:[^'\\\\]|\\\\.)*'"
            // Long strings [[...]] (single level only)
            .'|\[\[.*?\]\])';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::VALUE;
    }
}

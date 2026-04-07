<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Highlight\Lua\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class LuaNumberPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        // Hex (0x...), decimals, and scientific notation
        return '(?<match>\b0[xX][0-9a-fA-F]+\b|\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b)';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::NUMBER;
    }
}

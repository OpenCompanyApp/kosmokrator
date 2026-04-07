<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Highlight\Lua\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class LuaCommentPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        // -- single-line comment (not inside a string)
        return '(?<!["\'])(?<match>--[^\n]*)';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::COMMENT;
    }
}

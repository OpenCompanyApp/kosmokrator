<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Highlight\Lua\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class LuaOperatorPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        // Return a full regex with / delimiter (IsPattern skips wrapping when pattern starts with /)
        return '/(?<match>\+=|-=|\*=|\/=|%=|\^=|\.\.=|\.\.|==|~=|<=|>=|[#<>+\-*\/%^=])/';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::OPERATOR;
    }
}

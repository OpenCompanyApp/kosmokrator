<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Highlight\Lua\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class LuaFunctionPattern implements Pattern
{
    use IsPattern;

    public function getPattern(): string
    {
        return '\bfunction\s+(?<match>\w+)\b';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::PROPERTY;
    }
}

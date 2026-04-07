<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Highlight\Lua\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class LuaKeywordPattern implements Pattern
{
    use IsPattern;

    public function __construct(
        private readonly array $keywords = [
            'and', 'break', 'do', 'else', 'elseif', 'end',
            'false', 'for', 'function', 'goto', 'if', 'in',
            'local', 'nil', 'not', 'or', 'repeat', 'return',
            'then', 'true', 'until', 'while',
        ],
    ) {}

    public function getPattern(): string
    {
        return '\b(?<match>('.implode('|', $this->keywords).'))\b';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::KEYWORD;
    }
}

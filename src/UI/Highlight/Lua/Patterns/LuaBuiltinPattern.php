<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Highlight\Lua\Patterns;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final readonly class LuaBuiltinPattern implements Pattern
{
    use IsPattern;

    public function __construct(
        private readonly array $builtins = [
            'print', 'pairs', 'ipairs', 'tostring', 'tonumber', 'type',
            'require', 'pcall', 'xpcall', 'error', 'assert',
            'select', 'unpack', 'rawget', 'rawset', 'setmetatable', 'getmetatable',
            'next', 'loadstring', 'load', 'dofile', 'loadfile',
            'string', 'table', 'math', 'io', 'os', 'coroutine', 'debug',
            'collectgarbage',
        ],
    ) {}

    public function getPattern(): string
    {
        return '\b(?<match>('.implode('|', $this->builtins).'))\b';
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::TYPE;
    }
}

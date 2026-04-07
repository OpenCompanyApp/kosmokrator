<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Highlight\Lua;

use Kosmokrator\UI\Highlight\Lua\Patterns\LuaBuiltinPattern;
use Kosmokrator\UI\Highlight\Lua\Patterns\LuaCommentPattern;
use Kosmokrator\UI\Highlight\Lua\Patterns\LuaFunctionPattern;
use Kosmokrator\UI\Highlight\Lua\Patterns\LuaKeywordPattern;
use Kosmokrator\UI\Highlight\Lua\Patterns\LuaNumberPattern;
use Kosmokrator\UI\Highlight\Lua\Patterns\LuaOperatorPattern;
use Kosmokrator\UI\Highlight\Lua\Patterns\LuaStringPattern;
use Override;
use Tempest\Highlight\Languages\Base\BaseLanguage;

final class LuaLanguage extends BaseLanguage
{
    public function getName(): string
    {
        return 'lua';
    }

    #[Override]
    public function getAliases(): array
    {
        return [];
    }

    #[Override]
    public function getInjections(): array
    {
        return [
            ...parent::getInjections(),
        ];
    }

    #[Override]
    public function getPatterns(): array
    {
        return [
            ...parent::getPatterns(),

            // Keywords first (before operators, so "not"/"or" aren't caught by operator pattern)
            new LuaKeywordPattern,

            // Built-in globals
            new LuaBuiltinPattern,

            // Function definitions
            new LuaFunctionPattern,

            // Comments (before strings so -- inside strings isn't caught)
            new LuaCommentPattern,

            // Numbers
            new LuaNumberPattern,

            // Strings
            new LuaStringPattern,

            // Operators
            new LuaOperatorPattern,
        ];
    }
}

<?php

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

class KosmokratorTerminalTheme implements TerminalTheme
{
    use EscapesTerminalTheme;

    public function before(TokenType $tokenType): string
    {
        return match ($tokenType) {
            TokenTypeEnum::KEYWORD => Theme::code(),
            TokenTypeEnum::OPERATOR => Theme::white(),
            TokenTypeEnum::TYPE => Theme::warning(),
            TokenTypeEnum::VALUE => Theme::success(),
            TokenTypeEnum::NUMBER => Theme::accent(),
            TokenTypeEnum::LITERAL => Theme::info(),
            TokenTypeEnum::VARIABLE => Theme::white(),
            TokenTypeEnum::PROPERTY => Theme::info(),
            TokenTypeEnum::GENERIC => Theme::link(),
            TokenTypeEnum::COMMENT => Theme::dim(),
            TokenTypeEnum::ATTRIBUTE => Theme::code(),
            TokenTypeEnum::INJECTION => '',
            TokenTypeEnum::HIDDEN => "\033[8m",
            default => '',
        };
    }

    public function after(TokenType $tokenType): string
    {
        return Theme::reset();
    }

    public static function detectLanguage(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'php' => 'php',
            'js', 'mjs', 'cjs' => 'javascript',
            'ts', 'tsx' => 'javascript',
            'py' => 'python',
            'sql' => 'sql',
            'html', 'htm' => 'html',
            'css', 'scss', 'sass' => 'css',
            'json' => 'json',
            'xml', 'svg' => 'xml',
            'yaml', 'yml' => 'yaml',
            'md', 'markdown' => 'markdown',
            'dockerfile' => 'dockerfile',
            'env' => 'dotenv',
            'ini', 'cfg', 'conf' => 'ini',
            'twig' => 'twig',
            'diff', 'patch' => 'diff',
            default => '',
        };
    }
}

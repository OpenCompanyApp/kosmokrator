<?php

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * Maps Tempest Highlight token types to Kosmokrator Theme colors for syntax highlighting.
 *
 * Used by MarkdownToAnsi (ANSI path of the dual TUI/ANSI rendering layer) to colorize
 * fenced code blocks in terminal output.
 */
class KosmokratorTerminalTheme implements TerminalTheme
{
    use EscapesTerminalTheme;

    /**
     * Returns the ANSI escape sequence to apply before a highlighted token.
     * Maps each Tempest token type to a Kosmokrator Theme color.
     *
     * @param TokenType $tokenType Syntax token type from the highlighter
     * @return string ANSI escape sequence (empty string for unstyled tokens)
     */
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
            TokenTypeEnum::HIDDEN => "\033[8m", // ANSI escape: hide text (invisible, e.g. for zero-width markers)
            default => '',
        };
    }

    /** Returns Theme::reset() to clear all styling after any token. @param TokenType $tokenType */
    public function after(TokenType $tokenType): string
    {
        return Theme::reset();
    }

    /**
     * Maps a file extension to the corresponding Tempest Highlight language identifier.
     *
     * @param string $path File path or filename with extension
     * @return string Language identifier (e.g. 'php', 'javascript') or empty string if unknown
     */
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

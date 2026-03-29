<?php

namespace Kosmokrator\UI\Tui;

use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\BorderPattern;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Style\TextAlign;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

class KosmokratorStyleSheet
{
    public static function create(): StyleSheet
    {
        return new StyleSheet([
            // Root session container
            '.session' => new Style(
                direction: Direction::Vertical,
            ),

            // FIGlet header
            '.figlet-header' => new Style(
                color: Color::hex('#ff3c28'),
                bold: true,
                font: 'big',
                padding: new Padding(1, 2, 0, 2),
            ),

            // Subtitle line
            '.subtitle' => new Style(
                color: Color::hex('#ffc850'),
                italic: true,
                textAlign: TextAlign::Center,
                padding: new Padding(0, 2, 0, 2),
            ),

            // Tagline
            '.tagline' => new Style(
                color: Color::hex('#a0a0a0'),
                textAlign: TextAlign::Center,
                padding: new Padding(0, 2, 0, 2),
            ),

            // Welcome message
            '.welcome' => new Style(
                color: Color::hex('#a0a0a0'),
                padding: new Padding(1, 2, 0, 2),
            ),

            // User message echo
            '.user-message' => new Style(
                color: Color::hex('#ffffff'),
                bold: true,
                padding: new Padding(1, 2, 0, 2),
            ),

            // Separator
            '.separator' => new Style(
                color: Color::hex('#606060'),
                padding: new Padding(0, 2, 0, 2),
            ),

            // Response area (markdown)
            '.response' => new Style(
                padding: new Padding(0, 2, 0, 2),
            ),

            // Tool call display — double border for mythological ornate feel
            '.tool-call' => new Style(
                border: Border::all(1, BorderPattern::double(), Color::hex('#806428')),
                padding: new Padding(0, 1, 0, 1),
                color: Color::hex('#ffc850'),
            ),

            '.tool-result' => new Style(
                color: Color::hex('#a0a0a0'),
                padding: new Padding(0, 3, 0, 3),
            ),

            '.tool-success' => new Style(
                color: Color::hex('#50dc64'),
                padding: new Padding(0, 3, 0, 3),
            ),

            '.tool-error' => new Style(
                color: Color::hex('#ff5040'),
                padding: new Padding(0, 3, 0, 3),
            ),

            // Status bar
            '.status-bar' => new Style(
                color: Color::hex('#909090'),
                padding: new Padding(0, 1, 0, 1),
            ),

            // Editor prompt (Enter = submit, Shift+Enter = newline)
            // EditorWidget draws its own ─── top/bottom borders via 'frame' sub-element
            EditorWidget::class => new Style(
                padding: new Padding(0, 1, 0, 1),
                color: Color::hex('#dcdcdc'),
            ),

            EditorWidget::class . '::frame' => new Style(
                color: Color::hex('#a02018'),
            ),

            EditorWidget::class . ':focus::frame' => new Style(
                color: Color::hex('#ff5040'),
            ),

            // Context progress bar
            ProgressBarWidget::class => new Style(
                color: Color::hex('#909090'),
                padding: new Padding(0, 1, 0, 1),
            ),

            // ANSI art response (no color/attribute styling to preserve raw ANSI codes)
            '.ansi-art' => new Style(
                padding: new Padding(0, 2, 0, 2),
            ),

            // Thinking loader (animated spinner)
            CancellableLoaderWidget::class => new Style(
                color: Color::hex('#ffc850'),
                italic: true,
                padding: new Padding(1, 2, 0, 2),
            ),

            // Markdown widget — cap width for readability
            MarkdownWidget::class => new Style(
                padding: new Padding(0, 2, 0, 2),
                maxColumns: 100,
            ),

            // Permission prompt (tool approval)
            '.permission-prompt' => new Style(
                border: Border::all(1, BorderPattern::rounded(), Color::hex('#ffc850')),
                padding: new Padding(0, 1, 0, 1),
                color: Color::hex('#ffc850'),
            ),

            // Slash command completion
            '.slash-completion' => new Style(
                color: Color::hex('#a0a0a0'),
                padding: new Padding(0, 2, 0, 2),
            ),
        ]);
    }
}

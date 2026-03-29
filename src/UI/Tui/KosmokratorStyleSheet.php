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
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;

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
                color: Color::hex('#808080'),
                dim: true,
                textAlign: TextAlign::Center,
                padding: new Padding(0, 2, 0, 2),
            ),

            // Welcome message
            '.welcome' => new Style(
                color: Color::hex('#808080'),
                dim: true,
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
                color: Color::hex('#404040'),
                dim: true,
                padding: new Padding(0, 2, 0, 2),
            ),

            // Response area (markdown)
            '.response' => new Style(
                padding: new Padding(0, 2, 0, 2),
            ),

            // Tool call display
            '.tool-call' => new Style(
                border: Border::all(1, BorderPattern::rounded(), Color::hex('#64c8ff')),
                padding: new Padding(0, 1, 0, 1),
                color: Color::hex('#64c8ff'),
            ),

            '.tool-result' => new Style(
                padding: new Padding(0, 3, 0, 3),
                dim: true,
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
                color: Color::hex('#808080'),
                dim: true,
                padding: new Padding(0, 1, 0, 1),
            ),

            // Input prompt
            InputWidget::class => new Style(
                border: Border::all(1, BorderPattern::rounded(), Color::hex('#a02018')),
                padding: new Padding(0, 1, 0, 1),
                color: Color::hex('#dcdcdc'),
            ),

            InputWidget::class . ':focus' => new Style(
                border: Border::all(1, BorderPattern::rounded(), Color::hex('#ff5040')),
            ),

            // Thinking loader
            CancellableLoaderWidget::class => new Style(
                color: Color::hex('#ffc850'),
                padding: new Padding(0, 2, 0, 2),
            ),

            // Markdown widget
            MarkdownWidget::class => new Style(
                padding: new Padding(0, 2, 0, 2),
            ),
        ]);
    }
}

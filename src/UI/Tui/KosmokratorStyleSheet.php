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
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class KosmokratorStyleSheet
{
    public static function create(): StyleSheet
    {
        return new StyleSheet([
            // Root container
            '.session' => new Style(
                direction: Direction::Vertical,
            ),

            // Header / intro
            '.header' => new Style(
                color: Color::hex('#ff3c28'),
                bold: true,
                padding: new Padding(0, 1, 0, 1),
            ),

            '.subtitle' => new Style(
                color: Color::hex('#ffc850'),
                italic: true,
                textAlign: TextAlign::Center,
            ),

            // Response area
            '.response' => new Style(
                padding: new Padding(1, 2, 1, 2),
            ),

            // Tool call display
            '.tool-call' => new Style(
                border: Border::all(1, BorderPattern::rounded(), Color::hex('#64c8ff')),
                padding: new Padding(0, 1, 0, 1),
                color: Color::hex('#64c8ff'),
            ),

            '.tool-result' => new Style(
                padding: new Padding(0, 2, 0, 2),
                dim: true,
            ),

            '.tool-success' => new Style(
                color: Color::hex('#50dc64'),
            ),

            '.tool-error' => new Style(
                color: Color::hex('#ff5040'),
            ),

            // Status bar
            '.status-bar' => new Style(
                color: Color::hex('#808080'),
                dim: true,
                padding: new Padding(0, 1, 0, 1),
            ),

            // Input prompt
            InputWidget::class => new Style(
                border: Border::all(1, BorderPattern::rounded(), Color::hex('#ff3c28')),
                padding: new Padding(0, 1, 0, 1),
                color: Color::hex('#dcdcdc'),
            ),

            InputWidget::class . ':focus' => new Style(
                border: Border::all(1, BorderPattern::rounded(), Color::hex('#ff5040')),
            ),

            // Loader / thinking
            LoaderWidget::class => new Style(
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

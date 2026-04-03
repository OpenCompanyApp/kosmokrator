<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\KosmokratorStyleSheet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Style\TextAlign;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\SettingsListWidget;

final class KosmokratorStyleSheetTest extends TestCase
{
    public function testCreateReturnsStyleSheet(): void
    {
        $sheet = KosmokratorStyleSheet::create();

        $this->assertInstanceOf(StyleSheet::class, $sheet);
    }

    public function testCreateReturnsNewInstanceEachCall(): void
    {
        $a = KosmokratorStyleSheet::create();
        $b = KosmokratorStyleSheet::create();

        $this->assertNotSame($a, $b);
    }

    public function testAllExpectedSelectorsAreDefined(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $expectedSelectors = [
            // Layout
            '.session',
            // Header area
            '.figlet-header',
            '.subtitle',
            '.tagline',
            '.welcome',
            // Messages
            '.user-message',
            '.separator',
            '.response',
            // Tool display
            '.tool-call',
            '.task-call',
            '.tool-result',
            '.tool-batch',
            '.tool-shell',
            '.tool-success',
            '.tool-error',
            // Status bar
            '.status-bar',
            // Editor
            EditorWidget::class,
            EditorWidget::class.'::frame',
            EditorWidget::class.':focus::frame',
            // Progress bar
            ProgressBarWidget::class,
            ProgressBarWidget::class.'::bar-fill',
            ProgressBarWidget::class.'::bar-progress',
            ProgressBarWidget::class.'::bar-empty',
            // ANSI art
            '.ansi-art',
            // Compacting loader
            '.compacting',
            '.compacting::spinner',
            '.compacting::message',
            // Thinking loader
            CancellableLoaderWidget::class,
            CancellableLoaderWidget::class.'::spinner',
            CancellableLoaderWidget::class.'::message',
            // Subagent loader
            '.subagent-loader',
            // Markdown
            MarkdownWidget::class,
            // Permission prompt
            '.permission-prompt',
            // Slash completion
            '.slash-completion',
            // Settings
            SettingsListWidget::class,
            SettingsListWidget::class.'::label-selected',
            SettingsListWidget::class.'::value',
            SettingsListWidget::class.'::value-selected',
            SettingsListWidget::class.'::description',
            SettingsListWidget::class.'::hint',
        ];

        foreach ($expectedSelectors as $selector) {
            $this->assertArrayHasKey($selector, $rules, \sprintf('Missing selector: %s', $selector));
        }
    }

    public function testTotalRuleCount(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $this->assertCount(40, $rules);
    }

    public function testAllRulesAreStyleObjects(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        foreach ($rules as $selector => $style) {
            $this->assertInstanceOf(Style::class, $style, \sprintf('Rule "%s" is not a Style instance', $selector));
        }
    }

    // --- Specific style property assertions ---

    public function testSessionHasVerticalDirection(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $this->assertSame(Direction::Vertical, $rules['.session']->getDirection());
    }

    public function testFigletHeaderIsBoldRedWithFont(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.figlet-header'];

        $this->assertTrue($style->getBold());
        $this->assertSame('big', $style->getFont());
        $this->assertNotNull($style->getColor());
        $this->assertSame(1, $style->getPadding()->getTop());
        $this->assertSame(2, $style->getPadding()->getRight());
        $this->assertSame(0, $style->getPadding()->getBottom());
        $this->assertSame(2, $style->getPadding()->getLeft());
    }

    public function testSubtitleIsItalicCentered(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.subtitle'];

        $this->assertTrue($style->getItalic());
        $this->assertSame(TextAlign::Center, $style->getTextAlign());
        $this->assertNotNull($style->getColor());
    }

    public function testTaglineIsCentered(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.tagline'];

        $this->assertSame(TextAlign::Center, $style->getTextAlign());
        $this->assertNotNull($style->getColor());
    }

    public function testUserMessageIsBoldWhite(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.user-message'];

        $this->assertTrue($style->getBold());
        $this->assertNotNull($style->getColor());
    }

    public function testSeparatorHasGrayColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.separator'];

        $this->assertNotNull($style->getColor());
    }

    public function testResponseHasNoExplicitColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.response'];

        $this->assertNull($style->getColor());
        $this->assertNotNull($style->getPadding());
    }

    public function testToolCallHasColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.tool-call'];

        $this->assertNotNull($style->getColor());
    }

    public function testTaskCallHasNoTopPadding(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.task-call'];

        $this->assertSame(0, $style->getPadding()->getTop());
        $this->assertNotNull($style->getColor());
    }

    public function testToolSuccessHasGreenColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.tool-success'];

        $this->assertNotNull($style->getColor());
    }

    public function testToolErrorHasRedColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.tool-error'];

        $this->assertNotNull($style->getColor());
    }

    public function testEditorWidgetHasColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[EditorWidget::class];

        $this->assertNotNull($style->getColor());
        $this->assertNotNull($style->getPadding());
    }

    public function testEditorFrameColors(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $this->assertNotNull($rules[EditorWidget::class.'::frame']->getColor());
        $this->assertNotNull($rules[EditorWidget::class.':focus::frame']->getColor());
    }

    public function testProgressBarBarFillHasColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[ProgressBarWidget::class.'::bar-fill'];

        $this->assertNotNull($style->getColor());
    }

    public function testProgressBarBarEmptyHasColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[ProgressBarWidget::class.'::bar-empty'];

        $this->assertNotNull($style->getColor());
    }

    public function testAnsiArtHasNoColor(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.ansi-art'];

        $this->assertNull($style->getColor());
        $this->assertNull($style->getBold());
        $this->assertNull($style->getItalic());
    }

    public function testCompactingIsItalicRed(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.compacting'];

        $this->assertTrue($style->getItalic());
        $this->assertNotNull($style->getColor());
    }

    public function testCancellableLoaderIsItalic(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[CancellableLoaderWidget::class];

        $this->assertTrue($style->getItalic());
        $this->assertNotNull($style->getColor());
    }

    public function testCancellableLoaderSpinnerAndMessageElements(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $this->assertNotNull($rules[CancellableLoaderWidget::class.'::spinner']->getColor());
        $this->assertNotNull($rules[CancellableLoaderWidget::class.'::message']->getColor());
        $this->assertTrue($rules[CancellableLoaderWidget::class.'::message']->getItalic());
    }

    public function testMarkdownWidgetHasMaxColumns(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[MarkdownWidget::class];

        $this->assertSame(100, $style->getMaxColumns());
    }

    public function testPermissionPromptHasBorder(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.permission-prompt'];

        $this->assertNotNull($style->getBorder());
        $this->assertNotNull($style->getColor());
    }

    public function testSettingsListWidgetHasBorder(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[SettingsListWidget::class];

        $this->assertNotNull($style->getBorder());
        $this->assertNotNull($style->getColor());
    }

    public function testSettingsLabelSelectedIsBoldWhite(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[SettingsListWidget::class.'::label-selected'];

        $this->assertTrue($style->getBold());
        $this->assertNotNull($style->getColor());
    }

    public function testSettingsValueSelectedIsBoldGreen(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[SettingsListWidget::class.'::value-selected'];

        $this->assertTrue($style->getBold());
        $this->assertNotNull($style->getColor());
    }

    public function testSettingsDescriptionIsItalic(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[SettingsListWidget::class.'::description'];

        $this->assertTrue($style->getItalic());
    }

    public function testSubagentLoaderHasNoTopPadding(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.subagent-loader'];

        $this->assertSame(0, $style->getPadding()->getTop());
    }
}

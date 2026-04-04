<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\KosmokratorStyleSheet;
use PHPUnit\Framework\TestCase;
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
    public function test_create_returns_style_sheet(): void
    {
        $sheet = KosmokratorStyleSheet::create();

        $this->assertInstanceOf(StyleSheet::class, $sheet);
    }

    public function test_create_returns_new_instance_each_call(): void
    {
        $a = KosmokratorStyleSheet::create();
        $b = KosmokratorStyleSheet::create();

        $this->assertNotSame($a, $b);
    }

    public function test_all_expected_selectors_are_defined(): void
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

    public function test_total_rule_count(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $this->assertCount(40, $rules);
    }

    public function test_all_rules_are_style_objects(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        foreach ($rules as $selector => $style) {
            $this->assertInstanceOf(Style::class, $style, \sprintf('Rule "%s" is not a Style instance', $selector));
        }
    }

    // --- Specific style property assertions ---

    public function test_session_has_vertical_direction(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $this->assertSame(Direction::Vertical, $rules['.session']->getDirection());
    }

    public function test_figlet_header_is_bold_red_with_font(): void
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

    public function test_subtitle_is_italic_centered(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.subtitle'];

        $this->assertTrue($style->getItalic());
        $this->assertSame(TextAlign::Center, $style->getTextAlign());
        $this->assertNotNull($style->getColor());
    }

    public function test_tagline_is_centered(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.tagline'];

        $this->assertSame(TextAlign::Center, $style->getTextAlign());
        $this->assertNotNull($style->getColor());
    }

    public function test_user_message_is_bold_white(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.user-message'];

        $this->assertTrue($style->getBold());
        $this->assertNotNull($style->getColor());
    }

    public function test_separator_has_gray_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.separator'];

        $this->assertNotNull($style->getColor());
    }

    public function test_response_has_no_explicit_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.response'];

        $this->assertNull($style->getColor());
        $this->assertNotNull($style->getPadding());
    }

    public function test_tool_call_has_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.tool-call'];

        $this->assertNotNull($style->getColor());
    }

    public function test_task_call_has_no_top_padding(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.task-call'];

        $this->assertSame(0, $style->getPadding()->getTop());
        $this->assertNotNull($style->getColor());
    }

    public function test_tool_success_has_green_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.tool-success'];

        $this->assertNotNull($style->getColor());
    }

    public function test_tool_error_has_red_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.tool-error'];

        $this->assertNotNull($style->getColor());
    }

    public function test_editor_widget_has_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[EditorWidget::class];

        $this->assertNotNull($style->getColor());
        $this->assertNotNull($style->getPadding());
    }

    public function test_editor_frame_colors(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $this->assertNotNull($rules[EditorWidget::class.'::frame']->getColor());
        $this->assertNotNull($rules[EditorWidget::class.':focus::frame']->getColor());
    }

    public function test_progress_bar_bar_fill_has_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[ProgressBarWidget::class.'::bar-fill'];

        $this->assertNotNull($style->getColor());
    }

    public function test_progress_bar_bar_empty_has_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[ProgressBarWidget::class.'::bar-empty'];

        $this->assertNotNull($style->getColor());
    }

    public function test_ansi_art_has_no_color(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.ansi-art'];

        $this->assertNull($style->getColor());
        $this->assertNull($style->getBold());
        $this->assertNull($style->getItalic());
    }

    public function test_compacting_is_italic_red(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.compacting'];

        $this->assertTrue($style->getItalic());
        $this->assertNotNull($style->getColor());
    }

    public function test_cancellable_loader_is_italic(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[CancellableLoaderWidget::class];

        $this->assertTrue($style->getItalic());
        $this->assertNotNull($style->getColor());
    }

    public function test_cancellable_loader_spinner_and_message_elements(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();

        $this->assertNotNull($rules[CancellableLoaderWidget::class.'::spinner']->getColor());
        $this->assertNotNull($rules[CancellableLoaderWidget::class.'::message']->getColor());
        $this->assertTrue($rules[CancellableLoaderWidget::class.'::message']->getItalic());
    }

    public function test_markdown_widget_has_max_columns(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[MarkdownWidget::class];

        $this->assertSame(100, $style->getMaxColumns());
    }

    public function test_permission_prompt_has_border(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.permission-prompt'];

        $this->assertNotNull($style->getBorder());
        $this->assertNotNull($style->getColor());
    }

    public function test_settings_list_widget_has_border(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[SettingsListWidget::class];

        $this->assertNotNull($style->getBorder());
        $this->assertNotNull($style->getColor());
    }

    public function test_settings_label_selected_is_bold_white(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[SettingsListWidget::class.'::label-selected'];

        $this->assertTrue($style->getBold());
        $this->assertNotNull($style->getColor());
    }

    public function test_settings_value_selected_is_bold_green(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[SettingsListWidget::class.'::value-selected'];

        $this->assertTrue($style->getBold());
        $this->assertNotNull($style->getColor());
    }

    public function test_settings_description_is_italic(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules[SettingsListWidget::class.'::description'];

        $this->assertTrue($style->getItalic());
    }

    public function test_subagent_loader_has_no_top_padding(): void
    {
        $rules = KosmokratorStyleSheet::create()->getRules();
        $style = $rules['.subagent-loader'];

        $this->assertSame(0, $style->getPadding()->getTop());
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\DiscoveryBatchWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class DiscoveryBatchWidgetTest extends TestCase
{
    private function makeItem(
        string $name = 'file_read',
        string $label = 'src/file.php',
        string $detail = 'file contents',
        string $summary = '100 lines',
        string $status = 'success',
    ): array {
        return [
            'name' => $name,
            'label' => $label,
            'detail' => $detail,
            'summary' => $summary,
            'status' => $status,
        ];
    }

    public function test_constructor_with_empty_items(): void
    {
        $widget = new DiscoveryBatchWidget([]);

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
        $content = implode("\n", $lines);
        $this->assertStringContainsString('No omens yet', $content);
    }

    public function test_constructor_with_default_empty_items(): void
    {
        $widget = new DiscoveryBatchWidget();

        $lines = $widget->render(new RenderContext(80, 24));

        $content = implode("\n", $lines);
        $this->assertStringContainsString('No omens yet', $content);
    }

    public function test_default_state_is_collapsed(): void
    {
        $widget = new DiscoveryBatchWidget();

        $this->assertFalse($widget->isExpanded());
    }

    public function test_toggle_switches_state(): void
    {
        $widget = new DiscoveryBatchWidget();

        $widget->toggle();
        $this->assertTrue($widget->isExpanded());

        $widget->toggle();
        $this->assertFalse($widget->isExpanded());
    }

    public function test_setExpanded_sets_state(): void
    {
        $widget = new DiscoveryBatchWidget();

        $widget->setExpanded(true);
        $this->assertTrue($widget->isExpanded());

        $widget->setExpanded(false);
        $this->assertFalse($widget->isExpanded());
    }

    public function test_setItems_normalizes_tabs_in_detail(): void
    {
        $widget = new DiscoveryBatchWidget();
        $widget->setItems([
            $this->makeItem(detail: "col1\tcol2\tcol3"),
        ]);

        $widget->setExpanded(true);
        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString("\t", $content);
    }

    public function test_render_collapsed_shows_labels_and_reveal_hint(): void
    {
        $items = [
            $this->makeItem(label: 'file1.php'),
            $this->makeItem(name: 'grep', label: 'pattern match'),
        ];
        $widget = new DiscoveryBatchWidget($items);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('file1.php', $content);
        $this->assertStringContainsString('pattern match', $content);
        $this->assertStringContainsString('ctrl+o to reveal', $content);
    }

    public function test_render_expanded_shows_details_and_collapse_hint(): void
    {
        $items = [
            $this->makeItem(detail: "line1\nline2"),
        ];
        $widget = new DiscoveryBatchWidget($items);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('ctrl+o to collapse', $content);
    }

    public function test_render_expanded_shows_pending_status(): void
    {
        $items = [
            $this->makeItem(status: 'pending'),
        ];
        $widget = new DiscoveryBatchWidget($items);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('awaiting result', $content);
    }

    public function test_render_expanded_shows_detail_lines(): void
    {
        $items = [
            $this->makeItem(detail: "line1\nline2\nline3"),
        ];
        $widget = new DiscoveryBatchWidget($items);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('line1', $content);
        $this->assertStringContainsString('line3', $content);
    }

    public function test_format_summary_counts_tool_types(): void
    {
        $items = [
            $this->makeItem(name: 'file_read'),
            $this->makeItem(name: 'file_read'),
            $this->makeItem(name: 'grep'),
            $this->makeItem(name: 'bash'),
        ];
        $widget = new DiscoveryBatchWidget($items);

        $lines = $widget->render(new RenderContext(120, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('2 reads', $content);
        $this->assertStringContainsString('1 search', $content);
        $this->assertStringContainsString('1 probe', $content);
    }

    public function test_format_summary_singular_labels(): void
    {
        $items = [
            $this->makeItem(name: 'file_read'),
            $this->makeItem(name: 'glob'),
        ];
        $widget = new DiscoveryBatchWidget($items);

        $lines = $widget->render(new RenderContext(120, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('1 read', $content);
        $this->assertStringContainsString('1 glob', $content);
    }

    public function test_format_summary_includes_memory_search(): void
    {
        $items = [
            $this->makeItem(name: 'memory_search'),
            $this->makeItem(name: 'memory_search'),
            $this->makeItem(name: 'memory_search'),
        ];
        $widget = new DiscoveryBatchWidget($items);

        $lines = $widget->render(new RenderContext(120, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('3 recalls', $content);
    }

    public function test_render_expanded_shows_success_icon(): void
    {
        $items = [$this->makeItem(status: 'success', label: 'test.php')];
        $widget = new DiscoveryBatchWidget($items);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('✓', $content);
        $this->assertStringContainsString('Read', $content);
    }

    public function test_render_expanded_shows_error_icon(): void
    {
        $items = [$this->makeItem(status: 'error', label: 'missing.php')];
        $widget = new DiscoveryBatchWidget($items);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('✗', $content);
    }

    public function test_render_expanded_shows_friendly_tool_names(): void
    {
        $items = [
            $this->makeItem(name: 'file_read'),
            $this->makeItem(name: 'glob'),
            $this->makeItem(name: 'grep'),
            $this->makeItem(name: 'bash'),
            $this->makeItem(name: 'memory_search'),
        ];
        $widget = new DiscoveryBatchWidget($items);
        $widget->setExpanded(true);

        $lines = $widget->render(new RenderContext(120, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Read', $content);
        $this->assertStringContainsString('Scan', $content);
        $this->assertStringContainsString('Search', $content);
        $this->assertStringContainsString('Probe', $content);
        $this->assertStringContainsString('Recall', $content);
    }
}

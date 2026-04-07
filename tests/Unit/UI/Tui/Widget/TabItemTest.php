<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\TabItem;
use PHPUnit\Framework\TestCase;

final class TabItemTest extends TestCase
{
    public function test_constructor_properties(): void
    {
        $item = new TabItem('files', 'Files', 1);

        $this->assertSame('files', $item->id);
        $this->assertSame('Files', $item->label);
        $this->assertSame(1, $item->shortcut);
    }

    public function test_constructor_null_shortcut(): void
    {
        $item = new TabItem('settings', 'Settings');

        $this->assertSame('settings', $item->id);
        $this->assertSame('Settings', $item->label);
        $this->assertNull($item->shortcut);
    }

    public function test_from_labels_factory(): void
    {
        $items = TabItem::fromLabels(['Files', 'Branches', 'Commits']);

        $this->assertCount(3, $items);

        // First tab
        $this->assertSame('files', $items[0]->id);
        $this->assertSame('Files', $items[0]->label);
        $this->assertSame(1, $items[0]->shortcut);

        // Second tab
        $this->assertSame('branches', $items[1]->id);
        $this->assertSame('Branches', $items[1]->label);
        $this->assertSame(2, $items[1]->shortcut);

        // Third tab
        $this->assertSame('commits', $items[2]->id);
        $this->assertSame('Commits', $items[2]->label);
        $this->assertSame(3, $items[2]->shortcut);
    }

    public function test_from_labels_id_sanitization(): void
    {
        $items = TabItem::fromLabels(['My Tab Name']);

        $this->assertSame('my-tab-name', $items[0]->id);
    }

    public function test_from_labels_shortcut_limit(): void
    {
        $labels = ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'];
        $items = TabItem::fromLabels($labels);

        // First 9 tabs get shortcuts
        for ($i = 0; $i < 9; $i++) {
            $this->assertSame($i + 1, $items[$i]->shortcut, "Tab at index {$i} should have shortcut " . ($i + 1));
        }

        // 10th tab has no shortcut
        $this->assertNull($items[9]->shortcut, '10th tab should have no shortcut');
    }

    public function test_readonly_properties(): void
    {
        $item = new TabItem('id', 'Label', 3);

        // Verify all properties are accessible (readonly)
        $this->assertSame('id', $item->id);
        $this->assertSame('Label', $item->label);
        $this->assertSame(3, $item->shortcut);
    }

    public function test_shortcut_assignment(): void
    {
        // Explicit shortcut in constructor
        $item = new TabItem('test', 'Test', 5);
        $this->assertSame(5, $item->shortcut);

        // Null shortcut
        $item2 = new TabItem('test2', 'Test2', null);
        $this->assertNull($item2->shortcut);
    }
}

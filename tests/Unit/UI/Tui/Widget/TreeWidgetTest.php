<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use KosmoKrator\UI\Tui\Widget\Tree\TreeNode;
use KosmoKrator\UI\Tui\Widget\Tree\TreeState;
use KosmoKrator\UI\Tui\Widget\Tree\VisibleItem;
use KosmoKrator\UI\Tui\Widget\TreeWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class TreeWidgetTest extends TestCase
{
    // ── TreeNode Construction ─────────────────────────────────────────────

    public function test_treenode_basic_construction(): void
    {
        $node = new TreeNode(id: 'a', label: 'Alpha');

        $this->assertSame('a', $node->id);
        $this->assertSame('Alpha', $node->label);
        $this->assertNull($node->icon);
        $this->assertNull($node->detail);
        $this->assertSame([], $node->children);
        $this->assertNull($node->loadChildren);
        $this->assertFalse($node->expanded);
        $this->assertSame([], $node->metadata);
    }

    public function test_treenode_with_icon_detail_metadata(): void
    {
        $node = new TreeNode(
            id: 'b',
            label: 'Beta',
            icon: '📦',
            detail: '3 items',
            iconColor: "\033[33m",
            labelStyle: "\033[1m",
            detailStyle: "\033[2m",
            metadata: ['path' => '/foo/bar'],
        );

        $this->assertSame('📦', $node->icon);
        $this->assertSame('3 items', $node->detail);
        $this->assertSame("\033[33m", $node->iconColor);
        $this->assertSame("\033[1m", $node->labelStyle);
        $this->assertSame("\033[2m", $node->detailStyle);
        $this->assertSame(['path' => '/foo/bar'], $node->metadata);
    }

    public function test_treenode_hasChildren_with_prepopulated_children(): void
    {
        $child = new TreeNode(id: 'c1', label: 'Child 1');
        $parent = new TreeNode(id: 'p', label: 'Parent', children: [$child]);

        $this->assertTrue($parent->hasChildren());
    }

    public function test_treenode_hasChildren_with_loadChildren_callback(): void
    {
        $node = new TreeNode(
            id: 'lazy',
            label: 'Lazy',
            loadChildren: fn() => [new TreeNode(id: 'd1', label: 'Dynamic 1')],
        );

        $this->assertTrue($node->hasChildren());
        // children array is still empty
        $this->assertSame([], $node->children);
    }

    public function test_treenode_hasChildren_returns_false_for_leaf(): void
    {
        $leaf = new TreeNode(id: 'leaf', label: 'Leaf');

        $this->assertFalse($leaf->hasChildren());
    }

    public function test_treenode_withChildren_returns_new_instance_with_expanded(): void
    {
        $original = new TreeNode(id: 'p', label: 'Parent');
        $children = [
            new TreeNode(id: 'c1', label: 'Child 1'),
            new TreeNode(id: 'c2', label: 'Child 2'),
        ];

        $replaced = $original->withChildren($children);

        // New instance
        $this->assertNotSame($original, $replaced);
        $this->assertSame('p', $replaced->id);

        // Children populated
        $this->assertCount(2, $replaced->children);
        $this->assertSame('c1', $replaced->children[0]->id);

        // expanded=true
        $this->assertTrue($replaced->expanded);

        // loadChildren cleared
        $this->assertNull($replaced->loadChildren);

        // Original unchanged
        $this->assertSame([], $original->children);
    }

    public function test_treenode_withChildReplaced_deep_replaces_descendant(): void
    {
        $grandchild = new TreeNode(id: 'gc', label: 'Grandchild');
        $child = new TreeNode(id: 'c', label: 'Child', children: [$grandchild]);
        $parent = new TreeNode(id: 'p', label: 'Parent', children: [$child]);

        $newGrandchild = new TreeNode(id: 'gc', label: 'New Grandchild');
        $result = $parent->withChildReplaced('gc', $newGrandchild);

        // Top-level is a new instance
        $this->assertNotSame($parent, $result);

        // Grandchild was replaced deep in the tree
        $this->assertSame('New Grandchild', $result->children[0]->children[0]->label);

        // Original unchanged
        $this->assertSame('Grandchild', $parent->children[0]->children[0]->label);
    }

    public function test_treenode_withChildReplaced_no_match_returns_same_children(): void
    {
        $child = new TreeNode(id: 'c', label: 'Child');
        $parent = new TreeNode(id: 'p', label: 'Parent', children: [$child]);

        $replacement = new TreeNode(id: 'x', label: 'X');
        $result = $parent->withChildReplaced('nonexistent', $replacement);

        // Still a new instance (immutability)
        $this->assertNotSame($parent, $result);
        // Child unchanged
        $this->assertSame('c', $result->children[0]->id);
    }

    public function test_treenode_isChildrenLoaded_true_when_prepopulated(): void
    {
        $node = new TreeNode(
            id: 'p',
            label: 'P',
            children: [new TreeNode(id: 'c', label: 'C')],
        );

        $this->assertTrue($node->isChildrenLoaded());
    }

    public function test_treenode_isChildrenLoaded_false_when_lazy(): void
    {
        $node = new TreeNode(
            id: 'lazy',
            label: 'Lazy',
            loadChildren: fn() => [],
        );

        $this->assertFalse($node->isChildrenLoaded());
    }

    // ── TreeState Expand/Collapse ─────────────────────────────────────────

    private function makeFlatRoot(): TreeNode
    {
        return new TreeNode(
            id: '__tree_root__',
            label: '',
            children: [
                new TreeNode(id: 'a', label: 'Alpha'),
                new TreeNode(id: 'b', label: 'Beta'),
                new TreeNode(id: 'c', label: 'Gamma'),
            ],
        );
    }

    private function makeDeepRoot(): TreeNode
    {
        return new TreeNode(
            id: '__tree_root__',
            label: '',
            children: [
                new TreeNode(
                    id: 'parent',
                    label: 'Parent',
                    children: [
                        new TreeNode(id: 'child1', label: 'Child 1'),
                        new TreeNode(id: 'child2', label: 'Child 2'),
                    ],
                ),
                new TreeNode(id: 'sibling', label: 'Sibling'),
            ],
        );
    }

    public function test_treestate_initial_state_first_selected_nothing_expanded(): void
    {
        $root = $this->makeDeepRoot();
        $state = new TreeState($root);

        // First visible node should be auto-selected
        $visible = $state->getVisibleItems();
        $this->assertSame('parent', $state->getSelectedId());

        // Nothing expanded — only top-level items visible
        $this->assertCount(2, $visible); // parent, sibling (children collapsed)
    }

    public function test_treestate_setExpanded_isExpanded(): void
    {
        $root = $this->makeDeepRoot();
        $state = new TreeState($root);

        $this->assertFalse($state->isExpanded('parent'));

        $state->setExpanded('parent', true);
        $this->assertTrue($state->isExpanded('parent'));

        $state->setExpanded('parent', false);
        $this->assertFalse($state->isExpanded('parent'));
    }

    public function test_treestate_toggleExpanded(): void
    {
        $root = $this->makeDeepRoot();
        $state = new TreeState($root);

        $this->assertFalse($state->isExpanded('parent'));

        $state->toggleExpanded('parent');
        $this->assertTrue($state->isExpanded('parent'));

        $state->toggleExpanded('parent');
        $this->assertFalse($state->isExpanded('parent'));
    }

    public function test_treestate_getVisibleItems_collapsed_hides_children(): void
    {
        $root = $this->makeDeepRoot();
        $state = new TreeState($root);

        $visible = $state->getVisibleItems();
        $ids = array_map(fn(VIsibleItem $item) => $item->node->id, $visible);

        $this->assertSame(['parent', 'sibling'], $ids);
    }

    public function test_treestate_getVisibleItems_expanded_shows_children(): void
    {
        $root = $this->makeDeepRoot();
        $state = new TreeState($root);

        $state->setExpanded('parent', true);
        $visible = $state->getVisibleItems();
        $ids = array_map(fn(VisibleItem $item) => $item->node->id, $visible);

        $this->assertSame(['parent', 'child1', 'child2', 'sibling'], $ids);
    }

    public function test_treestate_initial_expanded_from_node(): void
    {
        $root = new TreeNode(
            id: '__tree_root__',
            label: '',
            children: [
                new TreeNode(
                    id: 'expanded-parent',
                    label: 'Expanded Parent',
                    children: [
                        new TreeNode(id: 'child', label: 'Child'),
                    ],
                    expanded: true,
                ),
            ],
        );

        $state = new TreeState($root);
        $visible = $state->getVisibleItems();
        $ids = array_map(fn(VisibleItem $item) => $item->node->id, $visible);

        $this->assertSame(['expanded-parent', 'child'], $ids);
        $this->assertTrue($state->isExpanded('expanded-parent'));
    }

    // ── TreeState Selection Navigation ────────────────────────────────────

    public function test_treestate_moveDown(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $this->assertSame('a', $state->getSelectedId());

        $this->assertTrue($state->moveDown());
        $this->assertSame('b', $state->getSelectedId());

        $this->assertTrue($state->moveDown());
        $this->assertSame('c', $state->getSelectedId());
    }

    public function test_treestate_moveUp(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $state->moveDown();
        $state->moveDown();
        $this->assertSame('c', $state->getSelectedId());

        $this->assertTrue($state->moveUp());
        $this->assertSame('b', $state->getSelectedId());

        $this->assertTrue($state->moveUp());
        $this->assertSame('a', $state->getSelectedId());
    }

    public function test_treestate_moveUp_at_top_returns_false(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $this->assertSame('a', $state->getSelectedId());
        $this->assertFalse($state->moveUp());
        $this->assertSame('a', $state->getSelectedId());
    }

    public function test_treestate_moveDown_at_bottom_returns_false(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $state->moveDown();
        $state->moveDown();
        $this->assertSame('c', $state->getSelectedId());

        $this->assertFalse($state->moveDown());
        $this->assertSame('c', $state->getSelectedId());
    }

    public function test_treestate_moveToFirst(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $state->moveDown();
        $state->moveDown();
        $this->assertSame('c', $state->getSelectedId());

        $this->assertTrue($state->moveToFirst());
        $this->assertSame('a', $state->getSelectedId());
    }

    public function test_treestate_moveToFirst_already_at_first_returns_false(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $this->assertFalse($state->moveToFirst());
    }

    public function test_treestate_moveToLast(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $this->assertSame('a', $state->getSelectedId());
        $this->assertTrue($state->moveToLast());
        $this->assertSame('c', $state->getSelectedId());
    }

    public function test_treestate_moveToLast_already_at_last_returns_false(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $state->moveToLast();
        $this->assertFalse($state->moveToLast());
    }

    public function test_treestate_moveToParent(): void
    {
        $root = $this->makeDeepRoot();
        $state = new TreeState($root);

        $state->setExpanded('parent', true);
        // Select child1
        $state->setSelectedId('child1');

        $this->assertTrue($state->moveToParent());
        $this->assertSame('parent', $state->getSelectedId());
    }

    public function test_treestate_moveToParent_at_top_level_returns_false(): void
    {
        $root = $this->makeDeepRoot();
        $state = new TreeState($root);

        $this->assertSame('parent', $state->getSelectedId());
        $this->assertFalse($state->moveToParent());
    }

    public function test_treestate_pageUp(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 20; $i++) {
            $nodes[] = new TreeNode(id: "n{$i}", label: "Node {$i}");
        }
        $root = new TreeNode(id: '__tree_root__', label: '', children: $nodes);
        $state = new TreeState($root);

        // Move to item 15 (0-indexed: 14)
        $state->setSelectedId('n15');

        // pageUp with viewport=5: move back 4 items to n11
        $this->assertTrue($state->pageUp(5));
        $this->assertSame('n11', $state->getSelectedId());
    }

    public function test_treestate_pageUp_clamps_to_first(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 20; $i++) {
            $nodes[] = new TreeNode(id: "n{$i}", label: "Node {$i}");
        }
        $root = new TreeNode(id: '__tree_root__', label: '', children: $nodes);
        $state = new TreeState($root);

        // Move to item 3
        $state->setSelectedId('n3');

        // pageUp with viewport=20 would go to -16 → clamped to 0 (n1)
        $this->assertTrue($state->pageUp(20));
        $this->assertSame('n1', $state->getSelectedId());
    }

    public function test_treestate_pageDown(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 20; $i++) {
            $nodes[] = new TreeNode(id: "n{$i}", label: "Node {$i}");
        }
        $root = new TreeNode(id: '__tree_root__', label: '', children: $nodes);
        $state = new TreeState($root);

        // Selected is n1 (auto-selected)
        $this->assertSame('n1', $state->getSelectedId());

        // pageDown with viewport=5: move forward 4 items to n5
        $this->assertTrue($state->pageDown(5));
        $this->assertSame('n5', $state->getSelectedId());
    }

    public function test_treestate_pageDown_clamps_to_last(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 5; $i++) {
            $nodes[] = new TreeNode(id: "n{$i}", label: "Node {$i}");
        }
        $root = new TreeNode(id: '__tree_root__', label: '', children: $nodes);
        $state = new TreeState($root);

        // Move to n3
        $state->setSelectedId('n3');

        // pageDown with viewport=20: would go past end → clamped to n5
        $this->assertTrue($state->pageDown(20));
        $this->assertSame('n5', $state->getSelectedId());
    }

    public function test_treestate_pageUp_at_first_returns_false(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $this->assertFalse($state->pageUp(10));
    }

    public function test_treestate_pageDown_at_last_returns_false(): void
    {
        $root = $this->makeFlatRoot();
        $state = new TreeState($root);

        $state->moveToLast();
        $this->assertFalse($state->pageDown(10));
    }

    // ── VisibleItem Depth Tracking ────────────────────────────────────────

    public function test_visibleitem_depth_and_connectors(): void
    {
        $root = new TreeNode(
            id: '__tree_root__',
            label: '',
            children: [
                new TreeNode(
                    id: 'p1',
                    label: 'Parent 1',
                    children: [
                        new TreeNode(id: 'c1', label: 'Child 1'),
                        new TreeNode(id: 'c2', label: 'Child 2'),
                    ],
                ),
                new TreeNode(id: 'p2', label: 'Parent 2'),
            ],
        );

        $state = new TreeState($root);
        $state->setExpanded('p1', true);
        $visible = $state->getVisibleItems();

        // p1: depth=0, hasMoreSiblings=true (p2 after it)
        $this->assertSame(0, $visible[0]->depth);
        $this->assertTrue($visible[0]->hasMoreSiblings);

        // c1: depth=1, ancestorHasMore=[true] (p1 has sibling p2), hasMoreSiblings=true (c2 after it)
        $this->assertSame(1, $visible[1]->depth);
        $this->assertSame([true], $visible[1]->ancestorHasMore);
        $this->assertTrue($visible[1]->hasMoreSiblings);

        // c2: depth=1, ancestorHasMore=[true], hasMoreSiblings=false (last child)
        $this->assertSame(1, $visible[2]->depth);
        $this->assertFalse($visible[2]->hasMoreSiblings);

        // p2: depth=0, hasMoreSiblings=false (last top-level)
        $this->assertSame(0, $visible[3]->depth);
        $this->assertFalse($visible[3]->hasMoreSiblings);
    }

    // ── TreeWidget Render ─────────────────────────────────────────────────

    public function test_render_empty_tree_returns_empty_array(): void
    {
        $widget = new TreeWidget([]);
        $context = new RenderContext(80, 24);

        $this->assertSame([], $widget->render($context));
    }

    public function test_render_single_top_level_node(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'only', label: 'Only Node'),
        ]);
        $context = new RenderContext(80, 24);

        $result = $widget->render($context);

        $this->assertNotEmpty($result);
        // Should contain the label text
        $found = false;
        foreach ($result as $line) {
            if (str_contains($line, 'Only Node')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Rendered output should contain "Only Node"');
    }

    public function test_render_tree_with_depth_shows_connectors(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [
                    new TreeNode(id: 'child1', label: 'Child 1'),
                    new TreeNode(id: 'child2', label: 'Child 2'),
                ],
            ),
        ]);

        // Expand parent
        $state = $widget->getState();
        $state->setExpanded('parent', true);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        // Find lines with child labels
        $child1Line = null;
        $child2Line = null;
        foreach ($result as $line) {
            if (str_contains($line, 'Child 1')) {
                $child1Line = $line;
            }
            if (str_contains($line, 'Child 2')) {
                $child2Line = $line;
            }
        }

        $this->assertNotNull($child1Line, 'Should render Child 1');
        $this->assertNotNull($child2Line, 'Should render Child 2');

        // Child 1 (not last sibling) should have ├─ connector
        $this->assertStringContainsString('├─', $child1Line);

        // Child 2 (last sibling) should have └─ connector
        $this->assertStringContainsString('└─', $child2Line);
    }

    public function test_render_collapsed_node_hides_children(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [
                    new TreeNode(id: 'child', label: 'Hidden Child'),
                ],
            ),
        ]);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        // Parent should be visible
        $parentFound = false;
        $childFound = false;
        foreach ($result as $line) {
            if (str_contains($line, 'Parent')) {
                $parentFound = true;
            }
            if (str_contains($line, 'Hidden Child')) {
                $childFound = true;
            }
        }

        $this->assertTrue($parentFound, 'Parent should be visible');
        $this->assertFalse($childFound, 'Child should be hidden when collapsed');
    }

    public function test_render_expanded_node_shows_children(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [
                    new TreeNode(id: 'child', label: 'Visible Child'),
                ],
            ),
        ]);

        $state = $widget->getState();
        $state->setExpanded('parent', true);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        $childFound = false;
        foreach ($result as $line) {
            if (str_contains($line, 'Visible Child')) {
                $childFound = true;
                break;
            }
        }

        $this->assertTrue($childFound, 'Child should be visible when expanded');
    }

    public function test_render_selected_node_has_highlight_when_focused(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'Alpha'),
            new TreeNode(id: 'b', label: 'Beta'),
        ]);
        $widget->setFocused(true);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        // First node is auto-selected; should have selection highlight (ANSI bg)
        $selectedLine = null;
        $unselectedLine = null;
        foreach ($result as $line) {
            if (str_contains($line, 'Alpha')) {
                $selectedLine = $line;
            }
            if (str_contains($line, 'Beta')) {
                $unselectedLine = $line;
            }
        }

        $this->assertNotNull($selectedLine);
        $this->assertNotNull($unselectedLine);

        // Selected line should contain a background color escape (48;2;)
        $this->assertStringContainsString('48;2;', $selectedLine,
            'Selected line should have a background color when focused');
    }

    public function test_render_selected_node_no_highlight_when_unfocused(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'Alpha'),
        ]);
        $widget->setFocused(false);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        $selectedLine = null;
        foreach ($result as $line) {
            if (str_contains($line, 'Alpha')) {
                $selectedLine = $line;
                break;
            }
        }

        $this->assertNotNull($selectedLine);
        // No bg color in the selected line (no 48;2;40;40;60)
        $this->assertStringNotContainsString('48;2;40;40;60', $selectedLine,
            'Selected line should NOT have selection background when unfocused');
    }

    public function test_render_expand_indicator_collapsed(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [new TreeNode(id: 'c', label: 'C')],
            ),
        ]);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        $parentLine = null;
        foreach ($result as $line) {
            if (str_contains($line, 'Parent')) {
                $parentLine = $line;
                break;
            }
        }

        $this->assertNotNull($parentLine);
        $this->assertStringContainsString('▸', $parentLine,
            'Collapsed node with children should show ▸ indicator');
    }

    public function test_render_expand_indicator_expanded(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [new TreeNode(id: 'c', label: 'C')],
            ),
        ]);
        $widget->getState()->setExpanded('parent', true);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        $parentLine = null;
        foreach ($result as $line) {
            if (str_contains($line, 'Parent')) {
                $parentLine = $line;
                break;
            }
        }

        $this->assertNotNull($parentLine);
        $this->assertStringContainsString('▾', $parentLine,
            'Expanded node should show ▾ indicator');
    }

    public function test_render_leaf_node_has_no_expand_indicator(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'leaf', label: 'Leaf'),
        ]);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        $leafLine = null;
        foreach ($result as $line) {
            if (str_contains($line, 'Leaf')) {
                $leafLine = $line;
                break;
            }
        }

        $this->assertNotNull($leafLine);
        $this->assertStringNotContainsString('▸', $leafLine);
        $this->assertStringNotContainsString('▾', $leafLine);
    }

    public function test_render_various_depths_proper_indentation(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'l1',
                label: 'Level 1',
                children: [
                    new TreeNode(
                        id: 'l2',
                        label: 'Level 2',
                        children: [
                            new TreeNode(id: 'l3', label: 'Level 3'),
                        ],
                    ),
                ],
            ),
        ]);

        $state = $widget->getState();
        $state->setExpanded('l1', true);
        $state->setExpanded('l2', true);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        $level1Line = $level2Line = $level3Line = null;
        foreach ($result as $line) {
            if (str_contains($line, 'Level 1') && $level1Line === null) {
                $level1Line = $line;
            }
            if (str_contains($line, 'Level 2') && $level2Line === null) {
                $level2Line = $line;
            }
            if (str_contains($line, 'Level 3') && $level3Line === null) {
                $level3Line = $line;
            }
        }

        $this->assertNotNull($level1Line);
        $this->assertNotNull($level2Line);
        $this->assertNotNull($level3Line);

        // Level 2 should have a connector (├─ or └─)
        $this->assertTrue(
            str_contains($level2Line, '├─') || str_contains($level2Line, '└─'),
            'Level 2 should have a tree connector'
        );

        // Level 3 should also have a connector
        $this->assertTrue(
            str_contains($level3Line, '├─') || str_contains($level3Line, '└─'),
            'Level 3 should have a tree connector'
        );

        // Strip ANSI and compare visual lengths to confirm deeper = more indented
        $stripAnsi = fn(string $s): string => preg_replace('/\033\[[0-9;]*m/', '', $s);
        $len1 = strlen($stripAnsi($level1Line));
        $len2 = strlen($stripAnsi($level2Line));
        $len3 = strlen($stripAnsi($level3Line));

        $this->assertGreaterThan($len1, $len2, 'Level 2 should be more indented than Level 1');
        $this->assertGreaterThan($len2, $len3, 'Level 3 should be more indented than Level 2');
    }

    public function test_render_icon_and_detail(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'fancy',
                label: 'Fancy',
                icon: '★',
                detail: '42 items',
            ),
        ]);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        $line = null;
        foreach ($result as $l) {
            if (str_contains($l, 'Fancy')) {
                $line = $l;
                break;
            }
        }

        $this->assertNotNull($line);
        $this->assertStringContainsString('★', $line, 'Should render icon');
        $this->assertStringContainsString('42 items', $line, 'Should render detail');
    }

    public function test_render_output_padded_to_height(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'A'),
        ]);

        $context = new RenderContext(80, 10);
        $result = $widget->render($context);

        // Should produce exactly 10 lines (padded)
        $this->assertCount(10, $result);
    }

    // ── TreeWidget handleInput ────────────────────────────────────────────

    public function test_handleInput_down_moves_selection(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'A'),
            new TreeNode(id: 'b', label: 'B'),
        ]);

        $this->assertSame('a', $widget->getSelectedNode()->id);

        // "\x1b[B" is the raw terminal sequence for Key::DOWN
        $widget->handleInput("\x1b[B");

        $this->assertSame('b', $widget->getSelectedNode()->id);
    }

    public function test_handleInput_up_moves_selection(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'A'),
            new TreeNode(id: 'b', label: 'B'),
        ]);

        $widget->handleInput("\x1b[B"); // down to B
        $widget->handleInput("\x1b[A"); // up to A

        $this->assertSame('a', $widget->getSelectedNode()->id);
    }

    public function test_handleInput_right_expands_collapsed_node(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [new TreeNode(id: 'child', label: 'Child')],
            ),
        ]);

        $this->assertFalse($widget->getState()->isExpanded('parent'));

        // "\x1b[C" is Key::RIGHT
        $widget->handleInput("\x1b[C");

        $this->assertTrue($widget->getState()->isExpanded('parent'));
    }

    public function test_handleInput_left_collapses_expanded_node(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [new TreeNode(id: 'child', label: 'Child')],
            ),
        ]);

        $widget->getState()->setExpanded('parent', true);
        $widget->handleInput("\x1b[D"); // Key::LEFT

        $this->assertFalse($widget->getState()->isExpanded('parent'));
    }

    public function test_handleInput_space_toggles(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [new TreeNode(id: 'child', label: 'Child')],
            ),
        ]);

        $this->assertFalse($widget->getState()->isExpanded('parent'));

        $widget->handleInput(' '); // Key::SPACE

        $this->assertTrue($widget->getState()->isExpanded('parent'));

        $widget->handleInput(' ');

        $this->assertFalse($widget->getState()->isExpanded('parent'));
    }

    public function test_handleInput_home_moves_to_first(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'A'),
            new TreeNode(id: 'b', label: 'B'),
            new TreeNode(id: 'c', label: 'C'),
        ]);

        // Move to C first
        $widget->getState()->setSelectedId('c');

        // "\x1b[H" is Key::HOME
        $widget->handleInput("\x1b[H");

        $this->assertSame('a', $widget->getSelectedNode()->id);
    }

    public function test_handleInput_end_moves_to_last(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'A'),
            new TreeNode(id: 'b', label: 'B'),
            new TreeNode(id: 'c', label: 'C'),
        ]);

        // "\x1b[F" is Key::END
        $widget->handleInput("\x1b[F");

        $this->assertSame('c', $widget->getSelectedNode()->id);
    }

    public function test_handleInput_enter_fires_select_callback(): void
    {
        $selected = null;
        $widget = new TreeWidget([
            new TreeNode(id: 'leaf', label: 'Leaf'),
        ]);
        $widget->onSelect(function (TreeNode $node) use (&$selected): void {
            $selected = $node;
        });

        // Enter on a leaf node fires the select callback
        $widget->handleInput("\r"); // Key::ENTER is "\r"

        $this->assertNotNull($selected);
        $this->assertSame('leaf', $selected->id);
    }

    public function test_handleInput_escape_fires_cancel_callback(): void
    {
        $cancelled = false;
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'A'),
        ]);
        $widget->onCancel(function () use (&$cancelled): void {
            $cancelled = true;
        });

        $widget->handleInput("\x1b"); // Key::ESCAPE

        $this->assertTrue($cancelled);
    }

    public function test_handleInput_right_on_expanded_node_fires_select(): void
    {
        $selected = null;
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [new TreeNode(id: 'c', label: 'C')],
            ),
        ]);
        $widget->getState()->setExpanded('parent', true);
        $widget->onSelect(function (TreeNode $node) use (&$selected): void {
            $selected = $node;
        });

        // Right on already-expanded node fires select
        $widget->handleInput("\x1b[C");

        $this->assertNotNull($selected);
        $this->assertSame('parent', $selected->id);
    }

    public function test_handleInput_left_on_leaf_moves_to_parent(): void
    {
        $widget = new TreeWidget([
            new TreeNode(
                id: 'parent',
                label: 'Parent',
                children: [new TreeNode(id: 'child', label: 'Child')],
            ),
        ]);
        $widget->getState()->setExpanded('parent', true);
        $widget->getState()->setSelectedId('child');

        $widget->handleInput("\x1b[D"); // LEFT

        $this->assertSame('parent', $widget->getSelectedNode()->id);
    }

    // ── TreeWidget State Management ───────────────────────────────────────

    public function test_setNodes_replaces_tree(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'old', label: 'Old'),
        ]);

        $widget->setNodes([
            new TreeNode(id: 'new', label: 'New'),
        ]);

        $this->assertSame('new', $widget->getSelectedNode()->id);
    }

    public function test_getSelectedNode_returns_null_for_empty_tree(): void
    {
        $widget = new TreeWidget([]);

        $this->assertNull($widget->getSelectedNode());
    }

    // ── TreeState setRoot preserves selection ─────────────────────────────

    public function test_treestate_setRoot_preserves_selection_if_node_exists(): void
    {
        $root1 = new TreeNode(
            id: '__tree_root__',
            label: '',
            children: [
                new TreeNode(id: 'a', label: 'A'),
                new TreeNode(id: 'b', label: 'B'),
            ],
        );
        $state = new TreeState($root1);
        $state->setSelectedId('b');

        $root2 = new TreeNode(
            id: '__tree_root__',
            label: '',
            children: [
                new TreeNode(id: 'a', label: 'A updated'),
                new TreeNode(id: 'b', label: 'B updated'),
            ],
        );
        $state->setRoot($root2);

        $this->assertSame('b', $state->getSelectedId());
    }

    public function test_treestate_setRoot_resets_selection_if_node_gone(): void
    {
        $root1 = new TreeNode(
            id: '__tree_root__',
            label: '',
            children: [
                new TreeNode(id: 'a', label: 'A'),
                new TreeNode(id: 'b', label: 'B'),
            ],
        );
        $state = new TreeState($root1);
        $state->setSelectedId('b');

        $root2 = new TreeNode(
            id: '__tree_root__',
            label: '',
            children: [
                new TreeNode(id: 'c', label: 'C'),
            ],
        );
        $state->setRoot($root2);

        // 'b' no longer exists; selectedId should be null then auto-selected to first
        $visible = $state->getVisibleItems();
        $this->assertSame('c', $state->getSelectedId());
    }

    // ── TreeWidget Lazy Loading ───────────────────────────────────────────

    public function test_lazy_loading_on_expand(): void
    {
        $loaded = false;
        $widget = new TreeWidget([
            new TreeNode(
                id: 'lazy',
                label: 'Lazy Parent',
                loadChildren: function () use (&$loaded): array {
                    $loaded = true;

                    return [
                        new TreeNode(id: 'dyn1', label: 'Dynamic 1'),
                        new TreeNode(id: 'dyn2', label: 'Dynamic 2'),
                    ];
                },
            ),
        ]);

        // Expand via right arrow
        $widget->handleInput("\x1b[C");

        $this->assertTrue($loaded, 'loadChildren callback should have been invoked');

        $state = $widget->getState();
        $this->assertTrue($state->isExpanded('lazy'));

        $visible = $state->getVisibleItems();
        $ids = array_map(fn(VisibleItem $item) => $item->node->id, $visible);

        $this->assertContains('dyn1', $ids);
        $this->assertContains('dyn2', $ids);
    }

    public function test_lazy_loading_empty_children_does_not_replace(): void
    {
        $loaded = false;
        $widget = new TreeWidget([
            new TreeNode(
                id: 'lazy',
                label: 'Lazy Empty',
                loadChildren: function () use (&$loaded): array {
                    $loaded = true;

                    return [];
                },
            ),
        ]);

        $widget->handleInput("\x1b[C"); // right → expand

        $this->assertTrue($loaded);

        // No children loaded, so the node should still have loadChildren
        $node = $widget->getSelectedNode();
        $this->assertNotNull($node->loadChildren);
    }

    // ── Scroll indicator ──────────────────────────────────────────────────

    public function test_scroll_indicator_shown_when_content_overflows(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 30; $i++) {
            $nodes[] = new TreeNode(id: "n{$i}", label: "Node {$i}");
        }
        $widget = new TreeWidget($nodes);

        $context = new RenderContext(80, 10);
        $result = $widget->render($context);

        // Last line should be a scroll indicator (contains parentheses and counts)
        $lastLine = $result[count($result) - 1];
        $this->assertMatchesRegularExpression('/\(\d+-\d+\/\d+\)/', $lastLine,
            'Last line should be a scroll indicator');
    }

    public function test_no_scroll_indicator_when_content_fits(): void
    {
        $widget = new TreeWidget([
            new TreeNode(id: 'a', label: 'A'),
            new TreeNode(id: 'b', label: 'B'),
        ]);

        $context = new RenderContext(80, 24);
        $result = $widget->render($context);

        // No scroll indicator expected
        foreach ($result as $line) {
            $this->assertDoesNotMatchRegularExpression('/^\(\d+-\d+\/\d+\)$/', $line);
        }
    }
}

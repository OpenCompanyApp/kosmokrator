<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Ansi\Handler;

use Kosmokrator\UI\Ansi\Handler\ListTracker;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListData;
use PHPUnit\Framework\TestCase;

class ListTrackerTest extends TestCase
{
    private ListTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new ListTracker;
    }

    // ── Initial state ───────────────────────────────────────────────

    public function testInitialStateDepthIsZero(): void
    {
        $this->assertSame(0, $this->tracker->depth());
    }

    public function testInitialStateIsNotInsideItem(): void
    {
        $this->assertFalse($this->tracker->isInsideItem());
    }

    public function testInitialStateDoesNotNeedBullet(): void
    {
        $this->assertFalse($this->tracker->needsBullet());
    }

    // ── reset() ─────────────────────────────────────────────────────

    public function testResetClearsAllState(): void
    {
        $listBlock = $this->createBulletListBlock();
        $this->tracker->handleListBlock($listBlock, true);
        $this->tracker->handleListItem(true);

        $this->tracker->reset();

        $this->assertSame(0, $this->tracker->depth());
        $this->assertFalse($this->tracker->isInsideItem());
        $this->assertFalse($this->tracker->needsBullet());
    }

    // ── handleListItem ──────────────────────────────────────────────

    public function testHandleListItemEnteringSetsInsideAndNeedsBullet(): void
    {
        $this->tracker->handleListItem(true);

        $this->assertTrue($this->tracker->isInsideItem());
        $this->assertTrue($this->tracker->needsBullet());
    }

    public function testHandleListItemLeavingClearsInsideAndNeedsBullet(): void
    {
        $this->tracker->handleListItem(true);
        $this->tracker->handleListItem(false);

        $this->assertFalse($this->tracker->isInsideItem());
        $this->assertFalse($this->tracker->needsBullet());
    }

    // ── handleListBlock ─────────────────────────────────────────────

    public function testHandleListBlockEnteringIncreasesDepth(): void
    {
        $listBlock = $this->createBulletListBlock();

        $result = $this->tracker->handleListBlock($listBlock, true);

        $this->assertSame(1, $this->tracker->depth());
        $this->assertNull($result);
    }

    public function testHandleListBlockLeavingDecreasesDepth(): void
    {
        $listBlock = $this->createBulletListBlock();
        $this->tracker->handleListBlock($listBlock, true);

        $this->tracker->handleListBlock($listBlock, false);

        $this->assertSame(0, $this->tracker->depth());
    }

    public function testHandleListBlockLeavingOutermostReturnsNewline(): void
    {
        $listBlock = $this->createBulletListBlock();
        $this->tracker->handleListBlock($listBlock, true);

        $result = $this->tracker->handleListBlock($listBlock, false);

        $this->assertSame("\n", $result);
    }

    public function testHandleListBlockLeavingInnerReturnsNull(): void
    {
        $outer = $this->createBulletListBlock();
        $inner = $this->createBulletListBlock();
        $this->tracker->handleListBlock($outer, true);
        $this->tracker->handleListBlock($inner, true);

        // Leaving inner list — still inside outer
        $result = $this->tracker->handleListBlock($inner, false);

        $this->assertNull($result);
        $this->assertSame(1, $this->tracker->depth());
    }

    // ── flushListItemParagraph ──────────────────────────────────────

    public function testFlushListItemParagraphWithEmptyBufferReturnsEmptyString(): void
    {
        $listBlock = $this->createBulletListBlock();
        $this->tracker->handleListBlock($listBlock, true);
        $this->tracker->handleListItem(true);

        $result = $this->tracker->flushListItemParagraph('', '', 80);

        $this->assertSame('', $result);
    }

    public function testFlushListItemParagraphProducesBulletOutput(): void
    {
        $listBlock = $this->createBulletListBlock();
        $this->tracker->handleListBlock($listBlock, true);
        $this->tracker->handleListItem(true);

        $result = $this->tracker->flushListItemParagraph('Hello world', '', 80);

        $this->assertNotSame('', $result);
        $this->assertStringContainsString('Hello world', $result);
        // Bullet character for first-level bullet list is • (U+2022)
        $this->assertStringContainsString("\u{2022}", $result);
    }

    public function testFlushListItemParagraphClearsNeedsBullet(): void
    {
        $listBlock = $this->createBulletListBlock();
        $this->tracker->handleListBlock($listBlock, true);
        $this->tracker->handleListItem(true);

        $this->assertTrue($this->tracker->needsBullet());

        $this->tracker->flushListItemParagraph('text', '', 80);

        $this->assertFalse($this->tracker->needsBullet());
    }

    public function testFlushListItemParagraphOrderedUsesCounter(): void
    {
        $listBlock = $this->createOrderedListBlock(start: 1);
        $this->tracker->handleListBlock($listBlock, true);
        $this->tracker->handleListItem(true);

        $result = $this->tracker->flushListItemParagraph('Item one', '', 80);

        $this->assertStringContainsString('1.', $result);

        // Second item — counter increments
        $this->tracker->handleListItem(false);
        $this->tracker->handleListItem(true);

        $result2 = $this->tracker->flushListItemParagraph('Item two', '', 80);

        $this->assertStringContainsString('2.', $result2);
    }

    public function testFlushListItemParagraphNestedUsesCircleBullet(): void
    {
        $outer = $this->createBulletListBlock();
        $inner = $this->createBulletListBlock();
        $this->tracker->handleListBlock($outer, true);
        $this->tracker->handleListBlock($inner, true);
        $this->tracker->handleListItem(true);

        $result = $this->tracker->flushListItemParagraph('Nested item', '', 80);

        // Nested bullet uses ◦ (U+25E6)
        $this->assertStringContainsString("\u{25E6}", $result);
    }

    public function testFlushListItemParagraphContinuationParagraph(): void
    {
        $listBlock = $this->createBulletListBlock();
        $this->tracker->handleListBlock($listBlock, true);
        $this->tracker->handleListItem(true);

        // First flush sets the bullet
        $this->tracker->flushListItemParagraph('First paragraph', '', 80);
        $this->assertFalse($this->tracker->needsBullet());

        // Continuation paragraph — no bullet
        $result = $this->tracker->flushListItemParagraph('Second paragraph', '', 80);

        $this->assertStringContainsString('Second paragraph', $result);
        $this->assertStringNotContainsString("\u{2022}", $result);
        $this->assertStringNotContainsString("\u{25E6}", $result);
    }

    // ── Helper methods ──────────────────────────────────────────────

    private function createBulletListBlock(): ListBlock
    {
        $listData = new ListData;
        $listData->type = ListBlock::TYPE_BULLET;
        $listData->start = 1;

        return new ListBlock($listData);
    }

    private function createOrderedListBlock(int $start = 1): ListBlock
    {
        $listData = new ListData;
        $listData->type = ListBlock::TYPE_ORDERED;
        $listData->start = $start;

        return new ListBlock($listData);
    }
}

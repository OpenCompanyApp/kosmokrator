<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Ansi\Handler;

use Kosmokrator\UI\Ansi\AnsiTableRenderer;
use Kosmokrator\UI\Ansi\Handler\TableCollector;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Inline\Text;
use PHPUnit\Framework\TestCase;

final class TableCollectorTest extends TestCase
{
    public function testInitialStateIsNotCollecting(): void
    {
        $renderer = $this->createStub(AnsiTableRenderer::class);
        $collector = new TableCollector($renderer);

        $this->assertFalse($collector->isCollecting());
    }

    public function testStartSetsCollectingToTrue(): void
    {
        $renderer = $this->createStub(AnsiTableRenderer::class);
        $collector = new TableCollector($renderer);

        $collector->start();
        $this->assertTrue($collector->isCollecting());
    }

    public function testFinishSetsCollectingToFalse(): void
    {
        $renderer = $this->createStub(AnsiTableRenderer::class);
        $renderer->method('render')->willReturn('RENDERED');
        $collector = new TableCollector($renderer);

        $collector->start();
        $collector->finish('');

        $this->assertFalse($collector->isCollecting());
    }

    public function testFinishCallsRendererWithHeadBodyAlignments(): void
    {
        $renderer = $this->createMock(AnsiTableRenderer::class);
        $renderer
            ->expects($this->once())
            ->method('render')
            ->with(
                [
                    'alignments' => [],
                    'head' => [],
                    'body' => [],
                ],
                '  ',
            )
            ->willReturn('RENDERED');

        $collector = new TableCollector($renderer);
        $collector->start();
        $result = $collector->finish('  ');

        $this->assertSame('RENDERED', $result);
    }

    public function testHandleNodeWithTableSectionSetsInHead(): void
    {
        $renderer = $this->createMock(AnsiTableRenderer::class);
        $collector = new TableCollector($renderer);
        $collector->start();

        $section = new TableSection(TableSection::TYPE_HEAD);

        // Entering head section
        $collector->handleNode($section, true);

        // Should route the next row to head
        $row = new TableRow();
        $collector->handleNode($row, true);
        $headerCell = new TableCell(TableCell::TYPE_HEADER, 'left');
        $collector->handleNode($headerCell, true);
        $collector->handleNode(new Text('Header'), true);
        $collector->handleNode($headerCell, false);
        $collector->handleNode($row, false);

        // Leaving head section
        $collector->handleNode($section, false);

        $renderer
            ->expects($this->once())
            ->method('render')
            ->with(
                [
                    'alignments' => ['left'],
                    'head' => [['Header']],
                    'body' => [],
                ],
                '',
            )
            ->willReturn('RENDERED');

        $collector->finish('');
    }

    public function testHandleNodeWithTableRowCollectsRows(): void
    {
        $renderer = $this->createMock(AnsiTableRenderer::class);
        $collector = new TableCollector($renderer);
        $collector->start();

        // Body section
        $section = new TableSection(TableSection::TYPE_BODY);
        $collector->handleNode($section, true);

        $row = new TableRow();
        $collector->handleNode($row, true);

        $cell = new TableCell('td');
        $collector->handleNode($cell, true);
        $collector->handleNode(new Text('Data'), true);
        $collector->handleNode($cell, false);

        $collector->handleNode($row, false);
        $collector->handleNode($section, false);

        $renderer
            ->expects($this->once())
            ->method('render')
            ->with(
                [
                    'alignments' => [],
                    'head' => [],
                    'body' => [['Data']],
                ],
                '',
            )
            ->willReturn('RENDERED');

        $collector->finish('');
    }

    public function testHandleNodeWithTableCellCapturesAlignmentForHeaderCells(): void
    {
        $renderer = $this->createMock(AnsiTableRenderer::class);
        $collector = new TableCollector($renderer);
        $collector->start();

        $section = new TableSection(TableSection::TYPE_HEAD);
        $collector->handleNode($section, true);

        $row = new TableRow();
        $collector->handleNode($row, true);

        $cell1 = new TableCell(TableCell::TYPE_HEADER, 'left');
        $collector->handleNode($cell1, true);
        $collector->handleNode(new Text('A'), true);
        $collector->handleNode($cell1, false);

        $cell2 = new TableCell(TableCell::TYPE_HEADER, 'right');
        $collector->handleNode($cell2, true);
        $collector->handleNode(new Text('B'), true);
        $collector->handleNode($cell2, false);

        $collector->handleNode($row, false);
        $collector->handleNode($section, false);

        $renderer
            ->expects($this->once())
            ->method('render')
            ->with(
                [
                    'alignments' => ['left', 'right'],
                    'head' => [['A', 'B']],
                    'body' => [],
                ],
                '',
            )
            ->willReturn('RENDERED');

        $collector->finish('');
    }

    public function testHandleNodeWithTextAppendsToCellBufferWhenCollectingCell(): void
    {
        $renderer = $this->createMock(AnsiTableRenderer::class);
        $collector = new TableCollector($renderer);
        $collector->start();

        $section = new TableSection(TableSection::TYPE_BODY);
        $collector->handleNode($section, true);

        $row = new TableRow();
        $collector->handleNode($row, true);

        $cell = new TableCell('td');
        $collector->handleNode($cell, true);
        $collector->handleNode(new Text('Hello '), true);
        $collector->handleNode(new Text('World'), true);
        $collector->handleNode($cell, false);

        $collector->handleNode($row, false);
        $collector->handleNode($section, false);

        $renderer
            ->expects($this->once())
            ->method('render')
            ->with(
                [
                    'alignments' => [],
                    'head' => [],
                    'body' => [['Hello World']],
                ],
                '',
            )
            ->willReturn('RENDERED');

        $collector->finish('');
    }

    public function testFullCycleFromStartToFinish(): void
    {
        $renderer = $this->createMock(AnsiTableRenderer::class);
        $collector = new TableCollector($renderer);
        $collector->start();
        $this->assertTrue($collector->isCollecting());

        // --- Head section ---
        $headSection = new TableSection(TableSection::TYPE_HEAD);
        $collector->handleNode($headSection, true);

        $headRow = new TableRow();
        $collector->handleNode($headRow, true);

        $hCell1 = new TableCell(TableCell::TYPE_HEADER, 'left');
        $collector->handleNode($hCell1, true);
        $collector->handleNode(new Text('Name'), true);
        $collector->handleNode($hCell1, false);

        $hCell2 = new TableCell(TableCell::TYPE_HEADER, 'right');
        $collector->handleNode($hCell2, true);
        $collector->handleNode(new Text('Value'), true);
        $collector->handleNode($hCell2, false);

        $collector->handleNode($headRow, false);
        $collector->handleNode($headSection, false);

        // --- Body section ---
        $bodySection = new TableSection(TableSection::TYPE_BODY);
        $collector->handleNode($bodySection, true);

        $bodyRow1 = new TableRow();
        $collector->handleNode($bodyRow1, true);

        $bCell1 = new TableCell('td');
        $collector->handleNode($bCell1, true);
        $collector->handleNode(new Text('foo'), true);
        $collector->handleNode($bCell1, false);

        $bCell2 = new TableCell('td');
        $collector->handleNode($bCell2, true);
        $collector->handleNode(new Text('bar'), true);
        $collector->handleNode($bCell2, false);

        $collector->handleNode($bodyRow1, false);
        $collector->handleNode($bodySection, false);

        $renderer
            ->expects($this->once())
            ->method('render')
            ->with(
                [
                    'alignments' => ['left', 'right'],
                    'head' => [['Name', 'Value']],
                    'body' => [['foo', 'bar']],
                ],
                '  ',
            )
            ->willReturn('RENDERED');

        $result = $collector->finish('  ');

        $this->assertSame('RENDERED', $result);
        $this->assertFalse($collector->isCollecting());
    }

    public function testStartResetsStateFromPreviousCollection(): void
    {
        $renderer = $this->createMock(AnsiTableRenderer::class);
        $collector = new TableCollector($renderer);

        // First table cycle
        $collector->start();

        $headSection = new TableSection(TableSection::TYPE_HEAD);
        $collector->handleNode($headSection, true);

        $row = new TableRow();
        $collector->handleNode($row, true);
        $cell = new TableCell(TableCell::TYPE_HEADER, 'center');
        $collector->handleNode($cell, true);
        $collector->handleNode(new Text('Old'), true);
        $collector->handleNode($cell, false);
        $collector->handleNode($row, false);
        $collector->handleNode($headSection, false);

        $renderer->method('render')->willReturn('RENDERED');
        $collector->finish('');

        // Second table cycle — state should be fully reset
        $collector->start();

        $renderer
            ->expects($this->once())
            ->method('render')
            ->with(
                [
                    'alignments' => [],
                    'head' => [],
                    'body' => [],
                ],
                '',
            )
            ->willReturn('RENDERED');

        $collector->finish('');
    }
}

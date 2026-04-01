<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi\Handler;

use Kosmokrator\UI\Ansi\AnsiTableRenderer;
use Kosmokrator\UI\Theme;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;

/**
 * Collects table rows and cells during markdown AST traversal,
 * then renders the complete table using AnsiTableRenderer.
 */
final class TableCollector
{
    private bool $collecting = false;

    /** @var list<string|null> */
    private array $alignments = [];

    /** @var list<list<string>> */
    private array $head = [];

    /** @var list<list<string>> */
    private array $body = [];

    private bool $inHead = false;

    /** @var list<string> */
    private array $currentRow = [];

    private string $cellBuffer = '';

    private bool $collectingCell = false;

    public function __construct(
        private readonly AnsiTableRenderer $renderer,
    ) {}

    /**
     * Whether the collector is currently accumulating table data.
     */
    public function isCollecting(): bool
    {
        return $this->collecting;
    }

    /**
     * Begin collecting a new table, resetting all internal state.
     */
    public function start(): void
    {
        $this->collecting = true;
        $this->alignments = [];
        $this->head = [];
        $this->body = [];
        $this->inHead = false;
        $this->currentRow = [];
        $this->cellBuffer = '';
        $this->collectingCell = false;
    }

    /**
     * Finish collecting and render the completed table.
     *
     * @param  string  $indent  Prefix for each rendered line
     * @return string The rendered table output
     */
    public function finish(string $indent): string
    {
        $this->collecting = false;

        return $this->renderer->render([
            'alignments' => $this->alignments,
            'head' => $this->head,
            'body' => $this->body,
        ], $indent);
    }

    /**
     * Handle a node encountered while collecting table data.
     *
     * @param  Node  $node  The AST node to process
     * @param  bool  $entering  Whether we are entering or leaving the node
     */
    public function handleNode(Node $node, bool $entering): void
    {
        match (true) {
            $node instanceof TableSection => $this->handleSection($node, $entering),
            $node instanceof TableRow => $this->handleRow($entering),
            $node instanceof TableCell => $this->handleCell($node, $entering),
            $node instanceof Text => $entering && $this->collectingCell ? $this->cellBuffer .= $node->getLiteral() : null,
            $node instanceof Code => $entering && $this->collectingCell ? $this->cellBuffer .= Theme::code().'`'.$node->getLiteral().'`'.Theme::reset() : null,
            $node instanceof Strong => $this->collectingCell ? ($entering ? $this->cellBuffer .= Theme::bold() : $this->cellBuffer .= Theme::reset()) : null,
            $node instanceof Emphasis => $this->collectingCell ? ($entering ? $this->cellBuffer .= "\033[3m" : $this->cellBuffer .= "\033[23m") : null,
            default => null,
        };
    }

    private function handleSection(TableSection $node, bool $entering): void
    {
        $this->inHead = $entering && $node->isHead();
    }

    private function handleRow(bool $entering): void
    {
        if ($entering) {
            $this->currentRow = [];
        } else {
            if ($this->inHead) {
                $this->head[] = $this->currentRow;
            } else {
                $this->body[] = $this->currentRow;
            }
        }
    }

    private function handleCell(TableCell $node, bool $entering): void
    {
        if ($entering) {
            $this->collectingCell = true;
            $this->cellBuffer = '';

            if ($node->getType() === TableCell::TYPE_HEADER) {
                $this->alignments[] = $node->getAlign();
            }
        } else {
            $this->collectingCell = false;
            $this->currentRow[] = $this->cellBuffer;
        }
    }
}

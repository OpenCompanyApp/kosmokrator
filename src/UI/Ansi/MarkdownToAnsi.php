<?php

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use Tempest\Highlight\Highlighter;

class MarkdownToAnsi
{
    private const MARGIN = '  ';

    private MarkdownParser $parser;
    private Highlighter $highlighter;
    private AnsiTableRenderer $tableRenderer;

    private string $output = '';
    private string $inlineBuffer = '';
    private int $blockquoteDepth = 0;
    private int $termWidth;

    /** @var list<array{type: string, counter: int, start: int}> */
    private array $listStack = [];

    // Table collection state
    private bool $collectingTable = false;
    /** @var list<string|null> */
    private array $tableAlignments = [];
    /** @var list<list<string>> */
    private array $tableHead = [];
    /** @var list<list<string>> */
    private array $tableBody = [];
    private bool $tableInHead = false;
    /** @var list<string> */
    private array $tableCurrentRow = [];
    private string $tableCellBuffer = '';
    private bool $collectingCell = false;

    // Link collection state
    private bool $collectingLink = false;
    private string $linkUrl = '';
    private string $linkBuffer = '';

    private bool $insideListItem = false;
    private bool $listItemNeedsBullet = false;

    public function __construct()
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $this->parser = new MarkdownParser($environment);
        $this->highlighter = new Highlighter(new KosmokratorTerminalTheme());
        $this->tableRenderer = new AnsiTableRenderer();
        $this->termWidth = self::detectTermWidth();
    }

    public function render(string $markdown): string
    {
        $this->output = '';
        $this->inlineBuffer = '';
        $this->blockquoteDepth = 0;
        $this->listStack = [];
        $this->collectingTable = false;
        $this->collectingLink = false;
        $this->insideListItem = false;

        $document = $this->parser->parse($markdown);
        $walker = $document->walker();

        while ($event = $walker->next()) {
            $node = $event->getNode();
            $entering = $event->isEntering();

            if ($node instanceof Document) {
                continue;
            }

            // When collecting table data, handle table nodes specially
            if ($this->collectingTable && !($node instanceof Table && !$entering)) {
                $this->handleTableNode($node, $entering);
                continue;
            }

            match (true) {
                $node instanceof Heading => $this->handleHeading($node, $entering),
                $node instanceof Paragraph => $this->handleParagraph($entering),
                $node instanceof FencedCode => $entering ? $this->handleFencedCode($node) : null,
                $node instanceof IndentedCode => $entering ? $this->handleIndentedCode($node) : null,
                $node instanceof BlockQuote => $this->handleBlockQuote($entering),
                $node instanceof ListBlock => $this->handleListBlock($node, $entering),
                $node instanceof ListItem => $this->handleListItem($entering),
                $node instanceof ThematicBreak => $entering ? $this->handleThematicBreak() : null,
                $node instanceof Table => $this->handleTable($entering),
                $node instanceof Strong => $this->handleStrong($entering),
                $node instanceof Emphasis => $this->handleEmphasis($entering),
                $node instanceof Code => $entering ? $this->handleInlineCode($node) : null,
                $node instanceof Link => $this->handleLink($node, $entering),
                $node instanceof Image => $this->handleImage($node, $entering),
                $node instanceof Strikethrough => $this->handleStrikethrough($entering),
                $node instanceof TaskListItemMarker => $entering ? $this->handleTaskListMarker($node) : null,
                $node instanceof Text => $entering ? $this->handleText($node) : null,
                $node instanceof Newline => $entering ? $this->handleNewline($node) : null,
                default => null,
            };
        }

        return $this->output;
    }

    private function handleHeading(Heading $node, bool $entering): void
    {
        if ($entering) {
            $this->inlineBuffer = '';
        } else {
            $r = Theme::reset();
            $level = $node->getLevel();
            $prefix = str_repeat('#', $level);
            $indent = $this->indent();

            if ($level <= 2) {
                $this->output .= $indent . Theme::white() . Theme::bold() . $prefix . ' ' . $this->inlineBuffer . $r . "\n";
            } else {
                $this->output .= $indent . Theme::info() . $prefix . ' ' . $this->inlineBuffer . $r . "\n";
            }

            $this->inlineBuffer = '';
        }
    }

    private function handleParagraph(bool $entering): void
    {
        if ($entering) {
            $this->inlineBuffer = '';
        } else {
            if ($this->insideListItem) {
                $this->flushListItemParagraph();
            } else {
                $this->flushParagraph();
            }
        }
    }

    private function flushParagraph(): void
    {
        if ($this->inlineBuffer === '') {
            return;
        }

        $indent = $this->indent();
        $indentWidth = AnsiTableRenderer::visibleWidth($indent);
        $availableWidth = max(40, $this->termWidth - $indentWidth - 2);
        $lines = $this->wrapAnsiText($this->inlineBuffer, $availableWidth);

        foreach ($lines as $line) {
            $this->output .= $indent . $line . Theme::reset() . "\n";
        }
        $this->output .= "\n";
        $this->inlineBuffer = '';
    }

    private function handleFencedCode(FencedCode $node): void
    {
        $infoWords = $node->getInfoWords();
        $language = $infoWords[0] ?? '';
        $code = rtrim($node->getLiteral(), "\n");

        $this->renderCodeBlock($code, $language);
    }

    private function handleIndentedCode(IndentedCode $node): void
    {
        $code = rtrim($node->getLiteral(), "\n");
        $this->renderCodeBlock($code, '');
    }

    private function renderCodeBlock(string $code, string $language): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $indent = $this->indent();

        // Syntax highlight
        if ($language !== '') {
            try {
                $highlighted = $this->highlighter->withGutter()->parse($code, $language);
            } catch (\Throwable) {
                $highlighted = $this->highlighter->withGutter()->parse($code, 'text');
            }
        } else {
            $highlighted = $this->highlighter->withGutter()->parse($code, 'text');
        }

        $lines = explode("\n", $highlighted);

        // Top border with language label
        $label = $language !== '' ? $dim . '── ' . $language . ' ' : $dim;
        $this->output .= $indent . $label . str_repeat('─', 20) . $r . "\n";

        // Code lines
        foreach ($lines as $line) {
            $this->output .= $indent . $dim . '│' . $r . ' ' . $line . $r . "\n";
        }

        // Bottom border
        $this->output .= $indent . $dim . '──' . str_repeat('─', 20) . $r . "\n\n";
    }

    private function handleBlockQuote(bool $entering): void
    {
        if ($entering) {
            $this->blockquoteDepth++;
        } else {
            $this->blockquoteDepth--;
        }
    }

    private function handleListBlock(ListBlock $node, bool $entering): void
    {
        if ($entering) {
            $this->listStack[] = [
                'type' => $node->getListData()->type,
                'counter' => $node->getListData()->start ?? 1,
                'start' => $node->getListData()->start ?? 1,
            ];
        } else {
            array_pop($this->listStack);
            if ($this->listStack === []) {
                $this->output .= "\n";
            }
        }
    }

    private function handleListItem(bool $entering): void
    {
        if ($entering) {
            $this->insideListItem = true;
            $this->listItemNeedsBullet = true;
            $this->inlineBuffer = '';
        } else {
            $this->insideListItem = false;
            $this->listItemNeedsBullet = false;
        }
    }

    private function flushListItemParagraph(): void
    {
        if ($this->inlineBuffer === '') {
            return;
        }

        $indent = $this->indent();
        $listCtx = end($this->listStack);
        $depth = count($this->listStack);
        $bulletIndent = str_repeat('  ', $depth - 1);

        if ($this->listItemNeedsBullet) {
            if ($listCtx !== false) {
                if ($listCtx['type'] === ListBlock::TYPE_ORDERED) {
                    $bullet = $listCtx['counter'] . '. ';
                    $this->listStack[array_key_last($this->listStack)]['counter']++;
                } else {
                    $bullet = $depth > 1 ? '◦ ' : '• ';
                }
            } else {
                $bullet = '• ';
            }

            $continuationIndent = $indent . $bulletIndent . str_repeat(' ', mb_strlen($bullet));
            $contWidth = AnsiTableRenderer::visibleWidth($continuationIndent);
            $availableWidth = max(40, $this->termWidth - $contWidth - 2);
            $lines = $this->wrapAnsiText($this->inlineBuffer, $availableWidth);

            $this->output .= $indent . $bulletIndent . Theme::dim() . $bullet . Theme::reset() . array_shift($lines) . Theme::reset() . "\n";
            foreach ($lines as $line) {
                $this->output .= $continuationIndent . $line . Theme::reset() . "\n";
            }

            $this->listItemNeedsBullet = false;
        } else {
            // Continuation paragraph in same list item (loose list)
            $continuationIndent = $indent . $bulletIndent . '  ';
            $contWidth = AnsiTableRenderer::visibleWidth($continuationIndent);
            $availableWidth = max(40, $this->termWidth - $contWidth - 2);
            $lines = $this->wrapAnsiText($this->inlineBuffer, $availableWidth);

            foreach ($lines as $line) {
                $this->output .= $continuationIndent . $line . Theme::reset() . "\n";
            }
        }

        $this->inlineBuffer = '';
    }

    private function handleThematicBreak(): void
    {
        $indent = $this->indent();
        $indentWidth = AnsiTableRenderer::visibleWidth($indent);
        $width = max(20, $this->termWidth - $indentWidth - 4);
        $this->output .= "\n" . $indent . Theme::dim() . str_repeat('━', $width) . Theme::reset() . "\n\n";
    }

    // ── Table handling ──────────────────────────────────────────────────

    private function handleTable(bool $entering): void
    {
        if ($entering) {
            $this->collectingTable = true;
            $this->tableAlignments = [];
            $this->tableHead = [];
            $this->tableBody = [];
            $this->tableInHead = false;
            $this->tableCurrentRow = [];
        } else {
            $this->collectingTable = false;

            $rendered = $this->tableRenderer->render([
                'alignments' => $this->tableAlignments,
                'head' => $this->tableHead,
                'body' => $this->tableBody,
            ], $this->indent());

            $this->output .= $rendered . "\n";
        }
    }

    private function handleTableNode(mixed $node, bool $entering): void
    {
        match (true) {
            $node instanceof TableSection => $this->handleTableSection($node, $entering),
            $node instanceof TableRow => $this->handleTableRow($entering),
            $node instanceof TableCell => $this->handleTableCell($node, $entering),
            $node instanceof Text => $entering && $this->collectingCell ? $this->tableCellBuffer .= $node->getLiteral() : null,
            $node instanceof Code => $entering && $this->collectingCell ? $this->tableCellBuffer .= Theme::code() . '`' . $node->getLiteral() . '`' . Theme::reset() : null,
            $node instanceof Strong => $this->collectingCell ? ($entering ? $this->tableCellBuffer .= Theme::bold() : $this->tableCellBuffer .= Theme::reset()) : null,
            $node instanceof Emphasis => $this->collectingCell ? ($entering ? $this->tableCellBuffer .= "\033[3m" : $this->tableCellBuffer .= "\033[23m") : null,
            default => null,
        };
    }

    private function handleTableSection(TableSection $node, bool $entering): void
    {
        $this->tableInHead = $entering && $node->isHead();
    }

    private function handleTableRow(bool $entering): void
    {
        if ($entering) {
            $this->tableCurrentRow = [];
        } else {
            if ($this->tableInHead) {
                $this->tableHead[] = $this->tableCurrentRow;
            } else {
                $this->tableBody[] = $this->tableCurrentRow;
            }
        }
    }

    private function handleTableCell(TableCell $node, bool $entering): void
    {
        if ($entering) {
            $this->collectingCell = true;
            $this->tableCellBuffer = '';

            // Collect alignment from header cells
            if ($node->getType() === TableCell::TYPE_HEADER) {
                $this->tableAlignments[] = $node->getAlign();
            }
        } else {
            $this->collectingCell = false;
            $this->tableCurrentRow[] = $this->tableCellBuffer;
        }
    }

    // ── Inline handling ─────────────────────────────────────────────────

    private function handleStrong(bool $entering): void
    {
        if ($entering) {
            $this->appendInline(Theme::bold() . Theme::white());
        } else {
            $this->appendInline(Theme::reset());
        }
    }

    private function handleEmphasis(bool $entering): void
    {
        if ($entering) {
            $this->appendInline("\033[3m");
        } else {
            $this->appendInline("\033[23m");
        }
    }

    private function handleInlineCode(Code $node): void
    {
        $this->appendInline(Theme::code() . '`' . $node->getLiteral() . '`' . Theme::reset());
    }

    private function handleLink(Link $node, bool $entering): void
    {
        if ($entering) {
            $this->collectingLink = true;
            $this->linkUrl = $node->getUrl();
            $this->linkBuffer = '';
        } else {
            $this->collectingLink = false;
            $this->appendInline(Theme::link() . $this->linkBuffer . Theme::reset() . Theme::dim() . ' (' . $this->linkUrl . ')' . Theme::reset());
        }
    }

    private function handleImage(Image $node, bool $entering): void
    {
        if ($entering) {
            $this->collectingLink = true;
            $this->linkUrl = $node->getUrl();
            $this->linkBuffer = '';
        } else {
            $this->collectingLink = false;
            $this->appendInline(Theme::dim() . '[' . Theme::link() . $this->linkBuffer . Theme::dim() . '](' . $this->linkUrl . ')' . Theme::reset());
        }
    }

    private function handleStrikethrough(bool $entering): void
    {
        if ($entering) {
            $this->appendInline("\033[9m");
        } else {
            $this->appendInline("\033[29m");
        }
    }

    private function handleTaskListMarker(TaskListItemMarker $node): void
    {
        if ($node->isChecked()) {
            $this->appendInline(Theme::success() . '☑ ' . Theme::reset());
        } else {
            $this->appendInline(Theme::dim() . '☐ ' . Theme::reset());
        }
    }

    private function handleText(Text $node): void
    {
        $this->appendInline($node->getLiteral());
    }

    private function handleNewline(Newline $node): void
    {
        if ($node->getType() === Newline::HARDBREAK) {
            $this->appendInline("\n");
        } else {
            $this->appendInline(' ');
        }
    }

    private function appendInline(string $text): void
    {
        if ($this->collectingLink) {
            $this->linkBuffer .= $text;
        } else {
            $this->inlineBuffer .= $text;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function indent(): string
    {
        $indent = self::MARGIN;
        for ($i = 0; $i < $this->blockquoteDepth; $i++) {
            $indent .= Theme::info() . '│' . Theme::reset() . ' ';
        }

        return $indent;
    }

    /**
     * Word-wrap text that may contain ANSI escape codes.
     * Splits on word boundaries using visible width, preserving escape sequences.
     *
     * @return list<string>
     */
    private function wrapAnsiText(string $text, int $width): array
    {
        // Split into words (preserving ANSI codes attached to words)
        $words = preg_split('/(?<=\s)(?=\S)/', $text);
        $lines = [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = AnsiTableRenderer::visibleWidth($word);

            if ($currentWidth > 0 && $currentWidth + $wordWidth > $width) {
                $lines[] = rtrim($currentLine);
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
                $currentLine .= $word;
                $currentWidth += $wordWidth;
            }
        }

        if ($currentLine !== '') {
            $lines[] = rtrim($currentLine);
        }

        return $lines ?: [''];
    }

    private static function detectTermWidth(): int
    {
        // Try PHP's built-in first (PHP 8.3+)
        if (function_exists('posix_get_terminal_size')) {
            $size = posix_get_terminal_size(STDOUT);
            if ($size !== false) {
                return $size['columns'];
            }
        }

        // Try COLUMNS env var (set by most shells)
        $cols = getenv('COLUMNS');
        if ($cols !== false && (int) $cols > 0) {
            return (int) $cols;
        }

        // Try stty as a quick fallback
        $result = @exec('stty size 2>/dev/null');
        if ($result !== '' && preg_match('/\d+ (\d+)/', $result, $m)) {
            return (int) $m[1];
        }

        return 80;
    }
}

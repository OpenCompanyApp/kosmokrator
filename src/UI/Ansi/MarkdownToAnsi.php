<?php

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Handler\ListTracker;
use Kosmokrator\UI\Ansi\Handler\TableCollector;
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
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use Tempest\Highlight\Highlighter;

/**
 * Converts CommonMark/GFM markdown to ANSI-escaped terminal output.
 *
 * Used by the ANSI renderer in the dual TUI/ANSI rendering layer. Parses markdown
 * via league/commonmark, then walks the AST to emit themed ANSI escape sequences.
 */
class MarkdownToAnsi
{
    private const MARGIN = '  ';

    private MarkdownParser $parser;

    private Highlighter $highlighter;

    private TableCollector $tableCollector;

    private ListTracker $listTracker;

    private string $output = '';

    private string $inlineBuffer = '';

    private int $blockquoteDepth = 0;

    private int $termWidth;

    // Link collection state
    private bool $collectingLink = false;

    private string $linkUrl = '';

    private string $linkBuffer = '';

    public function __construct()
    {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $this->parser = new MarkdownParser($environment);
        $this->highlighter = new Highlighter(new KosmokratorTerminalTheme);
        $this->tableCollector = new TableCollector(new AnsiTableRenderer);
        $this->listTracker = new ListTracker;
        $this->termWidth = self::detectTermWidth();
    }

    /**
     * Renders a markdown string as ANSI-escaped terminal output.
     * Resets internal state before parsing.
     *
     * @param  string  $markdown  CommonMark/GFM markdown source
     * @return string ANSI-escaped terminal output with newlines
     */
    public function render(string $markdown): string
    {
        $this->output = '';
        $this->inlineBuffer = '';
        $this->blockquoteDepth = 0;
        $this->listTracker->reset();
        $this->collectingLink = false;

        $document = $this->parser->parse($markdown);
        $walker = $document->walker();

        while ($event = $walker->next()) {
            $node = $event->getNode();
            $entering = $event->isEntering();

            if ($node instanceof Document) {
                continue;
            }

            // When collecting table data, handle table nodes specially
            if ($this->tableCollector->isCollecting() && ! ($node instanceof Table && ! $entering)) {
                $this->tableCollector->handleNode($node, $entering);

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

    /** Renders a heading with # prefix, using bold white for levels 1-2 and info color for 3+. */
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
                $this->output .= $indent.Theme::white().Theme::bold().$prefix.' '.$this->inlineBuffer.$r."\n";
            } else {
                $this->output .= $indent.Theme::info().$prefix.' '.$this->inlineBuffer.$r."\n";
            }

            $this->inlineBuffer = '';
        }
    }

    /** Handles paragraph open/close; flushes as list item or standalone paragraph. */
    private function handleParagraph(bool $entering): void
    {
        if ($entering) {
            $this->inlineBuffer = '';
        } else {
            if ($this->listTracker->isInsideItem()) {
                $this->output .= $this->listTracker->flushListItemParagraph(
                    $this->inlineBuffer,
                    $this->indent(),
                    $this->termWidth,
                );
                $this->inlineBuffer = '';
            } else {
                $this->flushParagraph();
            }
        }
    }

    /** Flushes the inline buffer as a word-wrapped paragraph with indent. */
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
            $this->output .= $indent.$line.Theme::reset()."\n";
        }
        $this->output .= "\n";
        $this->inlineBuffer = '';
    }

    /** Renders a fenced code block with syntax highlighting, language label, and line gutter. */
    private function handleFencedCode(FencedCode $node): void
    {
        $infoWords = $node->getInfoWords();
        $language = $infoWords[0] ?? '';
        $code = rtrim($node->getLiteral(), "\n");

        $this->renderCodeBlock($code, $language);
    }

    /** Renders an indented code block (4-space indent) as an unlabelled code block. */
    private function handleIndentedCode(IndentedCode $node): void
    {
        $code = rtrim($node->getLiteral(), "\n");
        $this->renderCodeBlock($code, '');
    }

    /** Renders a code block with syntax highlighting, border, and optional line wrapping. */
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
        $label = $language !== '' ? $dim.'── '.$language.' ' : $dim;
        $this->output .= $indent.$label.str_repeat('─', 20).$r."\n";

        // Code lines — wrap long lines to prevent terminal clipping
        $indentWidth = AnsiTableRenderer::visibleWidth($indent);
        $prefixWidth = 2; // "│ "
        $available = max(40, $this->termWidth - $indentWidth - $prefixWidth);

        foreach ($lines as $line) {
            if (AnsiTableRenderer::visibleWidth($line) > $available) {
                $wrapped = $this->wrapCodeLine($line, $available);
                foreach ($wrapped as $wl) {
                    $this->output .= $indent.$dim.'│'.$r.' '.$wl.$r."\n";
                }
            } else {
                $this->output .= $indent.$dim.'│'.$r.' '.$line.$r."\n";
            }
        }

        // Bottom border
        $this->output .= $indent.$dim.'──'.str_repeat('─', 20).$r."\n\n";
    }

    /** Tracks blockquote nesting depth for indent calculation. */
    private function handleBlockQuote(bool $entering): void
    {
        if ($entering) {
            $this->blockquoteDepth++;
        } else {
            $this->blockquoteDepth--;
        }
    }

    /** Delegates list block enter/exit to ListTracker for bullet/number management. */
    private function handleListBlock(ListBlock $node, bool $entering): void
    {
        $trailing = $this->listTracker->handleListBlock($node, $entering);
        if ($trailing !== null) {
            $this->output .= $trailing;
        }
    }

    /** Delegates list item enter/exit to ListTracker and resets inline buffer. */
    private function handleListItem(bool $entering): void
    {
        $this->listTracker->handleListItem($entering);
        if ($entering) {
            $this->inlineBuffer = '';
        }
    }

    /** Renders a horizontal rule (━) scaled to terminal width. */
    private function handleThematicBreak(): void
    {
        $indent = $this->indent();
        $indentWidth = AnsiTableRenderer::visibleWidth($indent);
        $width = max(20, $this->termWidth - $indentWidth - 4);
        $this->output .= "\n".$indent.Theme::dim().str_repeat('━', $width).Theme::reset()."\n\n";
    }

    // ── Table handling ──────────────────────────────────────────────────

    /** Starts/stops table collection via TableCollector; renders on exit. */
    private function handleTable(bool $entering): void
    {
        if ($entering) {
            $this->tableCollector->start();
        } else {
            $this->output .= $this->tableCollector->finish($this->indent())."\n";
        }
    }

    // ── Inline handling ─────────────────────────────────────────────────

    /** Wraps inline text in bold white on enter, resets on exit. */
    private function handleStrong(bool $entering): void
    {
        if ($entering) {
            $this->appendInline(Theme::bold().Theme::white());
        } else {
            $this->appendInline(Theme::reset());
        }
    }

    /** Toggles italic via Theme::italic() on enter, resets on exit. */
    private function handleEmphasis(bool $entering): void
    {
        if ($entering) {
            $this->appendInline(Theme::italic());
        } else {
            $this->appendInline("\033[23m");
        }
    }

    /** Renders inline code with theme color and backtick delimiters. */
    private function handleInlineCode(Code $node): void
    {
        $this->appendInline(Theme::code().'`'.$node->getLiteral().'`'.Theme::reset());
    }

    /** Collects link text into a separate buffer, then renders as colored text with URL in parentheses. */
    private function handleLink(Link $node, bool $entering): void
    {
        if ($entering) {
            $this->collectingLink = true;
            $this->linkUrl = $node->getUrl();
            $this->linkBuffer = '';
        } else {
            $this->collectingLink = false;
            $this->appendInline(Theme::link().$this->linkBuffer.Theme::reset().Theme::dim().' ('.$this->linkUrl.')'.Theme::reset());
        }
    }

    /** Renders images as dimmed [alt](url) since terminals cannot display images inline. */
    private function handleImage(Image $node, bool $entering): void
    {
        if ($entering) {
            $this->collectingLink = true;
            $this->linkUrl = $node->getUrl();
            $this->linkBuffer = '';
        } else {
            $this->collectingLink = false;
            $this->appendInline(Theme::dim().'['.Theme::link().$this->linkBuffer.Theme::dim().']('.$this->linkUrl.')'.Theme::reset());
        }
    }

    /** Toggles strikethrough via Theme::strikethrough() on enter, resets on exit. */
    private function handleStrikethrough(bool $entering): void
    {
        if ($entering) {
            $this->appendInline(Theme::strikethrough());
        } else {
            $this->appendInline("\033[29m");
        }
    }

    /** Renders a task list checkbox as ☑ (checked) or ☐ (unchecked). */
    private function handleTaskListMarker(TaskListItemMarker $node): void
    {
        if ($node->isChecked()) {
            $this->appendInline(Theme::success().'☑ '.Theme::reset());
        } else {
            $this->appendInline(Theme::dim().'☐ '.Theme::reset());
        }
    }

    /** Appends raw text to the inline buffer. */
    private function handleText(Text $node): void
    {
        $this->appendInline($node->getLiteral());
    }

    /** Handles hard breaks as newline, soft breaks as space. */
    private function handleNewline(Newline $node): void
    {
        if ($node->getType() === Newline::HARDBREAK) {
            $this->appendInline("\n");
        } else {
            $this->appendInline(' ');
        }
    }

    /** Routes inline text to the link buffer (when inside a link) or the main inline buffer. */
    private function appendInline(string $text): void
    {
        if ($this->collectingLink) {
            $this->linkBuffer .= $text;
        } else {
            $this->inlineBuffer .= $text;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Returns the left margin + blockquote depth indicators (│) for the current nesting level. */
    private function indent(): string
    {
        $indent = self::MARGIN;
        for ($i = 0; $i < $this->blockquoteDepth; $i++) {
            $indent .= Theme::info().'│'.Theme::reset().' ';
        }

        return $indent;
    }

    /**
     * Word-wrap text that may contain ANSI escape codes.
     * Splits on word boundaries using visible width, preserving escape sequences.
     *
     * @return list<string>
     */
    public static function wrapAnsiText(string $text, int $width): array
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

    /**
     * Wrap a single code line at a character boundary, preserving ANSI codes.
     *
     * @return list<string>
     */
    private function wrapCodeLine(string $line, int $width): array
    {
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $line);
        $visibleLen = mb_strwidth($stripped);

        if ($visibleLen <= $width) {
            return [$line];
        }

        // Walk through the string, tracking visible width and collecting ANSI codes
        $lines = [];
        $current = '';
        $currentWidth = 0;
        $i = 0;
        $len = strlen($line);

        while ($i < $len) {
            // Check for ANSI escape sequence
            if ($line[$i] === "\033" && $i + 1 < $len && $line[$i + 1] === '[') {
                $end = strpos($line, 'm', $i);
                if ($end !== false) {
                    $current .= substr($line, $i, $end - $i + 1);
                    $i = $end + 1;

                    continue;
                }
            }

            $char = mb_substr(substr($line, $i), 0, 1);
            $charWidth = mb_strwidth($char);
            $charBytes = strlen($char);

            if ($currentWidth + $charWidth > $width) {
                $lines[] = $current;
                $current = $char;
                $currentWidth = $charWidth;
            } else {
                $current .= $char;
                $currentWidth += $charWidth;
            }

            $i += $charBytes;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    /** Detects terminal width via posix_get_terminal_size, COLUMNS env, or stty. Defaults to 80. */
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

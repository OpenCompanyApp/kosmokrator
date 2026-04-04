<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Quill and parchment animation for the :docs command.
 *
 * Three phases: pages flutter in, quill writes across front page,
 * title fades in with subtitle. ~3 seconds total.
 */
class AnsiDocs implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const PAGE_CORNERS = ['┌', '┐', '└', '┘'];

    private const PAGE_EDGES = ['─', '│'];

    private const TEXT_CHARS = ['─', '·', '∙', '–'];

    /**
     * Run the full docs writing animation (pages -> writing -> title).
     */
    public function animate(): void
    {
        $this->termWidth = TerminalSize::cols();
        $this->termHeight = TerminalSize::lines();
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(function () {
            echo Theme::showCursor();
        });

        $this->installSignalHandler();

        try {
            $this->phasePages();
            $this->phaseWriting();
            $this->phaseTitle();

            usleep(400000);
            echo Theme::clearScreen();
            echo Theme::showCursor();
        } catch (IntroSkippedException) {
            // Animation skipped by user
        } finally {
            $this->restoreSignalHandler();
            TerminalSize::reset();
        }
    }

    /**
     * Phase 1 — Pages (~0.8s).
     *
     * 3-4 page outlines flutter in from the right side and settle in a
     * slight cascade. Each page is a box-drawing rectangle in warm
     * white/cream tones.
     */
    private function phasePages(): void
    {
        $r = Theme::reset();

        // Page dimensions
        $pageWidth = min(32, $this->termWidth - 16);
        $pageHeight = min(14, $this->termHeight - 10);

        // Page offsets for cascade (back to front)
        $pages = [
            ['offsetX' => 4, 'offsetY' => -2, 'brightness' => 0.4],
            ['offsetX' => 2, 'offsetY' => -1, 'brightness' => 0.6],
            ['offsetX' => 1, 'offsetY' => 0,  'brightness' => 0.8],
            ['offsetX' => 0, 'offsetY' => 1,  'brightness' => 1.0],  // Front page
        ];

        $totalSteps = 20;

        foreach ($pages as $pageIdx => $page) {
            $targetCol = max(1, $this->cx - (int) ($pageWidth / 2) + $page['offsetX']);
            $targetRow = max(1, $this->cy - (int) ($pageHeight / 2) + $page['offsetY']);

            // Flutter in from right
            $startCol = $this->termWidth + 5;
            $flutterSteps = max(5, $totalSteps - $pageIdx * 3);

            for ($step = 0; $step < $flutterSteps; $step++) {
                $progress = $step / $flutterSteps;
                // Ease-out for settling feel
                $eased = 1.0 - (1.0 - $progress) * (1.0 - $progress);

                $currentCol = (int) ($startCol + $eased * ($targetCol - $startCol));

                // Erase previous frame for this page only
                foreach ($this->prevCells as $key => ['row' => $pr, 'col' => $pc]) {
                    if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                        echo Theme::moveTo($pr, $pc).' ';
                    }
                }
                $this->prevCells = [];

                // Redraw all previously settled pages
                for ($prevPage = 0; $prevPage < $pageIdx; $prevPage++) {
                    $pp = $pages[$prevPage];
                    $ppCol = max(1, $this->cx - (int) ($pageWidth / 2) + $pp['offsetX']);
                    $ppRow = max(1, $this->cy - (int) ($pageHeight / 2) + $pp['offsetY']);
                    $this->drawPage($ppRow, $ppCol, $pageWidth, $pageHeight, $pp['brightness']);
                }

                // Draw current page at flutter position
                $this->drawPage($targetRow, $currentCol, $pageWidth, $pageHeight, $page['brightness']);

                usleep(25000);
            }
        }

        usleep(100000);
    }

    /**
     * Draw a single page rectangle at the given position.
     */
    private function drawPage(int $startRow, int $startCol, int $width, int $height, float $brightness): void
    {
        $r = Theme::reset();
        $bR = (int) (240 * $brightness);
        $bG = (int) (230 * $brightness);
        $bB = (int) (210 * $brightness);
        $color = Theme::rgb($bR, $bG, $bB);

        for ($dy = 0; $dy < $height; $dy++) {
            $row = $startRow + $dy;
            if ($row < 1 || $row > $this->termHeight) {
                continue;
            }

            $isTop = ($dy === 0);
            $isBottom = ($dy === $height - 1);

            if ($isTop) {
                $col = $startCol;
                if ($col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$color.'┌'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
                for ($dx = 1; $dx < $width - 1; $dx++) {
                    $col = $startCol + $dx;
                    if ($col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).$color.'─'.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                }
                $col = $startCol + $width - 1;
                if ($col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$color.'┐'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            } elseif ($isBottom) {
                $col = $startCol;
                if ($col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$color.'└'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
                for ($dx = 1; $dx < $width - 1; $dx++) {
                    $col = $startCol + $dx;
                    if ($col >= 1 && $col < $this->termWidth) {
                        echo Theme::moveTo($row, $col).$color.'─'.$r;
                        $this->prevCells[] = ['row' => $row, 'col' => $col];
                    }
                }
                $col = $startCol + $width - 1;
                if ($col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$color.'┘'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            } else {
                // Side borders
                $col = $startCol;
                if ($col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$color.'│'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
                $col = $startCol + $width - 1;
                if ($col >= 1 && $col < $this->termWidth) {
                    echo Theme::moveTo($row, $col).$color.'│'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $col];
                }
            }
        }
    }

    /**
     * Phase 2 — Writing (~1s).
     *
     * On the front page, text characters appear left-to-right, line by line,
     * as if a quill is writing. A golden quill tip leads the text. Blue ink
     * color for written text. 4-5 lines of dots/dashes.
     */
    private function phaseWriting(): void
    {
        $r = Theme::reset();
        $inkColor = Theme::rgb(40, 60, 160);
        $quillColor = Theme::rgb(255, 200, 80);
        $quillGlow = Theme::rgb(255, 220, 120);

        // Front page position (matches page index 3 from phasePages)
        $pageWidth = min(32, $this->termWidth - 16);
        $pageHeight = min(14, $this->termHeight - 10);
        $pageCol = max(1, $this->cx - (int) ($pageWidth / 2));
        $pageRow = max(1, $this->cy - (int) ($pageHeight / 2) + 1);

        // Writing area inside the page
        $writeStartCol = $pageCol + 3;
        $writeEndCol = $pageCol + $pageWidth - 4;
        $writeStartRow = $pageRow + 2;
        $writeLineCount = min(5, $pageHeight - 4);

        // Vary line lengths
        $lineLengths = [];
        for ($i = 0; $i < $writeLineCount; $i++) {
            $maxLen = $writeEndCol - $writeStartCol;
            $lineLengths[] = max(5, $maxLen - rand(0, (int) ($maxLen * 0.35)));
        }

        $quillPrevRow = 0;
        $quillPrevCol = 0;

        for ($line = 0; $line < $writeLineCount; $line++) {
            $row = $writeStartRow + $line * 2; // Double-spaced for readability
            if ($row < 1 || $row > $this->termHeight) {
                continue;
            }

            $lineLen = $lineLengths[$line];

            for ($charIdx = 0; $charIdx <= $lineLen; $charIdx++) {
                $col = $writeStartCol + $charIdx;
                if ($col < 1 || $col >= $this->termWidth) {
                    continue;
                }

                // Erase previous quill position
                if ($quillPrevRow >= 1 && $quillPrevRow <= $this->termHeight
                    && $quillPrevCol >= 1 && $quillPrevCol < $this->termWidth) {
                    // If the previous quill position had ink, redraw the ink char
                    if ($quillPrevCol < $col || $quillPrevRow < $row) {
                        $inkChar = self::TEXT_CHARS[array_rand(self::TEXT_CHARS)];
                        echo Theme::moveTo($quillPrevRow, $quillPrevCol).$inkColor.$inkChar.$r;
                    } else {
                        echo Theme::moveTo($quillPrevRow, $quillPrevCol).' ';
                    }
                }

                // Draw quill tip at current position
                echo Theme::moveTo($row, $col).$quillColor.'✧'.$r;
                $quillPrevRow = $row;
                $quillPrevCol = $col;
                $this->prevCells[] = ['row' => $row, 'col' => $col];

                // Draw ink char behind the quill
                if ($charIdx > 0) {
                    $inkCol = $col - 1;
                    if ($inkCol >= 1 && $inkCol < $this->termWidth) {
                        $inkChar = self::TEXT_CHARS[array_rand(self::TEXT_CHARS)];
                        echo Theme::moveTo($row, $inkCol).$inkColor.$inkChar.$r;
                    }
                }

                usleep(12000);
            }

            // Write the last char of the line (replace quill with ink)
            $lastCol = $writeStartCol + $lineLen;
            if ($lastCol >= 1 && $lastCol < $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                $inkChar = self::TEXT_CHARS[array_rand(self::TEXT_CHARS)];
                echo Theme::moveTo($row, $lastCol).$inkColor.$inkChar.$r;
            }
        }

        // Erase final quill position
        if ($quillPrevRow >= 1 && $quillPrevRow <= $this->termHeight
            && $quillPrevCol >= 1 && $quillPrevCol < $this->termWidth) {
            echo Theme::moveTo($quillPrevRow, $quillPrevCol).' ';
        }

        usleep(150000);
    }

    /**
     * Phase 3 — Title (~1.2s).
     *
     * "D O C S" fades in with a blue-to-white gradient. Subtitle
     * "✧ Documentation refreshed ✧" types out below.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        // Light background stars (scattered dots like scattered pages)
        $scatterCount = (int) ($this->termWidth * $this->termHeight * 0.005);
        for ($i = 0; $i < $scatterCount; $i++) {
            $sr = rand(1, $this->termHeight);
            $sc = rand(1, $this->termWidth - 1);
            if ($sr >= 1 && $sr <= $this->termHeight && $sc >= 1 && $sc < $this->termWidth) {
                $b = rand(30, 80);
                echo Theme::moveTo($sr, $sc)
                    .Theme::rgb($b, $b, (int) ($b * 0.9))
                    .'·'.$r;
            }
        }

        // Title: "D O C S" fade in blue -> white
        $title = 'D O C S';
        $titleLen = mb_strwidth($title);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $titleRow = $this->cy;

        $blueToWhiteGradient = [
            [40, 60, 160],     // Deep blue ink
            [60, 80, 180],
            [80, 100, 200],
            [110, 130, 215],
            [140, 160, 230],
            [170, 185, 240],
            [200, 210, 248],
            [230, 235, 255],   // Near white
        ];

        foreach ($blueToWhiteGradient as [$rv, $gv, $bv]) {
            if ($titleRow >= 1 && $titleRow <= $this->termHeight) {
                echo Theme::moveTo($titleRow, $titleCol)
                    .Theme::rgb($rv, $gv, $bv).$title.$r;
            }
            usleep(50000);
        }

        // Subtitle typeout
        $subtitle = '✧ Documentation refreshed ✧';
        $subLen = mb_strwidth($subtitle);
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));
        $subRow = $titleRow + 2;

        usleep(100000);

        if ($subRow >= 1 && $subRow <= $this->termHeight) {
            echo Theme::moveTo($subRow, $subCol);
            foreach (mb_str_split($subtitle) as $char) {
                echo Theme::rgb(40, 60, 160).$char.$r;
                usleep(25000);
            }
        }

        usleep(500000);
    }
}

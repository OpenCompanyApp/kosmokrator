<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Code inspection animation for the :review command.
 *
 * A scanning eye passes over code lines, highlights issues in red/amber,
 * marks clean lines green, then stamps a verdict with issue count.
 */
class AnsiReview implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const CODE_CHARS = ['─', '━', '╌', '·'];

    private const BEAM_CHARS = ['▏', '▎', '▍', '▌'];

    /** Scan beam cyan */
    private const CYAN_R = 100;

    private const CYAN_G = 200;

    private const CYAN_B = 255;

    /** Issue red */
    private const RED_R = 255;

    private const RED_G = 60;

    private const RED_B = 60;

    /** Approval green */
    private const GREEN_R = 80;

    private const GREEN_G = 255;

    private const GREEN_B = 80;

    /** Warning amber */
    private const AMBER_R = 255;

    private const AMBER_G = 200;

    private const AMBER_B = 50;

    /** @var array<int, array{row: int, startCol: int, endCol: int, isIssue: bool}> Code line positions */
    private array $codeLines = [];

    /**
     * Run the full code inspection animation (~3s).
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
            $this->phaseCodeLines();
            $this->phaseScan();
            $this->phaseVerdict();
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
     * Phase 1 — Code Lines (~0.6s).
     *
     * Faint horizontal lines appear across the screen representing code.
     * Lines vary in length and position, drawn in dim gray tones with
     * code-like characters.
     */
    private function phaseCodeLines(): void
    {
        $r = Theme::reset();
        $lineCount = rand(15, 20);
        $margin = 3;

        // Generate code line positions with varying indentation and length
        $this->codeLines = [];
        $usedRows = [];
        for ($i = 0; $i < $lineCount; $i++) {
            $attempts = 0;
            do {
                $row = rand($margin, $this->termHeight - $margin);
                $tooClose = false;
                foreach ($usedRows as $used) {
                    if (abs($used - $row) < 2) {
                        $tooClose = true;
                        break;
                    }
                }
                $attempts++;
            } while ($tooClose && $attempts < 30);

            $indent = rand(4, (int) ($this->termWidth * 0.3));
            $lineLen = rand((int) ($this->termWidth * 0.2), (int) ($this->termWidth * 0.65));
            $endCol = min($indent + $lineLen, $this->termWidth - $margin);

            // ~30% of lines will be issues
            $isIssue = (rand(1, 100) <= 30);

            $this->codeLines[] = [
                'row' => $row,
                'startCol' => $indent,
                'endCol' => $endCol,
                'isIssue' => $isIssue,
            ];
            $usedRows[] = $row;
        }

        // Sort by row for visual coherence
        usort($this->codeLines, fn ($a, $b) => $a['row'] <=> $b['row']);

        // Appear one by one with a slight stagger
        $delayPerLine = (int) (600000 / max(1, $lineCount));
        foreach ($this->codeLines as $line) {
            $row = $line['row'];
            $startCol = $line['startCol'];
            $endCol = $line['endCol'];

            // Draw the code line in dim gray
            $grayLevel = rand(40, 70);
            $dimGray = Theme::rgb($grayLevel, $grayLevel, $grayLevel + 10);

            for ($col = $startCol; $col <= $endCol; $col++) {
                if ($col >= 1 && $col < $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                    // Mix of code characters with some gaps
                    if (rand(1, 100) <= 85) {
                        $char = self::CODE_CHARS[array_rand(self::CODE_CHARS)];
                        echo Theme::moveTo($row, $col).$dimGray.$char.$r;
                    }
                }
            }

            // Small line number gutter
            $gutterCol = $startCol - 2;
            if ($gutterCol >= 1 && $gutterCol < $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                $lineNum = Theme::rgb(50, 50, 60);
                echo Theme::moveTo($row, $gutterCol).$lineNum.str_pad((string) rand(1, 99), 2, ' ', STR_PAD_LEFT).$r;
            }

            usleep($delayPerLine);
        }

        usleep(100000);
    }

    /**
     * Phase 2 — Scan (~0.8s).
     *
     * A vertical cyan scan beam sweeps left-to-right across the screen.
     * As it passes each code line, issue lines flash red and clean lines
     * flash green.
     */
    private function phaseScan(): void
    {
        $r = Theme::reset();
        $cyan = Theme::rgb(self::CYAN_R, self::CYAN_G, self::CYAN_B);
        $dimCyan = Theme::rgb(40, 80, 120);
        $issueRed = Theme::rgb(self::RED_R, self::RED_G, self::RED_B);
        $okGreen = Theme::rgb(self::GREEN_R, self::GREEN_G, self::GREEN_B);

        $totalSteps = $this->termWidth;
        $stepDelay = (int) (800000 / max(1, $totalSteps));

        // Track which lines have already been scanned
        $scannedLines = [];

        for ($scanCol = 1; $scanCol < $totalSteps; $scanCol += 2) {
            // Erase previous beam cells
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            // Draw vertical scan beam at current column
            for ($row = 1; $row <= $this->termHeight; $row++) {
                if ($scanCol >= 1 && $scanCol < $this->termWidth) {
                    $beamChar = self::BEAM_CHARS[($row + $scanCol) % count(self::BEAM_CHARS)];
                    echo Theme::moveTo($row, $scanCol).$cyan.$beamChar.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $scanCol];
                }

                // Glow column behind the beam
                $glowCol = $scanCol - 1;
                if ($glowCol >= 1 && $glowCol < $this->termWidth) {
                    echo Theme::moveTo($row, $glowCol).$dimCyan.'▏'.$r;
                    $this->prevCells[] = ['row' => $row, 'col' => $glowCol];
                }
            }

            // Check if the beam has passed through any code lines
            foreach ($this->codeLines as $idx => $line) {
                if (isset($scannedLines[$idx])) {
                    continue;
                }

                // Trigger when beam reaches the midpoint of the code line
                $midCol = (int) (($line['startCol'] + $line['endCol']) / 2);
                if ($scanCol >= $midCol) {
                    $scannedLines[$idx] = true;
                    $row = $line['row'];

                    // Flash the entire code line in the appropriate color
                    $flashColor = $line['isIssue'] ? $issueRed : $okGreen;
                    for ($col = $line['startCol']; $col <= $line['endCol']; $col++) {
                        if ($col >= 1 && $col < $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                            if (rand(1, 100) <= 85) {
                                $char = self::CODE_CHARS[array_rand(self::CODE_CHARS)];
                                echo Theme::moveTo($row, $col).$flashColor.$char.$r;
                            }
                        }
                    }
                }
            }

            usleep($stepDelay);
        }

        // Final erase of beam
        foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
            if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                echo Theme::moveTo($pr, $pc).' ';
            }
        }
        $this->prevCells = [];

        usleep(100000);
    }

    /**
     * Phase 3 — Verdict (~0.8s).
     *
     * Issue lines pulse red to amber, clean lines settle to dim.
     * Markers appear: cross for issues, checkmark for clean lines.
     * Issue count fades in at center.
     */
    private function phaseVerdict(): void
    {
        $r = Theme::reset();
        $issueRed = Theme::rgb(self::RED_R, self::RED_G, self::RED_B);
        $amber = Theme::rgb(self::AMBER_R, self::AMBER_G, self::AMBER_B);
        $dimGreen = Theme::rgb(40, 100, 40);
        $dimGray = Theme::rgb(50, 50, 55);

        // Pulse issue lines red -> amber, settle clean lines to dim
        $pulseSteps = [
            'issue' => [
                [self::RED_R, self::RED_G, self::RED_B],
                [255, 100, 60],
                [255, 140, 55],
                [self::AMBER_R, self::AMBER_G, self::AMBER_B],
                [220, 180, 50],
                [self::AMBER_R, self::AMBER_G, self::AMBER_B],
            ],
            'clean' => [
                [self::GREEN_R, self::GREEN_G, self::GREEN_B],
                [60, 180, 60],
                [50, 120, 50],
                [40, 80, 40],
                [35, 60, 35],
                [30, 50, 30],
            ],
        ];

        $issueCount = 0;
        foreach ($this->codeLines as $line) {
            if ($line['isIssue']) {
                $issueCount++;
            }
        }

        // Animate the pulse for all lines simultaneously
        $stepCount = count($pulseSteps['issue']);
        for ($step = 0; $step < $stepCount; $step++) {
            foreach ($this->codeLines as $line) {
                $row = $line['row'];
                $colors = $line['isIssue'] ? $pulseSteps['issue'][$step] : $pulseSteps['clean'][$step];
                $color = Theme::rgb($colors[0], $colors[1], $colors[2]);

                // Redraw the code line in the current pulse color
                for ($col = $line['startCol']; $col <= $line['endCol']; $col += 3) {
                    if ($col >= 1 && $col < $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                        $char = self::CODE_CHARS[array_rand(self::CODE_CHARS)];
                        echo Theme::moveTo($row, $col).$color.$char.$r;
                    }
                }
            }
            usleep(60000);
        }

        usleep(100000);

        // Place markers next to each line
        foreach ($this->codeLines as $line) {
            $row = $line['row'];
            $markerCol = $line['endCol'] + 2;
            if ($markerCol >= 1 && $markerCol < $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                if ($line['isIssue']) {
                    echo Theme::moveTo($row, $markerCol).$amber.'✗'.$r;
                } else {
                    echo Theme::moveTo($row, $markerCol).$dimGreen.'✓'.$r;
                }
            }
            usleep(20000);
        }

        usleep(100000);

        // Issue count at center
        $countText = "{$issueCount} issue".($issueCount !== 1 ? 's' : '').' found';
        $countLen = mb_strwidth($countText);
        $countCol = max(1, (int) (($this->termWidth - $countLen) / 2));

        // Fade in the count
        $fadeCyan = [
            [30, 60, 80],
            [50, 100, 140],
            [70, 150, 200],
            [self::CYAN_R, self::CYAN_G, self::CYAN_B],
        ];
        foreach ($fadeCyan as [$rv, $gv, $bv]) {
            if ($this->cy >= 1 && $this->cy <= $this->termHeight) {
                echo Theme::moveTo($this->cy, $countCol)
                    .Theme::rgb($rv, $gv, $bv).$countText.$r;
            }
            usleep(50000);
        }

        usleep(200000);
    }

    /**
     * Phase 4 — Title (~0.8s).
     *
     * "R E V I E W" fades in with a cyan-to-white gradient.
     * Subtitle "Inspection complete" typewriter-appears beneath.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();

        // Clear screen for title
        echo Theme::clearScreen();

        // Fade in "R E V I E W"
        $title = 'R E V I E W';
        $subtitle = "\u{2299} Inspection complete \u{2299}";
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        $fadeGradient = [
            [20, 50, 70], [40, 80, 110], [60, 120, 160], [80, 160, 210],
            [self::CYAN_R, self::CYAN_G, self::CYAN_B],
            [140, 220, 255], [180, 235, 255], [220, 245, 255], [255, 255, 255],
        ];

        foreach ($fadeGradient as [$rv, $gv, $bv]) {
            if ($this->cy - 1 >= 1 && $this->cy - 1 <= $this->termHeight) {
                echo Theme::moveTo($this->cy - 1, $titleCol)
                    .Theme::rgb($rv, $gv, $bv).$title.$r;
            }
            usleep(40000);
        }

        // Subtitle typeout in cyan
        usleep(80000);
        $cyan = Theme::rgb(self::CYAN_R, self::CYAN_G, self::CYAN_B);
        if ($this->cy + 1 >= 1 && $this->cy + 1 <= $this->termHeight) {
            echo Theme::moveTo($this->cy + 1, $subCol);
            foreach (mb_str_split($subtitle) as $char) {
                echo $cyan.$char.$r;
                usleep(20000);
            }
        }

        usleep(400000);
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * Deslop animation — purification ritual.
 *
 * Chaotic slop characters fill the screen in muddy colors, then dissolve
 * from edges inward. Clean crystal structures emerge from center, and
 * the title fades in pure white — noise transformed to signal.
 */
class AnsiDeslop implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    private const SLOP_CHARS = ['#', '@', '%', '&', '~', '$', '{', '}', '?', '!'];

    private const CRYSTAL_CHARS = ['◇', '◆', '⬡', '⬢', '·'];

    /**
     * Run the full animation sequence (chaos → dissolution → crystallization → title).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseChaos();
        $this->phaseDissolution();
        $this->phaseCrystallization();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Chaos.
     *
     * Screen fills with random slop characters at random positions in muddy
     * brown and gray tones. Dense, ugly, chaotic — the antithesis of clean code.
     */
    private function phaseChaos(): void
    {
        $r = Theme::reset();

        /** @var array<string, array{char: string, row: int, col: int, cr: int, cg: int, cb: int}> */
        $this->prevCells = [];

        $totalSteps = 16;
        $charsPerStep = 25;

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;

            for ($i = 0; $i < $charsPerStep; $i++) {
                $row = mt_rand(1, $this->termHeight);
                $col = mt_rand(1, $this->termWidth);
                $char = self::SLOP_CHARS[mt_rand(0, count(self::SLOP_CHARS) - 1)];

                // Muddy brown rgb(120,80,40) ↔ gray rgb(100,100,100) with variation
                $mudBias = mt_rand(0, 100) / 100.0;
                $cr = (int) (100 + 30 * $mudBias + mt_rand(-20, 20));
                $cg = (int) (80 + 20 * (1 - $mudBias) + mt_rand(-15, 15));
                $cb = (int) (40 + 60 * (1 - $mudBias) + mt_rand(-15, 15));
                $cr = max(30, min(150, $cr));
                $cg = max(30, min(130, $cg));
                $cb = max(20, min(120, $cb));

                if ($col >= 1 && $col <= $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                    echo Theme::moveTo($row, $col).Theme::rgb($cr, $cg, $cb).$char.$r;
                    $this->prevCells["{$row},{$col}"] = [
                        'char' => $char, 'row' => $row, 'col' => $col,
                        'cr' => $cr, 'cg' => $cg, 'cb' => $cb,
                    ];
                }
            }

            usleep(50000);
        }
    }

    /**
     * Phase 2 — Dissolution.
     *
     * Characters fade and disappear from edges inward. Each character fades
     * through decreasing brightness before vanishing. Some transform into
     * dots before disappearing.
     *
     * @var array<string, array{char: string, row: int, col: int, cr: int, cg: int, cb: int}>
     */
    private array $prevCells = [];

    private function phaseDissolution(): void
    {
        $r = Theme::reset();
        $totalSteps = 16;

        // Sort cells by distance from center (farthest first = dissolve edges first)
        $cells = $this->prevCells;
        uasort($cells, function (array $a, array $b): int {
            $distA = sqrt(pow($a['col'] - $this->cx, 2) + pow(($a['row'] - $this->cy) * 2, 2));
            $distB = sqrt(pow($b['col'] - $this->cx, 2) + pow(($b['row'] - $this->cy) * 2, 2));

            return (int) ($distB - $distA); // farthest first
        });

        $cellKeys = array_keys($cells);
        $totalCells = count($cellKeys);
        $dissolvedAt = []; // key => step when dissolution starts

        for ($step = 0; $step < $totalSteps; $step++) {
            $progress = $step / $totalSteps;

            // Schedule new cells to start dissolving (edges first)
            $dissolveUpTo = (int) ($totalCells * $progress);
            for ($i = 0; $i < $dissolveUpTo; $i++) {
                if (! isset($dissolvedAt[$cellKeys[$i]])) {
                    $dissolvedAt[$cellKeys[$i]] = $step;
                }
            }

            echo Theme::clearScreen();

            foreach ($cells as $key => $cell) {
                if (isset($dissolvedAt[$key])) {
                    $age = $step - $dissolvedAt[$key];

                    if ($age >= 4) {
                        continue; // fully gone
                    }

                    // Fade stages: full → dim → dot → gone
                    $fade = $age / 4.0;
                    $brightness = max(0.0, 1.0 - $fade);
                    $cr = (int) ($cell['cr'] * $brightness);
                    $cg = (int) ($cell['cg'] * $brightness);
                    $cb = (int) ($cell['cb'] * $brightness);
                    $char = $age >= 2 ? '·' : $cell['char'];

                    if ($cell['col'] >= 1 && $cell['col'] <= $this->termWidth && $cell['row'] >= 1 && $cell['row'] <= $this->termHeight) {
                        echo Theme::moveTo($cell['row'], $cell['col']).Theme::rgb($cr, $cg, $cb).$char.$r;
                    }
                } else {
                    // Not yet dissolving — show normally
                    if ($cell['col'] >= 1 && $cell['col'] <= $this->termWidth && $cell['row'] >= 1 && $cell['row'] <= $this->termHeight) {
                        echo Theme::moveTo($cell['row'], $cell['col'])
                            .Theme::rgb($cell['cr'], $cell['cg'], $cell['cb'])
                            .$cell['char'].$r;
                    }
                }
            }

            usleep(50000);
        }
    }

    /**
     * Phase 3 — Crystallization.
     *
     * Clean geometric patterns emerge from the center outward. Diamond and
     * hexagonal shapes in bright cyan and white — symmetric, orderly, the
     * visual antithesis of the chaos phase.
     */
    private function phaseCrystallization(): void
    {
        $r = Theme::reset();
        $totalSteps = 16;

        // Pre-compute crystal pattern: concentric diamond rings
        $crystalCells = [];
        $maxRing = (int) (min($this->cx, $this->cy) * 0.7);

        for ($ring = 0; $ring <= $maxRing; $ring++) {
            $points = max(8, $ring * 4);
            for ($i = 0; $i < $points; $i++) {
                // Diamond shape (Manhattan distance rings)
                $t = $i / $points;
                $side = (int) ($t * 4);
                $frac = ($t * 4) - $side;

                $dx = match ($side) {
                    0 => (int) ($ring * (1 - $frac)),
                    1 => (int) (-$ring * $frac),
                    2 => (int) (-$ring * (1 - $frac)),
                    3 => (int) ($ring * $frac),
                    default => 0,
                };
                $dy = match ($side) {
                    0 => (int) ($ring * $frac),
                    1 => (int) ($ring * (1 - $frac)),
                    2 => (int) (-$ring * $frac),
                    3 => (int) (-$ring * (1 - $frac)),
                    default => 0,
                };

                $col = $this->cx + $dx;
                $row = $this->cy + (int) ($dy * 0.5);

                if ($col >= 1 && $col <= $this->termWidth && $row >= 1 && $row <= $this->termHeight) {
                    // Char selection based on ring and position
                    $charIdx = ($ring + $i) % count(self::CRYSTAL_CHARS);
                    if ($ring === 0) {
                        $char = '◆';
                    } elseif ($ring % 3 === 0) {
                        $char = self::CRYSTAL_CHARS[$charIdx];
                    } elseif ($ring % 2 === 0) {
                        $char = '·';
                    } else {
                        $char = self::CRYSTAL_CHARS[$charIdx % 3];
                    }

                    $crystalCells[] = [
                        'row' => $row, 'col' => $col, 'char' => $char,
                        'ring' => $ring, 'maxRing' => $maxRing,
                    ];
                }
            }
        }

        // Animate: reveal rings from center outward
        for ($step = 0; $step < $totalSteps; $step++) {
            echo Theme::clearScreen();
            $progress = $step / $totalSteps;
            $revealRadius = $progress * $maxRing;

            foreach ($crystalCells as $cell) {
                if ($cell['ring'] > $revealRadius) {
                    continue;
                }

                $ringProgress = 1.0 - ($cell['ring'] / max(1, $revealRadius));

                // Color: center is bright white, outer rings are cyan
                $cr = (int) (100 + 140 * $ringProgress);
                $cg = (int) (220 + 30 * $ringProgress);
                $cb = 255;

                // Newly revealed rings sparkle brighter
                $edgeDist = abs($cell['ring'] - $revealRadius);
                if ($edgeDist < 2) {
                    $cr = min(255, $cr + 40);
                    $cg = min(255, $cg + 20);
                }

                echo Theme::moveTo($cell['row'], $cell['col'])
                    .Theme::rgb($cr, $cg, $cb).$cell['char'].$r;
            }

            // Center always bright
            echo Theme::moveTo($this->cy, $this->cx)
                .Theme::rgb(240, 250, 255).'◆'.$r;

            usleep(50000);
        }
    }

    /**
     * Phase 4 — Title reveal.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'D E S L O P';
        $subtitle = '✦ Purified ✦';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Redraw a few crystal accents around the title area
        $accentPositions = [
            [$this->cy - 3, $this->cx - 8, '◇'],
            [$this->cy - 3, $this->cx + 8, '◇'],
            [$this->cy + 3, $this->cx - 6, '·'],
            [$this->cy + 3, $this->cx + 6, '·'],
            [$this->cy - 2, $this->cx - 12, '·'],
            [$this->cy - 2, $this->cx + 12, '·'],
        ];
        $crystalDim = Theme::rgb(60, 140, 180);
        foreach ($accentPositions as [$aRow, $aCol, $aChar]) {
            if ($aCol >= 1 && $aCol <= $this->termWidth && $aRow >= 1 && $aRow <= $this->termHeight) {
                echo Theme::moveTo($aRow, $aCol).$crystalDim.$aChar.$r;
            }
        }

        // Fade in through purity gradient: dim gray → cyan → crystal white
        $gradient = [
            [40, 50, 60],
            [70, 90, 110],
            [100, 140, 170],
            [140, 190, 220],
            [180, 220, 240],
            [210, 240, 250],
            [230, 248, 255],
            [240, 250, 255],
        ];

        foreach ($gradient as [$rv, $g, $b]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $g, $b).$title.$r;
            usleep(55000);
        }

        // Subtitle typeout
        usleep(120000);
        $cyan = Theme::rgb(100, 220, 255);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $cyan.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Theme;

/**
 * DeepInit animation — archaeological excavation.
 *
 * Layers of earth fill the screen bottom-up, then peel away top-down
 * with a golden excavation frontier. A clean project tree structure is
 * revealed beneath, and the title fades in golden warmth.
 */
class AnsiDeepInit implements AnsiAnimation
{
    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    private const EARTH_CHARS = ['░', '▒', '▓', '█'];

    private const CRUMBLE_SEQUENCE = ['▓', '▒', '░', '·'];

    private const TREE_CHARS = ['├', '─', '│', '└', '●', '○'];

    /**
     * Cells placed during earth phase, keyed by "row,col".
     *
     * @var array<string, array{char: string, row: int, col: int, cr: int, cg: int, cb: int}>
     */
    private array $prevCells = [];

    /**
     * Run the full animation sequence (earth → excavation → structure → title).
     */
    public function animate(): void
    {
        $this->termWidth = (int) exec('tput cols') ?: 120;
        $this->termHeight = (int) exec('tput lines') ?: 30;
        $this->cx = (int) ($this->termWidth / 2);
        $this->cy = (int) ($this->termHeight / 2);

        echo Theme::hideCursor().Theme::clearScreen();

        register_shutdown_function(fn () => print (Theme::showCursor()));

        $this->phaseEarth();
        $this->phaseExcavation();
        $this->phaseStructure();
        $this->phaseTitle();

        usleep(400000);
        echo Theme::clearScreen();
        echo Theme::showCursor();
    }

    /**
     * Phase 1 — Earth.
     *
     * Screen fills bottom-up with earth-colored characters in layers of soil.
     * Colors vary by layer depth (darker at bottom, lighter at top).
     * Fills about 70% of the screen height.
     */
    private function phaseEarth(): void
    {
        $r = Theme::reset();
        $fillHeight = (int) ($this->termHeight * 0.7);
        $startRow = $this->termHeight;
        $endRow = $this->termHeight - $fillHeight + 1;
        $totalSteps = 14;

        $this->prevCells = [];

        // Fill rows bottom-up in batches
        $rowsPerStep = max(1, (int) ceil($fillHeight / $totalSteps));

        for ($step = 0; $step < $totalSteps; $step++) {
            $batchStart = $startRow - ($step * $rowsPerStep);
            $batchEnd = max($endRow, $batchStart - $rowsPerStep + 1);

            for ($row = $batchStart; $row >= $batchEnd; $row--) {
                if ($row < 1 || $row > $this->termHeight) {
                    continue;
                }

                // Depth: 0.0 at surface (top of earth), 1.0 at bottom
                $depth = ($row - $endRow) / max(1, $startRow - $endRow);

                // Color gradient by depth: surface rgb(139,90,43) → deep rgb(90,60,30) → bedrock rgb(60,40,20)
                $cr = (int) (139 - 79 * $depth);
                $cg = (int) (90 - 50 * $depth);
                $cb = (int) (43 - 23 * $depth);

                // Character density varies by depth (deeper = denser)
                $charIdx = min(count(self::EARTH_CHARS) - 1, (int) ($depth * count(self::EARTH_CHARS)));

                for ($col = 1; $col <= $this->termWidth; $col++) {
                    // Not every cell filled — some gaps for texture
                    if (mt_rand(0, 100) < 15) {
                        continue;
                    }

                    // Small color variation per cell
                    $cellR = max(30, min(160, $cr + mt_rand(-15, 15)));
                    $cellG = max(20, min(110, $cg + mt_rand(-10, 10)));
                    $cellB = max(10, min(60, $cb + mt_rand(-8, 8)));

                    // Vary char slightly within the layer
                    $ci = min(count(self::EARTH_CHARS) - 1, max(0, $charIdx + mt_rand(-1, 1)));
                    $char = self::EARTH_CHARS[$ci];

                    echo Theme::moveTo($row, $col).Theme::rgb($cellR, $cellG, $cellB).$char.$r;
                    $this->prevCells["{$row},{$col}"] = [
                        'char' => $char, 'row' => $row, 'col' => $col,
                        'cr' => $cellR, 'cg' => $cellG, 'cb' => $cellB,
                    ];
                }
            }

            usleep(50000);
        }
    }

    /**
     * Phase 2 — Excavation.
     *
     * Layers peel away top-down, revealing clear space. Each row clears with
     * a crumble animation (characters degrade through stages). A golden glow
     * appears at the excavation frontier.
     */
    private function phaseExcavation(): void
    {
        $r = Theme::reset();
        $fillHeight = (int) ($this->termHeight * 0.7);
        $topEarthRow = $this->termHeight - $fillHeight + 1;
        $totalSteps = 16;

        // Group cells by row for top-down clearing
        $rowBuckets = [];
        foreach ($this->prevCells as $key => $cell) {
            $rowBuckets[$cell['row']][$key] = $cell;
        }
        ksort($rowBuckets);

        $earthRows = array_keys($rowBuckets);
        $rowsPerStep = max(1, (int) ceil(count($earthRows) / $totalSteps));

        // Track crumble state per row: -1 = not started, 0..3 = crumble stage, 4 = cleared
        $rowState = array_fill_keys($earthRows, -1);

        for ($step = 0; $step < $totalSteps; $step++) {
            // Start crumbling new rows from top
            $clearUpTo = min(count($earthRows), ($step + 1) * $rowsPerStep);
            for ($i = 0; $i < $clearUpTo; $i++) {
                $row = $earthRows[$i];
                if ($rowState[$row] === -1) {
                    $rowState[$row] = 0;
                }
            }

            // Advance all active rows one crumble stage
            echo Theme::clearScreen();

            // Find the frontier row (lowest row that is currently crumbling)
            $frontierRow = 0;

            foreach ($rowBuckets as $row => $cells) {
                $state = $rowState[$row];

                if ($state === -1) {
                    // Untouched — draw normally
                    foreach ($cells as $cell) {
                        if ($cell['col'] >= 1 && $cell['col'] <= $this->termWidth && $cell['row'] >= 1 && $cell['row'] <= $this->termHeight) {
                            echo Theme::moveTo($cell['row'], $cell['col'])
                                .Theme::rgb($cell['cr'], $cell['cg'], $cell['cb'])
                                .$cell['char'].$r;
                        }
                    }
                } elseif ($state < count(self::CRUMBLE_SEQUENCE)) {
                    // Crumbling — show degraded char
                    $crumbleChar = self::CRUMBLE_SEQUENCE[$state];
                    $fade = $state / count(self::CRUMBLE_SEQUENCE);
                    $frontierRow = max($frontierRow, $row);

                    foreach ($cells as $cell) {
                        if ($cell['col'] >= 1 && $cell['col'] <= $this->termWidth && $cell['row'] >= 1 && $cell['row'] <= $this->termHeight) {
                            $cr = (int) ($cell['cr'] * (1 - $fade * 0.7));
                            $cg = (int) ($cell['cg'] * (1 - $fade * 0.7));
                            $cb = (int) ($cell['cb'] * (1 - $fade * 0.7));
                            echo Theme::moveTo($cell['row'], $cell['col'])
                                .Theme::rgb($cr, $cg, $cb).$crumbleChar.$r;
                        }
                    }
                }
                // state >= 4: fully cleared, draw nothing
            }

            // Golden glow at excavation frontier
            if ($frontierRow >= 1 && $frontierRow <= $this->termHeight) {
                $glowColor = Theme::rgb(255, 200, 80);
                $glowDim = Theme::rgb(180, 140, 40);
                for ($col = 1; $col <= $this->termWidth; $col += mt_rand(2, 5)) {
                    if ($col <= $this->termWidth) {
                        $gc = (mt_rand(0, 1) === 0) ? $glowColor : $glowDim;
                        $gChar = (mt_rand(0, 3) === 0) ? '✦' : '·';
                        echo Theme::moveTo($frontierRow, $col).$gc.$gChar.$r;
                    }
                }
                // Subtle glow row above frontier
                if ($frontierRow - 1 >= 1) {
                    for ($col = 1; $col <= $this->termWidth; $col += mt_rand(4, 8)) {
                        echo Theme::moveTo($frontierRow - 1, $col).$glowDim.'·'.$r;
                    }
                }
            }

            // Advance crumble states
            foreach ($rowState as $row => &$state) {
                if ($state >= 0 && $state < count(self::CRUMBLE_SEQUENCE) + 1) {
                    $state++;
                }
            }
            unset($state);

            usleep(50000);
        }
    }

    /**
     * Phase 3 — Structure.
     *
     * Revealed area shows clean structural lines — a project tree/map drawn
     * with box-drawing characters. Golden highlights on key nodes. Looks like
     * a file/folder tree being drawn line by line.
     */
    private function phaseStructure(): void
    {
        $r = Theme::reset();

        // Define a project tree to draw
        $treeLines = [
            ['  ●  project/', 'gold'],
            ['  ├── src/', 'white'],
            ['  │   ├── Agent/', 'white'],
            ['  │   │   ├── AgentLoop.php', 'dim'],
            ['  │   │   └── ToolExecutor.php', 'dim'],
            ['  │   ├── LLM/', 'white'],
            ['  │   │   └── AsyncClient.php', 'dim'],
            ['  │   ├── UI/', 'white'],
            ['  │   │   ├── Tui/', 'dim'],
            ['  │   │   └── Ansi/', 'dim'],
            ['  │   └── Tool/', 'white'],
            ['  │       ├── Coding/', 'dim'],
            ['  │       └── Permission/', 'dim'],
            ['  ├── config/', 'white'],
            ['  │   └── ○ kosmokrator.yaml', 'gold'],
            ['  ├── tests/', 'white'],
            ['  └── ○ composer.json', 'gold'],
        ];

        $treeStartRow = max(1, $this->cy - (int) (count($treeLines) / 2));
        $treeStartCol = max(1, $this->cx - 18);

        $totalSteps = 16;
        $linesPerStep = max(1, (int) ceil(count($treeLines) / $totalSteps));

        for ($step = 0; $step < $totalSteps; $step++) {
            $revealCount = min(count($treeLines), ($step + 1) * $linesPerStep);

            // Only draw newly revealed lines (additive rendering)
            $newStart = max(0, $revealCount - $linesPerStep);
            for ($i = $newStart; $i < $revealCount; $i++) {
                [$text, $colorType] = $treeLines[$i];
                $row = $treeStartRow + $i;

                if ($row < 1 || $row > $this->termHeight) {
                    continue;
                }

                $color = match ($colorType) {
                    'gold' => Theme::rgb(255, 200, 80),
                    'white' => Theme::rgb(220, 220, 230),
                    'dim' => Theme::rgb(160, 160, 170),
                    default => Theme::rgb(180, 180, 190),
                };

                // Draw structural chars (│├└─) in warm white, filenames in their color
                $chars = mb_str_split($text);
                $col = $treeStartCol;
                foreach ($chars as $ch) {
                    if ($col < 1 || $col > $this->termWidth) {
                        $col++;

                        continue;
                    }

                    $isStructural = in_array($ch, ['│', '├', '└', '─', '●', '○'], true);
                    $charColor = $isStructural ? Theme::rgb(180, 150, 80) : $color;

                    // Gold nodes get extra brightness
                    if ($colorType === 'gold' && ($ch === '●' || $ch === '○')) {
                        $charColor = Theme::rgb(255, 220, 100);
                    }

                    echo Theme::moveTo($row, $col).$charColor.$ch.$r;
                    $col++;
                }
            }

            usleep(50000);
        }

        // Brief hold with full tree visible
        usleep(200000);
    }

    /**
     * Phase 4 — Title reveal.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        echo Theme::clearScreen();

        $title = 'D E E P I N I T';
        $subtitle = '⊛ Structure mapped ⊛';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Redraw a few structural accents around the title area
        $accentPositions = [
            [$this->cy - 3, $this->cx - 10, '├──'],
            [$this->cy - 3, $this->cx + 7, '──┤'],
            [$this->cy + 3, $this->cx - 8, '└──'],
            [$this->cy + 3, $this->cx + 5, '──┘'],
            [$this->cy - 2, $this->cx - 14, '│'],
            [$this->cy - 2, $this->cx + 14, '│'],
            [$this->cy + 2, $this->cx - 14, '│'],
            [$this->cy + 2, $this->cx + 14, '│'],
        ];
        $structDim = Theme::rgb(120, 100, 50);
        foreach ($accentPositions as [$aRow, $aCol, $aStr]) {
            if ($aCol >= 1 && $aCol <= $this->termWidth && $aRow >= 1 && $aRow <= $this->termHeight) {
                echo Theme::moveTo($aRow, $aCol).$structDim.$aStr.$r;
            }
        }

        // Fade in through golden gradient: dark earth → warm gold → bright gold → warm white
        $gradient = [
            [50, 30, 10],
            [90, 55, 20],
            [130, 85, 30],
            [170, 120, 45],
            [200, 155, 60],
            [230, 185, 70],
            [245, 200, 80],
            [255, 220, 140],
        ];

        foreach ($gradient as [$rv, $g, $b]) {
            echo Theme::moveTo($this->cy - 1, $titleCol)
                .Theme::rgb($rv, $g, $b).$title.$r;
            usleep(55000);
        }

        // Subtitle typeout
        usleep(120000);
        $gold = Theme::rgb(255, 200, 80);
        echo Theme::moveTo($this->cy + 1, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $gold.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}

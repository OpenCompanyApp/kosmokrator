<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\Ansi\Concern\AnimationSignalHandler;
use Kosmokrator\UI\Theme;

/**
 * Team assembly animation — squad formation sequence.
 *
 * Five role figures materialize one by one with teleport effects, move
 * into V-formation, receive their labels, and stand ready under the
 * "TEAM" banner.
 */
class AnsiTeam implements AnsiAnimation
{
    use AnimationSignalHandler;

    private int $termWidth;

    private int $termHeight;

    private int $cx;

    private int $cy;

    /** @var array<int, array{row: int, col: int}> Previous frame cells to erase */
    private array $prevCells = [];

    private const TELEPORT_CHARS = ['∗', '✦', '·', '⊹', '∘'];

    /**
     * Role definitions: glyph, label, RGB color.
     *
     * @var array<int, array{glyph: string, label: string, color: array{int, int, int}}>
     */
    private const ROLES = [
        ['glyph' => '⊕', 'label' => 'PLAN', 'color' => [80, 140, 255]],   // Planner — blue
        ['glyph' => '⊗', 'label' => 'ARCH', 'color' => [180, 100, 255]],  // Architect — purple
        ['glyph' => '◈', 'label' => 'EXEC', 'color' => [80, 220, 100]],   // Executor — green
        ['glyph' => '☉', 'label' => 'TEST', 'color' => [255, 200, 80]],   // Verifier — amber
        ['glyph' => '♦', 'label' => 'FIX',  'color' => [255, 100, 80]],   // Fixer — red
    ];

    /**
     * Run the full animation sequence (arrival → formation → labels → title).
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
            $this->phaseArrival();
            $this->phaseFormation();
            $this->phaseLabels();
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
     * Get the spawn positions — evenly spaced horizontal line at screen center.
     *
     * @return list<array{int, int}> [row, col] for each role
     */
    private function getSpawnPositions(): array
    {
        $positions = [];
        $spacing = (int) ($this->termWidth / 6);
        for ($i = 0; $i < 5; $i++) {
            $col = $spacing + $i * $spacing;
            $positions[] = [$this->cy, $col];
        }

        return $positions;
    }

    /**
     * Get the V-formation target positions (leader in front, flanks behind).
     *
     * Index 0 (Planner) is the leader at the front-center of the V.
     *
     * @return list<array{int, int}> [row, col] for each role
     */
    private function getFormationPositions(): array
    {
        $spacing = 8;
        $vDepth = 3;

        return [
            [$this->cy - $vDepth * 2, $this->cx],                             // PLAN  — point of V
            [$this->cy - $vDepth, $this->cx - $spacing],                      // ARCH  — left inner
            [$this->cy - $vDepth, $this->cx + $spacing],                      // EXEC  — right inner
            [$this->cy, $this->cx - $spacing * 2],                            // TEST  — left outer
            [$this->cy, $this->cx + $spacing * 2],                            // FIX   — right outer
        ];
    }

    /**
     * Phase 1 — Arrival.
     *
     * Five figures materialize one by one. Each appears with a teleport
     * scatter effect (particles converging to position). ~1s total.
     */
    private function phaseArrival(): void
    {
        $r = Theme::reset();
        $spawnPositions = $this->getSpawnPositions();
        $materialized = []; // indices of fully materialized figures

        for ($figIdx = 0; $figIdx < 5; $figIdx++) {
            [$targetRow, $targetCol] = $spawnPositions[$figIdx];
            [$cr, $cg, $cb] = self::ROLES[$figIdx]['color'];
            $glyph = self::ROLES[$figIdx]['glyph'];

            // Teleport converge: 8 steps per figure
            $teleportSteps = 8;
            $scatterRadius = 6;

            // Generate scatter particles
            $scatterParticles = [];
            for ($p = 0; $p < 12; $p++) {
                $angle = ($p / 12) * 2 * M_PI + (mt_rand(-30, 30) / 100.0);
                $dist = mt_rand(3, $scatterRadius);
                $scatterParticles[] = ['angle' => $angle, 'dist' => (float) $dist];
            }

            for ($step = 0; $step < $teleportSteps; $step++) {
                // Erase previous frame
                foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                    if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                        echo Theme::moveTo($pr, $pc).' ';
                    }
                }
                $this->prevCells = [];

                // Redraw already-materialized figures
                foreach ($materialized as $mIdx) {
                    [$mRow, $mCol] = $spawnPositions[$mIdx];
                    [$mr, $mg, $mb] = self::ROLES[$mIdx]['color'];
                    if ($mRow >= 1 && $mRow <= $this->termHeight && $mCol >= 1 && $mCol <= $this->termWidth) {
                        echo Theme::moveTo($mRow, $mCol).Theme::rgb($mr, $mg, $mb)
                            .self::ROLES[$mIdx]['glyph'].$r;
                        $this->prevCells[] = ['row' => $mRow, 'col' => $mCol];
                    }
                }

                $progress = ($step + 1) / $teleportSteps;

                // Draw converging scatter particles
                foreach ($scatterParticles as $sp) {
                    $currentDist = $sp['dist'] * (1.0 - $progress);
                    $pCol = $targetCol + (int) ($currentDist * cos($sp['angle']));
                    $pRow = $targetRow + (int) ($currentDist * 0.5 * sin($sp['angle']));

                    if ($pRow >= 1 && $pRow <= $this->termHeight && $pCol >= 1 && $pCol <= $this->termWidth) {
                        $brightness = 0.3 + 0.7 * $progress;
                        $pr2 = (int) ($cr * $brightness);
                        $pg = (int) ($cg * $brightness);
                        $pb = (int) ($cb * $brightness);
                        $char = self::TELEPORT_CHARS[array_rand(self::TELEPORT_CHARS)];
                        echo Theme::moveTo($pRow, $pCol).Theme::rgb($pr2, $pg, $pb).$char.$r;
                        $this->prevCells[] = ['row' => $pRow, 'col' => $pCol];
                    }
                }

                // In final steps, show the figure glyph fading in
                if ($progress > 0.6) {
                    $glyphBrightness = ($progress - 0.6) / 0.4;
                    if ($targetRow >= 1 && $targetRow <= $this->termHeight && $targetCol >= 1 && $targetCol <= $this->termWidth) {
                        $gr = (int) ($cr * $glyphBrightness);
                        $gg = (int) ($cg * $glyphBrightness);
                        $gb = (int) ($cb * $glyphBrightness);
                        echo Theme::moveTo($targetRow, $targetCol).Theme::rgb($gr, $gg, $gb).$glyph.$r;
                        $this->prevCells[] = ['row' => $targetRow, 'col' => $targetCol];
                    }
                }

                usleep(25000);
            }

            $materialized[] = $figIdx;
        }
    }

    /**
     * Phase 2 — Formation.
     *
     * Figures move from spawn positions into a V-formation. Motion trails
     * in their respective colors. ~0.7s.
     */
    private function phaseFormation(): void
    {
        $r = Theme::reset();
        $spawnPositions = $this->getSpawnPositions();
        $targetPositions = $this->getFormationPositions();
        $totalSteps = 18;

        /** @var array<string, array{char: string, color: array{int, int, int}, age: int}> */
        $trails = [];

        for ($step = 0; $step < $totalSteps; $step++) {
            // Erase previous frame
            foreach ($this->prevCells as ['row' => $pr, 'col' => $pc]) {
                if ($pr >= 1 && $pr <= $this->termHeight && $pc >= 1 && $pc < $this->termWidth) {
                    echo Theme::moveTo($pr, $pc).' ';
                }
            }
            $this->prevCells = [];

            $progress = ($step + 1) / $totalSteps;
            // Ease out: decelerating approach to final position
            $eased = 1.0 - (1.0 - $progress) * (1.0 - $progress);

            // Draw fading trails
            foreach ($trails as $key => $trail) {
                $trail['age']++;
                $trails[$key] = $trail;
                if ($trail['age'] > 3) {
                    unset($trails[$key]);

                    continue;
                }
                [$tRow, $tCol] = explode(',', $key);
                $tRow = (int) $tRow;
                $tCol = (int) $tCol;
                if ($tCol >= 1 && $tCol <= $this->termWidth && $tRow >= 1 && $tRow <= $this->termHeight) {
                    $fade = max(0.1, 1.0 - $trail['age'] * 0.35);
                    [$tcr, $tcg, $tcb] = $trail['color'];
                    echo Theme::moveTo($tRow, $tCol)
                        .Theme::rgb((int) ($tcr * $fade), (int) ($tcg * $fade), (int) ($tcb * $fade))
                        .'·'.$r;
                    $this->prevCells[] = ['row' => $tRow, 'col' => $tCol];
                }
            }

            // Draw each figure moving to formation
            for ($i = 0; $i < 5; $i++) {
                [$sRow, $sCol] = $spawnPositions[$i];
                [$tRow, $tCol] = $targetPositions[$i];
                [$cr, $cg, $cb] = self::ROLES[$i]['color'];

                $curRow = (int) ($sRow + ($tRow - $sRow) * $eased);
                $curCol = (int) ($sCol + ($tCol - $sCol) * $eased);

                // Leave trail
                $trails["{$curRow},{$curCol}"] = ['char' => '·', 'color' => [$cr, $cg, $cb], 'age' => 0];

                if ($curRow >= 1 && $curRow <= $this->termHeight && $curCol >= 1 && $curCol <= $this->termWidth) {
                    echo Theme::moveTo($curRow, $curCol)
                        .Theme::rgb($cr, $cg, $cb)
                        .self::ROLES[$i]['glyph'].$r;
                    $this->prevCells[] = ['row' => $curRow, 'col' => $curCol];
                }
            }

            usleep(40000);
        }
    }

    /**
     * Phase 3 — Labels.
     *
     * Role labels appear below each figure, typing out letter by letter.
     * ~0.6s.
     */
    private function phaseLabels(): void
    {
        $r = Theme::reset();
        $positions = $this->getFormationPositions();

        // Draw all figures at final positions first
        for ($i = 0; $i < 5; $i++) {
            [$row, $col] = $positions[$i];
            [$cr, $cg, $cb] = self::ROLES[$i]['color'];
            if ($row >= 1 && $row <= $this->termHeight && $col >= 1 && $col <= $this->termWidth) {
                echo Theme::moveTo($row, $col).Theme::rgb($cr, $cg, $cb)
                    .self::ROLES[$i]['glyph'].$r;
                $this->prevCells[] = ['row' => $row, 'col' => $col];
            }
        }

        // Type out each label letter by letter
        for ($i = 0; $i < 5; $i++) {
            [$row, $col] = $positions[$i];
            [$cr, $cg, $cb] = self::ROLES[$i]['color'];
            $label = self::ROLES[$i]['label'];
            $labelLen = mb_strlen($label);
            $labelCol = $col - (int) ($labelLen / 2);
            $labelRow = $row + 2;

            $chars = mb_str_split($label);
            foreach ($chars as $cIdx => $char) {
                $charCol = $labelCol + $cIdx;
                if ($labelRow >= 1 && $labelRow <= $this->termHeight && $charCol >= 1 && $charCol <= $this->termWidth) {
                    echo Theme::moveTo($labelRow, $charCol).Theme::rgb($cr, $cg, $cb).$char.$r;
                    $this->prevCells[] = ['row' => $labelRow, 'col' => $charCol];
                }
                usleep(22000);
            }

            usleep(40000);
        }
    }

    /**
     * Phase 4 — Title reveal.
     *
     * "T E A M" fades in white above the formation. Subtitle below.
     * ~0.7s.
     */
    private function phaseTitle(): void
    {
        $r = Theme::reset();
        $positions = $this->getFormationPositions();

        $title = 'T E A M';
        $subtitle = '⚔ Squad assembled ⚔';
        $titleLen = mb_strwidth($title);
        $subLen = mb_strwidth($subtitle);
        $titleCol = max(1, (int) (($this->termWidth - $titleLen) / 2));
        $subCol = max(1, (int) (($this->termWidth - $subLen) / 2));

        // Title row: well above the V-formation
        $topOfV = $positions[0][0]; // Planner is at the point
        $titleRow = max(1, $topOfV - 5);
        $subRow = $titleRow + 2;

        // Fade in through white gradient
        $gradient = [
            [30, 30, 35],
            [60, 60, 70],
            [100, 100, 115],
            [140, 140, 155],
            [180, 180, 195],
            [210, 210, 225],
            [235, 235, 245],
            [255, 255, 255],
        ];

        foreach ($gradient as [$rv, $gv, $bv]) {
            echo Theme::moveTo($titleRow, $titleCol)
                .Theme::rgb($rv, $gv, $bv).$title.$r;
            usleep(40000);
        }

        // Subtitle typeout in dim white
        usleep(100000);
        $subtitleColor = Theme::rgb(180, 180, 200);
        echo Theme::moveTo($subRow, $subCol);
        foreach (mb_str_split($subtitle) as $char) {
            echo $subtitleColor.$char.$r;
            usleep(22000);
        }

        usleep(500000);
    }
}

<?php
$logo = [
    '██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗ ████████╗ ██████╗ ██████╗ ',
    '██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗╚══██╔══╝██╔═══██╗██╔══██╗',
    '█████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║   ██║   ██║   ██║██████╔╝',
    '██╔═██╗ ██║   ██║╚════██║██║╚██╔╝██║██║   ██║██╔═██╗ ██╔══██╗██╔══██║   ██║   ██║   ██║██╔══██╗',
    '██║  ██╗╚██████╔╝███████║██║ ╚═╝ ██║╚██████╔╝██║  ██╗██║  ██║██║  ██║   ██║   ╚██████╔╝██║  ██║',
    '╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝    ╚═════╝ ╚═╝  ╚═╝',
];

$cellW = 9;
$cellH = 17;
$logoW = 95 * $cellW;
$logoH = 6 * $cellH;
$startX = (1200 - $logoW) / 2;
$startY = (630 - $logoH) / 2 - 30; // nudge up to leave room for text below

$rects = '';
foreach ($logo as $row => $line) {
    foreach (mb_str_split($line) as $col => $char) {
        if ($char === ' ') continue;
        $x = $startX + ($col * $cellW);
        $y = $startY + ($row * $cellH);
        if ($char === '█') {
            $rects .= sprintf('    <rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#dc143c" rx="0.6"/>' . "\n",
                $x, $y, $cellW - 0.6, $cellH - 0.6);
        } else {
            $rects .= sprintf('    <rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#dc143c" opacity="0.5" rx="0.4"/>' . "\n",
                $x + 1, $y + 1, $cellW - 2.5, $cellH - 2.5);
        }
    }
}
$logoBottom = $startY + $logoH;
?>
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
  <defs>
    <radialGradient id="g" cx="50%" cy="45%" r="50%">
      <stop offset="0%" stop-color="#dc143c" stop-opacity="0.1"/>
      <stop offset="100%" stop-color="transparent"/>
    </radialGradient>
  </defs>

  <rect width="1200" height="630" fill="#07070d"/>
  <ellipse cx="600" cy="<?= $startY + $logoH/2 ?>" rx="500" ry="180" fill="url(#g)"/>

  <!-- Stars -->
  <circle cx="80" cy="40" r="1.2" fill="white" opacity="0.4"/>
  <circle cx="250" cy="580" r="0.8" fill="white" opacity="0.3"/>
  <circle cx="420" cy="50" r="1" fill="white" opacity="0.5"/>
  <circle cx="720" cy="35" r="1.3" fill="white" opacity="0.35"/>
  <circle cx="1020" cy="30" r="1.1" fill="white" opacity="0.3"/>
  <circle cx="1130" cy="590" r="0.7" fill="white" opacity="0.4"/>
  <circle cx="950" cy="600" r="0.9" fill="white" opacity="0.35"/>

  <g><?= $rects ?></g>

  <text x="600" y="<?= $logoBottom + 40 ?>" text-anchor="middle" font-family="JetBrains Mono, Menlo, Consolas, monospace" font-size="16" fill="rgba(240,240,245,0.32)">PHP 8.4  &#183;  ~50MB RAM  &#183;  40+ providers  &#183;  parallel subagent swarms</text>

  <rect x="460" y="<?= $logoBottom + 60 ?>" width="280" height="24" rx="6" fill="rgba(220,20,60,0.1)" stroke="rgba(220,20,60,0.18)" stroke-width="1"/>
  <text x="600" y="<?= $logoBottom + 77 ?>" text-anchor="middle" font-family="JetBrains Mono, Menlo, Consolas, monospace" font-size="12" fill="#ff2d55">open source  |  MIT license</text>
</svg>

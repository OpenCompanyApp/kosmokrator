# Render Performance Benchmarking

> **Module**: `13-architecture`  
> **Depends on**: `02-widget-compaction` (widget eviction affects render load)  
> **Status**: Plan

---

## Problem

The TUI render pipeline has no built-in performance measurement. As conversations grow (50+ messages, subagent trees, streaming), render time may exceed the 16ms budget for 60fps. Without instrumentation, regressions are invisible until users notice lag. There is no way to:

- Know how long each render phase takes (style resolution, layout, widget render, diff, write)
- Track frame rates during real usage
- Detect when a code change makes rendering slower
- Compare performance across scenarios (empty vs 200 messages vs streaming)

**Target metrics**:
- < 16ms per frame (60fps) for full renders
- < 5ms for incremental/differential updates (streaming, animation ticks)
- < 1ms for no-op renders (nothing changed)

---

## 2. Render Pipeline Analysis

### 2.1 The Full Render Path

A single frame flows through these phases:

```
TuiCoreRenderer::flushRender()
  → Tui::requestRender()          [flag + refreshLoopDriver]
  → Tui::processRender()          [triggered by AdaptativeTicker]
      → Renderer::render()        [PHASE 1-4: widget tree → lines]
          → Style resolution      [PHASE 1: StyleSheet::resolve() per widget]
          → Layout computation    [PHASE 2: LayoutEngine::layout()]
          → Widget render         [PHASE 3: widget->render() + ChromeApplier]
      → ScreenWriter::writeLines()[PHASE 5: lines → terminal diff]
          → prepareLines()        [diff detection, cursor parsing]
          → differentialRender()  [only changed lines written]
```

### 2.2 Key Measurement Points

| Phase | Location | What it does | Cost driver |
|-------|----------|-------------|-------------|
| **Style resolution** | `Renderer::resolveStyle()` (line 188) | Cascading style lookup per widget | Widget count × stylesheet complexity |
| **Layout** | `LayoutEngine::layout()` | Distribute space among children | Widget count × nesting depth |
| **Widget render** | `Renderer::renderWidget()` (line 121) | Call `widget->render()` + chrome | Content size (markdown, ANSI) |
| **Diff** | `ScreenWriter::prepareLines()` (line 441) | Line-by-line comparison, cursor parsing | Total line count |
| **Write** | `ScreenWriter::differentialRender()` (line 339) | ANSI output to terminal | Changed line count |

### 2.3 Cost Scaling

| Scenario | Widgets | Rendered Lines | Expected Time |
|----------|---------|---------------|---------------|
| Empty conversation | ~8 | ~10 | < 1ms |
| 10 messages | ~40 | ~80 | 1–3ms |
| 50 messages | ~200 | ~400 | 3–8ms |
| 200 messages | ~800 | ~2000 | 8–20ms ⚠️ |
| Streaming tick (1 widget) | ~200 | ~400 | 1–3ms |
| 5 subagents active | ~250 | ~500 | 4–10ms |

The widget render cache (`AbstractWidget::getRenderCache()`) helps: unchanged widgets return cached output. But style resolution still runs for every widget during layout filtering (`renderContainer` line 259–260), and `prepareLines()` compares every line.

---

## 3. Architecture

### 3.1 RenderProfiler

**File**: `src/UI/Tui/Profiler/RenderProfiler.php`

A stateful profiler that wraps the render pipeline with `hrtime(true)` measurements. Tracks timing per phase with nanosecond precision.

```php
namespace Kosmokrator\UI\Tui\Profiler;

/**
 * Measures time spent in each render phase.
 *
 * Usage:
 *   $profiler->beginFrame();
 *   $profiler->measure('style', fn() => $this->resolveStyle($widget));
 *   $profiler->endFrame();
 *   $stats = $profiler->getFrameStats();
 */
final class RenderProfiler
{
    private bool $enabled = false;
    private ?int $frameStart = null;
    private array $phases = [];
    private int $totalFrames = 0;

    public function enable(): void { $this->enabled = true; }
    public function disable(): void { $this->enabled = false; }
    public function isEnabled(): bool { return $this->enabled; }

    /** Begin a new frame measurement. */
    public function beginFrame(): void
    {
        if (!$this->enabled) return;
        $this->frameStart = hrtime(true);
        $this->phases = [];
    }

    /**
     * Measure a callable as a named phase.
     *
     * @template T
     * @param string $phase Phase name (e.g., 'style', 'layout', 'render', 'diff', 'write')
     * @param callable(): T $callable
     * @return T
     */
    public function measure(string $phase, callable $callable): mixed
    {
        if (!$this->enabled) return $callable();

        $start = hrtime(true);
        try {
            return $callable();
        } finally {
            $this->phases[$phase] = ($this->phases[$phase] ?? 0) + (hrtime(true) - $start);
        }
    }

    /** End the frame and record stats. */
    public function endFrame(): void
    {
        if (!$this->enabled || $this->frameStart === null) return;
        $total = hrtime(true) - $this->frameStart;
        $this->totalFrames++;
        $this->frameHistory[] = new FrameMeasurement(
            totalNs: $total,
            phases: $this->phases,
            timestamp: microtime(true),
        );
        // Keep last 300 frames (~5 seconds at 60fps)
        if (count($this->frameHistory) > 300) {
            array_shift($this->frameHistory);
        }
        $this->frameStart = null;
    }

    /** Get aggregated frame stats for the overlay. */
    public function getStats(): ProfilerStats { /* ... */ }
}
```

**Key decisions**:
- Nanosecond precision via `hrtime(true)` — `microtime(true)` has ~1μs granularity which is too coarse for sub-5ms frames
- Rolling window of 300 frames to keep memory bounded
- `measure()` returns the callable's result, so it can wrap existing code transparently
- Disabled by default, zero overhead when off (early return before callable invocation)

### 3.2 FrameMeasurement & ProfilerStats

**File**: `src/UI/Tui/Profiler/FrameMeasurement.php`

```php
namespace Kosmokrator\UI\Tui\Profiler;

final readonly class FrameMeasurement
{
    public function __construct(
        public int $totalNs,
        public array $phases,  // [string => int] phase name → nanoseconds
        public float $timestamp,
    ) {}

    public function totalMs(): float { return $this->totalNs / 1_000_000; }
    public function phaseMs(string $phase): float { return ($this->phases[$phase] ?? 0) / 1_000_000; }
}
```

**File**: `src/UI/Tui/Profiler/ProfilerStats.php`

```php
namespace Kosmokrator\UI\Tui\Profiler;

/** Aggregated stats computed from the frame history window. */
final readonly class ProfilerStats
{
    public function __construct(
        public float $fps,
        public float $avgFrameMs,
        public float $p95FrameMs,
        public float $maxFrameMs,
        public float $avgStyleMs,
        public float $avgLayoutMs,
        public float $avgRenderMs,
        public float $avgDiffMs,
        public float $avgWriteMs,
        public int $frameCount,
        public int $totalFramesSinceStart,
        public int $widgetCount,
        public int $lineCount,
    ) {}
}
```

### 3.3 Instrumentation Points

The profiler is integrated at two levels:

#### 3.3.1 Symfony Tui class (vendor-level)

Since `Tui::processRender()` is in vendor code, we cannot modify it directly. Instead, we instrument at the **KosmoKrator boundary**:

```php
// TuiCoreRenderer::flushRender() — current code
public function flushRender(): void
{
    $this->tui->requestRender();
    $this->tui->processRender();
}

// TuiCoreRenderer::flushRender() — with profiler
public function flushRender(): void
{
    $profiler = $this->profiler; // injected RenderProfiler
    $profiler->beginFrame();

    $profiler->measure('request', fn() => $this->tui->requestRender());
    $profiler->measure('process', fn() => $this->tui->processRender());

    $profiler->endFrame();
}
```

This gives us coarse timing. For phase-level breakdown, we need to hook deeper.

#### 3.3.2 Decorated Renderer (phase-level)

Create a `ProfilingRenderer` decorator that wraps the Symfony `Renderer`:

**File**: `src/UI/Tui/Profiler/ProfilingRenderer.php`

```php
namespace Kosmokrator\UI\Tui\Profiler;

use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRendererInterface;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Decorator around Renderer that measures each render phase.
 *
 * Injected via Tui constructor's ?Renderer parameter.
 */
final class ProfilingRenderer implements WidgetRendererInterface
{
    public function __construct(
        private readonly Renderer $inner,
        private readonly RenderProfiler $profiler,
    ) {}

    public function render(ContainerWidget $root, int $columns, int $rows): array
    {
        $this->profiler->beginFrame();

        $result = $this->profiler->measure('render_full', fn() =>
            $this->inner->render($root, $columns, $rows)
        );

        $this->profiler->endFrame();
        return $result;
    }

    public function renderWidget(AbstractWidget $widget, RenderContext $context): array
    {
        return $this->inner->renderWidget($widget, $context);
    }
}
```

**Problem**: The Renderer's internal phases (style, layout, chrome) are private methods. We cannot instrument them without modifying vendor code.

**Solution**: Rather than decorating the Renderer, we use a **subclass approach** in our namespace:

**File**: `src/UI/Tui/Profiler/InstrumentedRenderer.php`

```php
namespace Kosmokrator\UI\Tui\Profiler;

use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Style\Style;

/**
 * Extends Renderer to inject timing probes into each render phase.
 *
 * This is the only place we extend a vendor class for profiling.
 * When the upstream Renderer changes, these overrides must be updated.
 */
final class InstrumentedRenderer extends Renderer
{
    private RenderProfiler $profiler;
    private int $widgetCount = 0;
    private int $lineCount = 0;

    public function setProfiler(RenderProfiler $profiler): void
    {
        $this->profiler = $profiler;
    }

    public function render(ContainerWidget $root, int $columns, int $rows): array
    {
        $this->widgetCount = 0;
        $this->lineCount = 0;

        $this->profiler->beginFrame();

        // Parent::render() internally calls renderWidget() for each widget,
        // which calls resolveStyle() — all of which we override below
        $result = parent::render($root, $columns, $rows);

        $this->lineCount = count($result);
        $this->profiler->endFrame();
        return $result;
    }

    public function renderWidget(AbstractWidget $widget, RenderContext $context): array
    {
        $this->widgetCount++;
        // Count style resolution time
        return $this->profiler->measure('widget', fn() =>
            parent::renderWidget($widget, $context)
        );
    }

    public function resolveStyle(AbstractWidget $widget): Style
    {
        return $this->profiler->measure('style', fn() =>
            parent::resolveStyle($widget)
        );
    }

    public function getWidgetCount(): int { return $this->widgetCount; }
    public function getLineCount(): int { return $this->lineCount; }
}
```

This gives us phase-level timing by overriding the key methods. The cost is maintaining these overrides when the vendor Renderer changes — but Renderer is stable and the override surface is small (3 methods).

#### 3.3.3 ScreenWriter Profiling

For diff/write timing, we wrap the `ScreenWriter` similarly:

**File**: `src/UI/Tui/Profiler/InstrumentedScreenWriter.php`

```php
namespace Kosmokrator\UI\Tui\Profiler;

use Symfony\Component\Tui\Render\ScreenWriter;
use Symfony\Component\Tui\Terminal\TerminalInterface;

final class InstrumentedScreenWriter extends ScreenWriter
{
    private RenderProfiler $profiler;

    public function __construct(
        TerminalInterface $terminal,
    ) {
        parent::__construct($terminal);
    }

    public function setProfiler(RenderProfiler $profiler): void
    {
        $this->profiler = $profiler;
    }

    public function writeLines(array $lines): void
    {
        $this->profiler->measure('diff_write', fn() => parent::writeLines($lines));
    }
}
```

### 3.4 Integration with TuiCoreRenderer

**File**: `src/UI/Tui/TuiCoreRenderer.php` (modifications)

```php
// New property
private RenderProfiler $profiler;

// In initialize():
public function initialize(): void
{
    $this->profiler = new RenderProfiler();

    $renderer = new InstrumentedRenderer(KosmokratorStyleSheet::create());
    $renderer->setProfiler($this->profiler);

    $screenWriter = new InstrumentedScreenWriter(new Terminal());
    $screenWriter->setProfiler($this->profiler);

    $this->tui = new Tui(
        styleSheet: KosmokratorStyleSheet::create(),
        renderer: $renderer,
        screenWriter: $screenWriter,
    );
    // ... rest of initialization
}
```

The profiler is disabled by default. Enable via:
- Environment variable: `KOSMOKRATOR_PROFILER=1`
- Runtime toggle: keybinding (e.g., `Ctrl+Shift+P`)

---

## 4. Performance Overlay

### 4.1 Overlay Widget

**File**: `src/UI/Tui/Profiler/ProfilerOverlay.php`

A lightweight widget that renders a semi-transparent stats panel in the top-right corner of the terminal. Modeled after game FPS counters.

```
┌─ Perf ──────────────────┐
│ FPS: 62  Avg: 4.2ms     │
│ P95: 8.1ms  Max: 12.3ms │
│ Style: 0.8ms  Layout: 0.3ms │
│ Render: 2.1ms  Diff: 0.5ms │
│ Write: 0.4ms              │
│ Widgets: 203  Lines: 412 │
│ Frames: 1,247            │
└──────────────────────────┘
```

```php
namespace Kosmokrator\UI\Tui\Profiler;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class ProfilerOverlay extends AbstractWidget
{
    private bool $visible = false;

    public function __construct(
        private readonly RenderProfiler $profiler,
    ) {
        $this->setId('profiler-overlay');
    }

    public function toggle(): void
    {
        $this->visible = !$this->visible;
        $this->invalidate();
    }

    public function isVisible(): bool { return $this->visible; }

    public function render(RenderContext $context): array
    {
        if (!$this->visible) return [];

        $stats = $this->profiler->getStats();
        $lines = $this->formatStats($stats, $context->getColumns());

        return $lines;
    }

    private function formatStats(ProfilerStats $stats, int $columns): array
    {
        $fpsColor = $stats->fps >= 55 ? "\033[38;2;80;200;120m"   // green
                  : ($stats->fps >= 30 ? "\033[38;2;255;180;60m"   // orange
                  : "\033[38;2;255;80;60m");                        // red

        $r = "\033[0m";
        $dim = "\033[38;2;140;140;160m";
        $bg = "\033[48;2;20;20;30m";

        // ... format each line with ANSI colors
        return $lines;
    }
}
```

### 4.2 Overlay Integration

The overlay sits in the `overlay` ContainerWidget (already exists in TuiCoreRenderer) and is positioned absolutely via CSS class.

```php
// In TuiCoreRenderer::initialize():
$this->profilerOverlay = new ProfilerOverlay($this->profiler);
$this->profilerOverlay->addStyleClass('profiler-overlay');
$this->overlay->add($this->profilerOverlay);
```

**Stylesheet additions** (`KosmokratorStyleSheet`):

```css
#profiler-overlay {
    position: absolute;
    top: 0;
    right: 0;
    background: rgba(20, 20, 30, 0.85);
    padding: 0 1;
}
```

Or via PHP stylesheet rules:

```php
// In KosmokratorStyleSheet
'#profiler-overlay' => Style::create()
    ->withBackground("\033[48;2;20;20;30m")
    ->withPadding(new Padding(0, 1, 0, 1)),
```

### 4.3 Toggle Keybinding

Add `Ctrl+Shift+P` (or `F12`) to toggle the profiler overlay:

```php
// In TuiInputHandler::bind():
$this->input->on('ctrl+shift+p', function () {
    $this->profiler->toggle(); // enables/disables profiling
    $this->profilerOverlay->toggle();
    ($this->flushRender)();
});
```

The profiler only collects data when enabled. Toggling the overlay on enables profiling; toggling off disables it and clears history.

---

## 5. Benchmark Scenarios

### 5.1 Benchmark Runner

**File**: `src/UI/Tui/Profiler/BenchmarkRunner.php`

Runs predefined scenarios in a headless terminal (virtual terminal) and reports timing.

```php
namespace Kosmokrator\UI\Tui\Profiler;

/**
 * Runs render benchmarks against a virtual terminal.
 *
 * Usage:
 *   $runner = new BenchmarkRunner();
 *   $results = $runner->runAll();
 *   echo $runner->formatReport($results);
 *
 * Or via CLI:
 *   php bin/kosmokrator benchmark:render
 */
final class BenchmarkRunner
{
    private const TERMINAL_WIDTH = 120;
    private const TERMINAL_HEIGHT = 40;
    private const WARMUP_FRAMES = 10;
    private const MEASURED_FRAMES = 100;

    /**
     * @return BenchmarkResult[]
     */
    public function runAll(): array
    {
        return [
            $this->runScenario('empty', new EmptyScenario()),
            $this->runScenario('10_messages', new TenMessagesScenario()),
            $this->runScenario('50_messages', new FiftyMessagesScenario()),
            $this->runScenario('200_messages', new TwoHundredMessagesScenario()),
            $this->runScenario('streaming_tick', new StreamingTickScenario()),
            $this->runScenario('5_subagents', new FiveSubagentsScenario()),
            $this->runScenario('resize', new ResizeScenario()),
        ];
    }

    private function runScenario(string $name, BenchmarkScenario $scenario): BenchmarkResult
    {
        // Create a virtual terminal (no real I/O)
        $terminal = new VirtualTerminal(self::TERMINAL_WIDTH, self::TERMINAL_HEIGHT);
        $profiler = new RenderProfiler();
        $profiler->enable();

        $renderer = new InstrumentedRenderer(/* ... */);
        $renderer->setProfiler($profiler);
        $screenWriter = new InstrumentedScreenWriter($terminal);
        $screenWriter->setProfiler($profiler);

        $tui = new Tui(
            renderer: $renderer,
            screenWriter: $screenWriter,
            terminal: $terminal,
        );

        // Set up scenario (add widgets, set state)
        $scenario->setup($tui, /* coreRenderer mock */);

        // Warmup
        for ($i = 0; $i < self::WARMUP_FRAMES; $i++) {
            $tui->requestRender();
            $tui->processRender();
        }

        // Measure
        $profiler->reset();
        $start = hrtime(true);
        for ($i = 0; $i < self::MEASURED_FRAMES; $i++) {
            $scenario->tick($tui, $i); // simulate state changes
            $tui->requestRender();
            $tui->processRender();
        }
        $totalNs = hrtime(true) - $start;

        $scenario->teardown($tui);

        return new BenchmarkResult(
            name: $name,
            totalMs: $totalNs / 1_000_000,
            frames: self::MEASURED_FRAMES,
            avgFrameMs: ($totalNs / self::MEASURED_FRAMES) / 1_000_000,
            stats: $profiler->getStats(),
        );
    }
}
```

### 5.2 Scenario Definitions

**File**: `src/UI/Tui/Profiler/Scenario/BenchmarkScenario.php`

```php
namespace Kosmokrator\UI\Tui\Profiler\Scenario;

interface BenchmarkScenario
{
    /** Set up the TUI with initial widget state. */
    public function setup(Tui $tui, object $coreRenderer): void;

    /** Optional per-frame state mutation (e.g., streaming append). */
    public function tick(Tui $tui, int $frame): void {}

    /** Clean up. */
    public function teardown(Tui $tui): void {}
}
```

#### Scenario: Empty Conversation

```php
// Scenario/EmptyScenario.php
final class EmptyScenario implements BenchmarkScenario
{
    public function setup(Tui $tui): void
    {
        // Just the default session layout: status bar, input, no messages
    }
}
```

#### Scenario: N Messages

```php
// Scenario/FiftyMessagesScenario.php
final class FiftyMessagesScenario implements BenchmarkScenario
{
    public function setup(Tui $tui): void
    {
        $root = $tui->getById('session');
        $conversation = $root->findById('conversation');

        // Add 25 user + 25 response widgets with realistic content
        for ($i = 0; $i < 25; $i++) {
            $user = new TextWidget("⟡ User question {$i} with some detail...");
            $user->addStyleClass('user-message');
            $conversation->add($user);

            $response = new MarkdownWidget($this->generateResponse($i));
            $response->addStyleClass('response');
            $conversation->add($response);
        }
    }

    private function generateResponse(int $i): string
    {
        // ~200 words of markdown (realistic response)
        return str_repeat("Here is **answer** {$i} with `code` and [links](url).\n\n", 10);
    }
}
```

#### Scenario: Streaming Tick

```php
// Scenario/StreamingTickScenario.php
final class StreamingTickScenario implements BenchmarkScenario
{
    private MarkdownWidget $streaming;

    public function setup(Tui $tui): void
    {
        // Pre-populate 20 messages
        // ...

        // Add streaming widget
        $this->streaming = new MarkdownWidget('');
        $this->streaming->addStyleClass('response');
        $conversation->add($this->streaming);
    }

    public function tick(Tui $tui, int $frame): void
    {
        // Append a few words each frame (simulates streaming)
        $current = $this->streaming->getText();
        $this->streaming->setText($current . "Additional streamed content. ");
    }
}
```

#### Scenario: Subagents

```php
// Scenario/FiveSubagentsScenario.php
final class FiveSubagentsScenario implements BenchmarkScenario
{
    public function setup(Tui $tui): void
    {
        // Pre-populate 10 messages
        // Add 5 subagent display trees with spinners
        // Each subagent has 3-5 child tasks
    }
}
```

### 5.3 CLI Command

**File**: `src/Command/BenchmarkRenderCommand.php`

```php
namespace Kosmokrator\Command;

use Kosmokrator\UI\Tui\Profiler\BenchmarkRunner;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('benchmark:render', 'Run render performance benchmarks')]
final class BenchmarkRenderCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = new BenchmarkRunner();
        $results = $runner->runAll();

        // Format and output results as table
        // Fail with exit code 1 if any scenario exceeds thresholds

        return $this->checkThresholds($results) ? 0 : 1;
    }
}
```

Output format:

```
KosmoKrator Render Benchmarks
═══════════════════════════════════════════════════════════════
Scenario              Avg (ms)   P95 (ms)   Max (ms)   FPS    Status
───────────────────────────────────────────────────────────────
empty                 0.4        0.6        0.8        2500   ✅
10_messages           1.2        1.8        2.1        833    ✅
50_messages           3.8        5.2        6.1        263    ✅
200_messages          12.4       15.8       18.2       80     ✅
streaming_tick        1.9        2.4        3.1        526    ✅
5_subagents           5.3        7.1        8.8        188    ✅
resize                14.2       18.1       22.3       70     ⚠️
═══════════════════════════════════════════════════════════════

Thresholds: < 16ms avg, < 5ms incremental
⚠️ resize: avg exceeds soft threshold (14.2ms)
```

---

## 6. Regression Detection

### 6.1 CI Job

**File**: `.github/workflows/render-benchmark.yml`

```yaml
name: Render Performance
on:
  push:
    branches: [main, develop]
  pull_request:

jobs:
  benchmark:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install --no-interaction

      - name: Run render benchmarks
        run: php bin/kosmokrator benchmark:render --format=json > benchmark-results.json

      - name: Check thresholds
        run: |
          php bin/kosmokrator benchmark:check \
            --max-avg-full=16 \
            --max-avg-incremental=5 \
            --max-p95-full=20 \
            benchmark-results.json

      - name: Upload results
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: benchmark-results
          path: benchmark-results.json
```

### 6.2 Threshold Checker

**File**: `src/UI/Tui/Profiler/ThresholdChecker.php`

```php
namespace Kosmokrator\UI\Tui\Profiler;

final class ThresholdChecker
{
    public function __construct(
        private readonly float $maxAvgFullRender = 16.0,      // ms
        private readonly float $maxAvgIncremental = 5.0,       // ms
        private readonly float $maxP95FullRender = 20.0,       // ms
        private readonly float $maxP95Incremental = 8.0,       // ms
    ) {}

    /**
     * @param BenchmarkResult[] $results
     * @return ThresholdViolation[]
     */
    public function check(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $isIncremental = in_array($result->name, ['streaming_tick', 'empty'], true);
            $maxAvg = $isIncremental ? $this->maxAvgIncremental : $this->maxAvgFullRender;
            $maxP95 = $isIncremental ? $this->maxP95Incremental : $this->maxP95FullRender;

            if ($result->avgFrameMs > $maxAvg) {
                $violations[] = new ThresholdViolation(
                    scenario: $result->name,
                    metric: 'avg',
                    value: $result->avgFrameMs,
                    threshold: $maxAvg,
                );
            }
            if ($result->stats->p95FrameMs > $maxP95) {
                $violations[] = new ThresholdViolation(
                    scenario: $result->name,
                    metric: 'p95',
                    value: $result->stats->p95FrameMs,
                    threshold: $maxP95,
                );
            }
        }
        return $violations;
    }
}
```

### 6.3 Historical Tracking (Optional)

Store benchmark results as JSON artifacts. Over time, graph trends:

```
benchmark-results/
  2024-01-15_abc1234.json
  2024-01-16_def5678.json
  ...
```

A `benchmark:compare` command can diff two runs:

```
$ php bin/kosmokrator benchmark:compare HEAD~1 HEAD

Scenario         Before   After    Delta
50_messages      3.8ms    4.2ms    +10.5% ⚠️
streaming_tick   1.9ms    1.8ms    -5.3%  ✅
200_messages     12.4ms   14.1ms   +13.7% ❌
```

---

## 7. Integration with Animation Loop

### 7.1 Automatic Profiling in TuiAnimationManager

The `TuiAnimationManager` drives periodic renders via Revolt timers. Each animation tick should be profiled:

```php
// TuiAnimationManager — modified timer callbacks

private function startBreathTimer(): void
{
    $this->breathTimerId = EventLoop::repeat(0.08, function () {
        $this->profiler?->beginFrame();
        // ... existing breath animation logic
        $this->profiler?->endFrame();
    });
}
```

However, since the actual render happens in `TuiCoreRenderer::flushRender()` (which is called from the animation callback), the profiler is already active if enabled. The key is ensuring `beginFrame()` is called before `requestRender()` and `endFrame()` after `processRender()`.

### 7.2 Profiling-Friendly AdaptativeTicker

The `AdaptativeTicker` already adjusts tick intervals:
- Active: 10ms (100Hz)
- Idle: 250ms (4Hz)

When profiling is enabled, we can emit the current tick interval as part of the stats, helping understand whether the adaptive ticker is contributing to perceived lag.

---

## 8. File Structure

```
src/UI/Tui/Profiler/
├── RenderProfiler.php              # Core profiler: timing, frame history
├── FrameMeasurement.php            # Immutable frame data
├── ProfilerStats.php               # Aggregated stats
├── ProfilerOverlay.php             # In-terminal overlay widget
├── InstrumentedRenderer.php        # Renderer subclass with timing probes
├── InstrumentedScreenWriter.php    # ScreenWriter subclass with timing probes
├── BenchmarkRunner.php             # Scenario runner
├── BenchmarkResult.php             # Single scenario result
├── ThresholdChecker.php            # CI threshold validation
├── ThresholdViolation.php          # Threshold breach data
└── Scenario/
    ├── BenchmarkScenario.php       # Interface
    ├── EmptyScenario.php           # Empty conversation
    ├── TenMessagesScenario.php     # 10 messages
    ├── FiftyMessagesScenario.php   # 50 messages
    ├── TwoHundredMessagesScenario.php  # 200 messages
    ├── StreamingTickScenario.php   # Streaming simulation
    ├── FiveSubagentsScenario.php   # Subagent tree
    └── ResizeScenario.php          # Terminal resize stress test

src/Command/
├── BenchmarkRenderCommand.php      # CLI: benchmark:render
└── BenchmarkCheckCommand.php       # CLI: benchmark:check

.github/workflows/
└── render-benchmark.yml            # CI job
```

---

## 9. Implementation Order

### Phase 1: Core Profiling Infrastructure (est. 2-3 hours)

1. Create `RenderProfiler`, `FrameMeasurement`, `ProfilerStats`
2. Create `InstrumentedRenderer` and `InstrumentedScreenWriter`
3. Modify `TuiCoreRenderer::initialize()` to use instrumented classes
4. Add `KOSMOKRATOR_PROFILER=1` env var check to enable profiling
5. Verify overhead when disabled is < 0.1ms per frame

### Phase 2: Performance Overlay (est. 1-2 hours)

1. Create `ProfilerOverlay` widget
2. Integrate into `TuiCoreRenderer::initialize()` (overlay container)
3. Add toggle keybinding in `TuiInputHandler`
4. Add stylesheet rules for overlay positioning
5. Test: verify overlay renders correctly over content

### Phase 3: Benchmark Scenarios (est. 2-3 hours)

1. Create `BenchmarkScenario` interface
2. Implement all 7 scenarios
3. Create `BenchmarkRunner`
4. Create `BenchmarkRenderCommand`
5. Run benchmarks locally, establish baseline numbers

### Phase 4: CI Integration (est. 1 hour)

1. Create `ThresholdChecker` and `ThresholdViolation`
2. Create `BenchmarkCheckCommand`
3. Add GitHub Actions workflow
4. Set initial thresholds based on baseline measurements
5. Document thresholds in `docs/contributing/render-performance.md`

### Phase 5: Historical Tracking (optional, est. 1-2 hours)

1. Add JSON output format to `BenchmarkRenderCommand`
2. Create `BenchmarkCompareCommand`
3. Add artifact upload/download to CI workflow

---

## 10. Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Vendor Renderer changes break `InstrumentedRenderer` overrides | Build failure | Pin Symfony TUI version; overrides are only 3 methods, easy to update |
| Profiler overhead when enabled | Inaccurate measurements | Use `hrtime(true)` (monotonic); measure profiler overhead separately |
| Virtual terminal doesn't match real terminal | Benchmark results not representative | Test with real terminal too; virtual terminal provides consistent baseline |
| Overlay itself affects render time | Feedback loop | Exclude overlay widget from profiled measurements; measure separately |
| PHP 8.4 JIT changes timing | Non-deterministic results | Run with `opcache.jit=off` in CI for consistency; document this |

---

## 11. Success Criteria

1. **Profiler works**: Running with `KOSMOKRATOR_PROFILER=1` shows real-time stats overlay
2. **Benchmarks pass**: All scenarios complete under threshold on CI
3. **Regression detected**: A deliberate slowdown (e.g., disabling widget cache) causes CI failure
4. **Zero overhead when disabled**: No measurable performance impact with profiler off (< 0.1ms)
5. **Actionable data**: Phase-level timing identifies which render step is slow

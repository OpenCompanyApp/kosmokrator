# Reactive TUI Primitives

A declarative, signal-driven UI layer built on top of Symfony TUI. Replaces the current
imperative scatter pattern (`refreshStatusBar()` + `flushRender()` × 59) with SwiftUI-style
reactive composition.

## Table of Contents

1. [Why](#why)
2. [Architecture](#architecture)
3. [The Signal System (Already Landed)](#the-signal-system-already-landed)
4. [The Primitive Layer (Proposal)](#the-primitive-layer-proposal)
5. [Widget Catalog](#widget-catalog)
6. [Usage Examples](#usage-examples)
7. [Migration Path](#migration-path)
8. [Package Extraction](#package-extraction)
9. [Alternatives Considered](#alternatives-considered)

---

## Why

### Current pattern: imperative scatter

The existing TUI code uses **plain scalar properties + manual refresh calls**. Every state
mutation is a 3-step ritual:

```php
// Current pattern — repeated ~59 times across 6 files
$this->currentModeLabel = 'Edit';
$this->currentModeColor = "\033[...m";
$this->refreshStatusBar();  // reads all the scalars, rebuilds the bar
$this->flushRender();        // tells Symfony TUI to re-render
```

**Problems:**

1. **Coupling** — whoever changes a property must remember to call the right refresh(es).
   Forget one → stale UI. `refreshStatusBar()` is called 6 times, `flushRender()` ~51 times
   across 6 files.
2. **Over-rendering** — a single agent tick touches 5+ properties. Each `flushRender()`
   triggers a full re-render. There's no batching.
3. **No derived values** — things like "context window percentage" are computed inline
   wherever needed, with no caching or reactivity.
4. **Scattered state** — ~20 mutable properties on `TuiCoreRenderer`, more on
   `TuiAnimationManager`, `TuiToolRenderer`, `TuiModalManager`. No single source of truth.

### What signals give us

```php
// Signal pattern — state change propagates automatically
$this->modeLabel->set('Edit');
$this->modeColor->set("\033[...m");
// Status bar Effect auto-fires, render Effect auto-fires. Zero manual calls.

// Batching is explicit:
BatchScope::run(function () {
    $this->modeLabel->set('Edit');
    $this->tokensIn->set(42000);
    $this->cost->set(0.042);
    // One render, not three.
});
```

### The declarative layer on top

Signals solve state management. But we can go further — wrap Symfony TUI's widget API into
declarative primitives that compose like SwiftUI. The result: describe your UI tree once,
signals drive all updates.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│  KosmoKrator TUI                                                     │
│  Agent tree, tool renderer, toast overlay, conversation scroll,      │
│  permission prompts — app-specific compositions of primitives        │
├─────────────────────────────────────────────────────────────────────┤
│  Reactive TUI Primitives                                             │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │  Layout: Column, Row, Spacer, Conditional, Scroll             │  │
│  │  Display: Label, ContextMeter, PhaseIcon, Sep                 │  │
│  │  Input:   TextField, Button, KeyBinding                       │  │
│  │  Bridge:  ReactiveWidget, ReactiveBridge                      │  │
│  ├────────────────────────────────────────────────────────────────┤  │
│  │  Signal System  (already landed, zero Symfony TUI deps)       │  │
│  │  Signal, Computed, Effect, EffectScope, BatchScope             │  │
│  └────────────────────────────────────────────────────────────────┘  │
├─────────────────────────────────────────────────────────────────────┤
│  Symfony TUI  (vendor, unmodified)                                   │
│  AbstractWidget, ContainerWidget, TextWidget, Renderer,             │
│  DirtyWidgetTrait, Style, Direction                                  │
└─────────────────────────────────────────────────────────────────────┘
```

### Dependency flow

```
Signal (pure PHP + Revolt\EventLoop)
  ↑
ReactiveWidget (extends AbstractWidget, reads signals)
  ↑
Column / Row / Label / ... (extend ReactiveWidget or ContainerWidget)
  ↑
KosmoKrator compositions (StatusBar, ToolCard, AgentTree, ToastStack)
```

Signals have **no knowledge** of Symfony TUI. The primitive layer is the adapter.
KosmoKrator's specific UIs are compositions of primitives.

### No framework changes required

Symfony TUI already provides everything the primitive layer needs:

| What we need | What Symfony TUI provides |
|---|---|
| Dirty tracking | `DirtyWidgetTrait` — `invalidate()` + `renderRevision` |
| Selective re-render | `getRenderCache()` / `setRenderCache()` — skip unchanged widgets |
| State sync hook | `beforeRender()` — called every frame, even on cache hits |
| Frame scheduling | `requestRender()` — deferred render on next tick |
| Layout | `ContainerWidget` + `Style(direction: Direction::Horizontal, gap: 2)` |
| Styling | `Style` objects + stylesheet rules + CSS-style classes |

The bridge is two pieces:

1. **`ReactiveWidget::beforeRender()`** — reads bound signals, syncs into widget state,
   calls `invalidate()` if changed.
2. **`ReactiveBridge`** — one `Effect` that reads all display signals and calls
   `Tui::requestRender()` whenever any of them change.

---

## The Signal System (Already Landed)

Cherry-picked from `feat/tui` to `dev` as a standalone layer. 14 source files, 11 test files,
zero Symfony TUI dependencies.

### Files

```
src/UI/Tui/Signal/
├── Signal.php          Reactive value holder with version counter + auto-tracking
├── Computed.php        Lazy derived value with circular depth guard
├── Effect.php          Side-effect auto-runner with cleanup lifecycle
├── EffectScope.php     Static tracking context (stack-based)
├── BatchScope.php      Batches writes, deduplicates effects (Revolt\EventLoop)
└── Subscriber.php      Internal subscriber record
```

### Key concepts

**Signal** — a reactive value container. Reading inside a tracking scope auto-subscribes.
Writing notifies all subscribers (unless batched).

```php
$count = new Signal(0);

// Reading outside a tracking scope — no side effects
$count->get(); // 0

// Subscribe to changes
$count->subscribe(fn (int $v) => print "Count is now {$v}\n");
$count->set(1); // prints "Count is now 1"
$count->set(1); // no-op (identity check ===)
```

**Computed** — a lazily-evaluated derived value. Recomputes only when dependencies change.

```php
$width = new Signal(100);
$height = new Signal(50);
$area = new Computed(fn (): int => $width->get() * $height->get());

$area->get(); // 5000 — first evaluation
$width->set(200);
$area->get(); // 10000 — re-evaluated because $width changed
```

**Effect** — auto-runs a side effect whenever its dependencies change.

```php
$name = new Signal('world');

$effect = new Effect(function () use ($name): void {
    echo "Hello, {$name->get()}!\n";
});
// prints "Hello, world!" immediately

$name->set('KosmoKrator');
// prints "Hello, KosmoKrator!" automatically

$effect->dispose(); // stops tracking
```

**BatchScope** — coalesces multiple writes into a single notification round.

```php
$a = new Signal(1);
$b = new Signal(2);

new Effect(function () use ($a, $b): void {
    echo $a->get() + $b->get() . "\n";
});

BatchScope::run(function () use ($a, $b): void {
    $a->set(10);
    $b->set(20);
});
// Prints "30" once, not "3" then "30"
```

### Consumers also landed

```
src/UI/Tui/Phase/
├── Phase.php                      enum: Idle, Thinking, Tools, Compacting
├── PhaseStateMachine.php          transition rules + Signal<Phase> backing
└── InvalidTransitionException.php

src/UI/Tui/State/
└── TuiStateStore.php              11 signals + 1 computed for all UI state

src/UI/Tui/Toast/
├── ToastType.php                  enum: Success, Warning, Error, Info
├── ToastPhase.php                 enum: Entering, Visible, Exiting, Done
├── ToastItem.php                  per-toast Signal state (opacity, phase, offset)
└── ToastManager.php               singleton stack manager with Signal<list<ToastItem>>
```

These are dormant — no existing code uses them yet. They're ready for the primitive layer
to consume.

---

## The Primitive Layer (Proposal)

### ReactiveWidget — the bridge

The base class that connects signals to Symfony TUI's dirty tracking:

```php
namespace KosmoKrator\UI\Tui\Primitive;

use KosmoKrokrator\UI\Tui\Signal\Computed;
use KosmoKrator\UI\Tui\Signal\Signal;
use Symfony\Component\Tui\Widget\AbstractWidget;

abstract class ReactiveWidget extends AbstractWidget
{
    /** @var list<Signal|Computed> */
    private array $boundSignals = [];

    /**
     * Bind a signal to this widget. beforeRender() will check it each frame.
     */
    protected function bind(Signal|Computed $signal): static
    {
        $this->boundSignals[] = $signal;
        return $this;
    }

    /**
     * Called by the Renderer on every frame. Syncs signal state into widget
     * state. If syncFromSignals() returns true, calls invalidate() to bust
     * the render cache.
     */
    public function beforeRender(): void
    {
        if ($this->syncFromSignals()) {
            $this->invalidate();
        }
    }

    /**
     * Override: read bound signals, write widget state.
     * Return true if the widget needs re-rendering.
     */
    abstract protected function syncFromSignals(): bool;
}
```

### ReactiveBridge — the render driver

One `Effect` that watches all display signals and schedules renders:

```php
namespace KosmoKrator\UI\Tui\Primitive;

use KosmoKrator\UI\Tui\Signal\Effect;
use KosmoKrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Tui;

final class ReactiveBridge
{
    private ?Effect $renderEffect = null;

    /**
     * Start the reactive render loop.
     *
     * Reading each signal inside the Effect callback auto-tracks it.
     * When any tracked signal changes, the Effect re-runs and calls
     * requestRender() to schedule a new frame.
     */
    public function start(Tui $tui, TuiStateStore $store): void
    {
        $this->renderEffect = new Effect(function () use ($tui, $store): void {
            // Touch every display signal — this auto-tracks them all.
            // Any future set() on any of these re-runs this Effect.
            $store->modeLabelSignal()->get();
            $store->modeColorSignal()->get();
            $store->permissionLabelSignal()->get();
            $store->permissionColorSignal()->get();
            $store->statusDetailSignal()->get();
            $store->tokensInSignal()->get();
            $store->tokensOutSignal()->get();
            $store->costSignal()->get();
            $store->maxContextSignal()->get();
            $store->modelSignal()->get();
            $store->phaseSignal()->get();
            $store->scrollOffsetSignal()->get();
            $store->activeResponseSignal()->get();
            $store->spinnerIndexSignal()->get();
            $store->hasRunningAgentsSignal()->get();
            $store->hasTasksSignal()->get();
            $store->contextPercentComputed()->get();

            $tui->requestRender();
        });
    }

    public function stop(): void
    {
        $this->renderEffect?->dispose();
        $this->renderEffect = null;
    }
}
```

This replaces all 51 `flushRender()` / `requestRender()` calls with a single Effect.

---

## Widget Catalog

### Layout primitives

#### Column

Vertical stack of children. Wraps `ContainerWidget` with `Direction::Vertical`.

```php
final class Column extends ContainerWidget
{
    /**
     * @param  list<AbstractWidget>  $children
     * @param  string ...$classes  CSS-style class names for stylesheet rules
     */
    public static function make(
        int $gap = 0,
        array $children = [],
        array $classes = [],
    ): self {
        $col = (new self())
            ->setStyle(new Style(direction: Direction::Vertical, gap: $gap))
            ->setStyleClasses($classes);

        foreach ($children as $child) {
            $col->add($child);
        }

        return $col;
    }

    /**
     * Reactive column that rebuilds children from a Signal<list<T>>.
     *
     * @param  Signal<list<T>>  $items
     * @param  callable(T): AbstractWidget  $builder
     */
    public static function reactive(
        Signal $items,
        callable $builder,
        int $gap = 0,
    ): self {
        $col = self::make($gap);

        new Effect(function () use ($col, $items, $builder): void {
            $col->clear();
            foreach ($items->get() as $item) {
                $col->add($builder($item));
            }
        });

        return $col;
    }
}
```

#### Row

Horizontal stack of children. Wraps `ContainerWidget` with `Direction::Horizontal`.

```php
final class Row extends ContainerWidget
{
    public static function make(
        int $gap = 0,
        array $children = [],
        array $classes = [],
    ): self {
        $row = (new self())
            ->setStyle(new Style(direction: Direction::Horizontal, gap: $gap))
            ->setStyleClasses($classes);

        foreach ($children as $child) {
            $row->add($child);
        }

        return $row;
    }
}
```

#### Spacer

Eats remaining space in a flex container.

```php
final class Spacer extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $vertical = false;

    public static function flex(): self
    {
        return new self();
    }

    public static function vertical(): self
    {
        $s = new self();
        $s->vertical = true;
        return $s;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->vertical;
    }

    public function render(RenderContext $context): array
    {
        return array_fill(0, $context->getRows(), '');
    }
}
```

#### Conditional

Shows/hides a child based on a signal or computed boolean.

```php
final class Conditional extends ReactiveWidget
{
    private bool $lastVisible = false;

    private function __construct(
        private readonly Signal|Computed $condition,
        private readonly AbstractWidget $child,
    ) {
        $this->bind($condition);
    }

    public static function reactive(
        Signal|Computed $condition,
        AbstractWidget $child,
    ): self {
        return new self($condition, $child);
    }

    protected function syncFromSignals(): bool
    {
        $visible = (bool) $this->condition->get();
        if ($visible !== $this->lastVisible) {
            $this->lastVisible = $visible;
            return true;
        }
        return false;
    }

    public function render(RenderContext $context): array
    {
        if (!$this->lastVisible) {
            return [];
        }
        return $this->child->render($context);
    }
}
```

### Display primitives

#### Label

Text display. Either static or reactive (bound to a Signal/Computed).

```php
final class Label extends ReactiveWidget
{
    private string $text = '';
    private bool $truncate;

    private function __construct(
        private readonly Signal|Computed|string $source,
        bool $truncate = false,
    ) {
        $this->truncate = $truncate;

        if (is_string($source)) {
            $this->text = $source;
        } else {
            $this->bind($source);
            $this->text = (string) $source->get();
        }
    }

    /** Static text */
    public static function text(string $text, bool $truncate = false): self
    {
        return new self($text, $truncate);
    }

    /** Auto-updating text bound to a Signal or Computed */
    public static function reactive(
        Signal|Computed $source,
        bool $truncate = false,
    ): self {
        return new self($source, $truncate);
    }

    protected function syncFromSignals(): bool
    {
        if (is_string($this->source)) {
            return false;
        }
        $new = (string) $this->source->get();
        if ($this->text === $new) {
            return false;
        }
        $this->text = $new;
        return true;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function render(RenderContext $context): array
    {
        if ('' === $this->text || '' === trim($this->text)) {
            return [];
        }
        $cols = $context->getColumns();
        if ($this->truncate) {
            return [AnsiUtils::truncateToWidth($this->text, $cols)];
        }
        return TextWrapper::wrapTextWithAnsi($this->text, $cols);
    }
}
```

#### Sep

Visual separator — pipe or horizontal line.

```php
final class Sep extends AbstractWidget
{
    private function __construct(
        private readonly string $char,
    ) {}

    /** Vertical pipe: │ */
    public static function pipe(): self
    {
        return new self('│');
    }

    /** Horizontal line: ─ */
    public static function line(): self
    {
        return new self('─');
    }

    public function render(RenderContext $context): array
    {
        if ($this->char === '─') {
            return [str_repeat('─', $context->getColumns())];
        }
        return [$this->char];
    }
}
```

#### ContextMeter

Progress bar driven by a computed percentage. Changes color reactively.

```php
final class ContextMeter extends ReactiveWidget
{
    private function __construct(
        private readonly Signal|Computed $percent,
    ) {
        $this->bind($percent);
    }

    public static function reactive(Signal|Computed $percent): self
    {
        return new self($percent);
    }

    protected function syncFromSignals(): bool
    {
        return true; // always re-render (bar shape changes every tick)
    }

    public function render(RenderContext $context): array
    {
        $pct = (float) $this->percent->get();
        $width = $context->getColumns() - 2;
        $filled = max(0, (int) ($pct / 100 * $width));
        $empty = max(0, $width - $filled);

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        $color = match (true) {
            $pct < 50  => "\033[38;2;80;200;120m",
            $pct < 80  => "\033[38;2;230;200;80m",
            default    => "\033[38;2;220;80;80m",
        };

        return [$color . '[' . $bar . "]\033[0m"];
    }
}
```

#### PhaseIcon

Displays the current phase as a symbol, driven by the phase signal.

```php
final class PhaseIcon extends ReactiveWidget
{
    private string $icon = '';

    private function __construct(
        private readonly Signal $phase,
    ) {
        $this->bind($phase);
    }

    public static function reactive(Signal $phase): self
    {
        return new self($phase);
    }

    protected function syncFromSignals(): bool
    {
        $new = match ($this->phase->get()) {
            'idle'      => '◆',
            'thinking'  => '⚡',
            'tools'     => '🔧',
            'compacting' => '◈',
            default     => '·',
        };
        if ($this->icon === $new) {
            return false;
        }
        $this->icon = $new;
        return true;
    }

    public function render(RenderContext $context): array
    {
        return [$this->icon];
    }
}
```

### Input primitives

#### TextField

Single-line text input. Extends Symfony TUI's `InputWidget` with signal binding.

```php
final class TextField extends InputWidget
{
    private ?Signal $boundSignal = null;

    public static function make(
        string $placeholder = '',
        ?Signal $value = null,
    ): self {
        $field = new self();
        $field->setPlaceholder($placeholder);

        if ($value !== null) {
            $field->boundSignal = $value;
            $field->setText((string) $value->get());
        }

        return $field;
    }

    /**
     * Sync widget state back to signal on each frame.
     * Direction: widget → signal (user input updates state).
     */
    public function beforeRender(): void
    {
        parent::beforeRender();

        if ($this->boundSignal !== null) {
            $current = $this->getText();
            if ($this->boundSignal->get() !== $current) {
                $this->boundSignal->set($current);
            }
        }
    }
}
```

#### Button

A labeled key-binding trigger. Not a traditional button (terminals don't have clicks),
but a styled label that responds to a key press.

```php
final class Button extends AbstractWidget
{
    private function __construct(
        private readonly string $label,
        private readonly ?string $keyBinding = null,
        private readonly ?callable $onPress = null,
        private readonly string $variant = 'primary',
    ) {}

    public static function make(
        string $label,
        ?string $key = null,
        ?callable $onPress = null,
        string $variant = 'primary',
    ): self {
        return new self($label, $key, $onPress, $variant);
    }

    public function render(RenderContext $context): array
    {
        $style = match ($this->variant) {
            'primary'   => "\033[38;2;80;200;120m",
            'secondary' => "\033[38;2;160;160;180m",
            'accent'    => "\033[38;2;120;160;220m",
            'danger'    => "\033[38;2;220;80;80m",
            default     => '',
        };

        $keyHint = $this->keyBinding ? "[{$this->keyBinding}] " : '';

        return [$style . $keyHint . $this->label . "\033[0m"];
    }
}
```

---

## Usage Examples

### Status bar

Currently ~120 lines of `refreshStatusBar()` with 6 manual `flushRender()` call sites.

```php
final class StatusBarBuilder
{
    public static function build(TuiStateStore $state): AbstractWidget
    {
        return Row::make(
            gap: 1,
            classes: ['status-bar'],
            children: [
                // Mode badge — color changes reactively
                Label::reactive($state->modeLabel)
                    ->addStyleClass('badge')
                    ->setStyle(new Style(fg: $state->modeColor->get())),

                Sep::pipe(),

                // Permission mode
                Label::reactive($state->permissionLabel),

                Sep::pipe(),

                // Phase + thinking timer
                Row::make(gap: 1, children: [
                    PhaseIcon::reactive($state->phase),
                    Label::reactive($state->statusDetail),
                ]),

                Spacer::flex(),

                // Context meter — computed from two signals
                ContextMeter::reactive($state->contextPercentComputed),

                Sep::pipe(),

                // Cost counter
                Label::reactive(
                    Computed(fn (): string => $state->getCost() !== null
                        ? '$' . number_format($state->getCost(), 3)
                        : ''),
                )->addStyleClass('dim'),

                // Model name
                Label::reactive($state->model)->addStyleClass('dim'),
            ],
        );
    }
}
```

### Context meter

A progress bar that changes color as it fills — pure reactive:

```php
// In a composition
ContextMeter::reactive($store->contextPercentComputed)
```

One line. The meter redraws automatically whenever `tokensIn` or `maxContext` changes,
because `contextPercentComputed` is a `Computed` that depends on both.

The full widget implementation:

```php
final class ContextMeter extends ReactiveWidget
{
    private function __construct(
        private readonly Signal|Computed $percent,
    ) {
        $this->bind($percent);
    }

    public static function reactive(Signal|Computed $percent): self
    {
        return new self($percent);
    }

    protected function syncFromSignals(): bool
    {
        return true;
    }

    public function render(RenderContext $context): array
    {
        $pct = (float) $this->percent->get();
        $width = $context->getColumns() - 2;
        $filled = max(0, (int) ($pct / 100 * $width));
        $empty = max(0, $width - $filled);

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        $color = match (true) {
            $pct < 50  => "\033[38;2;80;200;120m",
            $pct < 80  => "\033[38;2;230;200;80m",
            default    => "\033[38;2;220;80;80m",
        };

        return [$color . '[' . $bar . "]\033[0m"];
    }
}
```

### Tool execution card

Currently `TuiToolRenderer` at ~300 lines with manual loader management, timer IDs,
breath tick counters. With primitives:

```php
final class ToolExecutionCard extends ReactiveWidget
{
    public static function build(TuiStateStore $state): self
    {
        return new self($state);
    }

    public function render(RenderContext $context): array
    {
        $lines = [];
        $cols = $context->getColumns();

        // Tool name + spinner
        $spinner = Theme::spinner($this->state->spinnerIndex->get());
        $toolName = $this->state->activeToolName->get();

        $lines[] = Theme::toolIcon($toolName) . ' '
                  . $spinner . ' '
                  . Theme::bold($toolName);

        // Preview line (last non-empty line of tool args)
        $preview = $this->state->toolExecutingPreview->get();
        if ($preview !== null) {
            $lines[] = Theme::dim('  › '
                . AnsiUtils::truncateToWidth($preview, $cols - 4));
        }

        // Elapsed timer
        $elapsed = $this->state->thinkingStartTime->get();
        if ($elapsed > 0) {
            $seconds = (int) (microtime(true) - $elapsed);
            $lines[] = Theme::dim("  {$seconds}s elapsed");
        }

        return $lines;
    }
}
```

### Subagent tree

The live agent swarm display — currently `SubagentDisplayManager` at ~250 lines managing
tree widget state manually:

```php
final class AgentTreeView extends ReactiveWidget
{
    public static function build(
        TuiStateStore $state,
        SubagentOrchestrator $orchestrator,
    ): AbstractWidget {
        return Column::make(gap: 0, children: [
            // Header with progress
            Row::make(gap: 1, children: [
                Label::text('◈ Agents')->addStyleClass('tool-header'),
                Spacer::flex(),
                Label::reactive(Computed(
                    fn (): string => self::formatProgress($orchestrator)
                )),
            ]),

            // Agent list — rebuilds when agents change
            Column::reactive(
                items: $state->agentList,
                builder: fn (AgentInfo $agent): AbstractWidget
                    => self::agentRow($agent),
                gap: 0,
            ),
        ]);
    }

    private static function agentRow(AgentInfo $agent): AbstractWidget
    {
        $icon = match ($agent->status) {
            AgentStatus::Running  => '●',
            AgentStatus::Done     => '✓',
            AgentStatus::Failed   => '✗',
            AgentStatus::Waiting  => '○',
        };

        return Row::make(gap: 1, children: [
            Label::text("  {$icon}")
                ->addStyleClass($agent->status->styleClass()),
            Label::text($agent->id)->addStyleClass('dim'),
            Label::text($agent->taskPreview),
            Spacer::flex(),
            Label::text(self::formatElapsed($agent->elapsed)),
        ]);
    }
}
```

### Toast stack

The toast overlay — absolute-positioned notification boxes in the bottom-right corner:

```php
final class ToastStack extends ReactiveWidget
{
    public static function build(): self
    {
        $manager = ToastManager::getInstance();
        return new self($manager->toastsSignal());
    }

    public function render(RenderContext $context): array
    {
        $lines = [];
        $cols = $context->getColumns();
        $maxWidth = min(50, $cols - 6);

        foreach (array_reverse($this->toasts->get()) as $toast) {
            if ($toast->phase->get() === ToastPhase::Done) {
                continue;
            }

            $opacity = $toast->opacity->get();
            $style = $toast->type->applyOpacity($opacity);

            $border = $style->apply('┌' . str_repeat('─', $maxWidth - 2) . '┐');
            $content = $style->apply(
                '│ ' . AnsiUtils::truncateToWidth($toast->message, $maxWidth - 4) . ' │'
            );
            $bottom = $style->apply('└' . str_repeat('─', $maxWidth - 2) . '┘');

            $lines[] = '';
            $lines[] = $border;
            $lines[] = $content;
            $lines[] = $bottom;
        }

        return $lines;
    }
}
```

### Permission prompt dialog

Currently `TuiModalManager::showPermissionPrompt()` at ~80 lines building widgets
imperatively:

```php
final class PermissionPrompt extends ReactiveWidget
{
    public static function build(TuiStateStore $state): AbstractWidget
    {
        return Column::make(
            gap: 1,
            classes: ['modal-overlay'],
            children: [
                Column::make(
                    gap: 0,
                    classes: ['modal-card'],
                    children: [
                        // Header
                        Row::make(gap: 1, children: [
                            Label::text('⚠ Permission Required')
                                ->addStyleClass('bold'),
                        ]),

                        Sep::line(),

                        // Tool name + args
                        Label::reactive($state->pendingToolName)
                            ->addStyleClass('tool-name'),
                        Label::reactive($state->pendingToolArgs)
                            ->addStyleClass('dim')
                            ->truncate(),

                        Sep::line(),

                        // Actions
                        Row::make(
                            gap: 2,
                            justify: 'end',
                            children: [
                                Button::make(
                                    'Deny',
                                    key: 'n',
                                    variant: 'danger',
                                ),
                                Button::make(
                                    'Allow once',
                                    key: 'y',
                                    variant: 'primary',
                                ),
                                Button::make(
                                    'Allow always',
                                    key: 'a',
                                    variant: 'accent',
                                ),
                            ],
                        ),
                    ],
                ),
            ],
        );
    }
}
```

### The full app shell

Putting it all together. This is what `TuiCoreRenderer`'s layout would become:

```php
final class AppShell
{
    public function build(TuiStateStore $state): AbstractWidget
    {
        return Column::make(gap: 0, classes: ['root'], children: [
            // Intro animation (ephemeral, replaced after first response)
            Conditional::reactive(
                condition: Computed(fn (): bool => $state->introVisible->get()),
                child: new AnsiArtWidget(Theme::cosmicIntro()),
            ),

            // Main conversation area — fills remaining space
            ConversationScroll::build($state)
                ->expandVertically(),

            // Active tool execution (shows/hides reactively)
            Conditional::reactive(
                condition: $state->hasActiveTool,
                child: ToolExecutionCard::build($state),
            ),

            // Subagent tree (shows when agents are running)
            Conditional::reactive(
                condition: $state->hasRunningAgents,
                child: AgentTreeView::build($state, $this->orchestrator),
            ),

            Sep::line(),

            // Status bar — always visible
            StatusBarBuilder::build($state),

            // Input area
            InputBar::build($state),
        ]);
    }
}
```

Compare to the current `TuiCoreRenderer` — ~800 lines of imperative
`addConversationWidget()`, `refreshStatusBar()`, `flushRender()`, manual timer management,
and 51 separate `requestRender()` calls. The declaration above is the entire layout.
State changes propagate through signals. Effects schedule renders. Widgets sync in
`beforeRender()`. No manual refresh calls anywhere.

---

## Migration Path

The migration is incremental. Each step is independently testable.

### Phase 1: Land the state store (done)

- Signal primitives, TuiStateStore, PhaseStateMachine, ToastManager
- All landed, tested, dormant
- Zero changes to existing code

### Phase 2: Create the primitive layer

- `ReactiveWidget`, `ReactiveBridge`, `Column`, `Row`, `Label`, `Sep`, `Spacer`,
  `Conditional`, `ContextMeter`, `PhaseIcon`
- New directory: `src/UI/Tui/Primitive/`
- Still dormant — not wired into the existing renderer

### Phase 3: Wire ReactiveBridge

- Create `TuiStateStore` instance in `TuiCoreRenderer::__construct()`
- Create `ReactiveBridge` instance, call `start($tui, $store)`
- This replaces the manual `flushRender()` pattern — all 51 call sites become unnecessary

### Phase 4: Migrate the status bar

- Replace the scalar properties + `refreshStatusBar()` with `StatusBarBuilder::build($store)`
- One file change, testable in isolation
- Delete `refreshStatusBar()` and its 6 call sites

### Phase 5: Migrate tool rendering

- Replace `TuiToolRenderer`'s manual widget management with `ToolExecutionCard`
- Remove manual loader timer management

### Phase 6: Migrate remaining components

- Subagent display → `AgentTreeView`
- Toast overlay → `ToastStack`
- Permission prompts → `PermissionPrompt`
- Modal management → `Conditional` + overlay positioning

### Phase 7: Remove old infrastructure

- Delete `refreshStatusBar()`, `flushRender()`, `requestRender()` wrappers
- Delete scalar properties from `TuiCoreRenderer`
- Delete manual timer management from `TuiAnimationManager`

Each phase is a single PR. Each PR can be reverted independently.

---

## Package Extraction

### Why it could become a standalone package

The signal system has zero Symfony TUI dependencies — only `Revolt\EventLoop`. The primitive
layer depends on Symfony TUI only through inheritance (`extends AbstractWidget`). This is
a clean, extractable boundary.

### Proposed structure

```
opcompany/reactive-tui/
├── src/
│   ├── Signal/              ← Pure PHP, zero TUI deps
│   │   ├── Signal.php
│   │   ├── Computed.php
│   │   ├── Effect.php
│   │   ├── EffectScope.php
│   │   ├── BatchScope.php
│   │   └── Subscriber.php
│   │
│   ├── Widget/              ← Extends Symfony TUI widgets
│   │   ├── ReactiveWidget.php
│   │   ├── Column.php
│   │   ├── Row.php
│   │   ├── Label.php
│   │   ├── Sep.php
│   │   ├── Spacer.php
│   │   ├── Conditional.php
│   │   ├── ContextMeter.php
│   │   ├── TextField.php
│   │   └── Button.php
│   │
│   └── Bridge/              ← Connects signals to Tui::requestRender()
│       └── ReactiveBridge.php
│
├── tests/
│   ├── Unit/Signal/         ← Pure logic tests, no TUI
│   └── Unit/Widget/         ← Widget tests with mocked RenderContext
│
└── composer.json
    requires:
      - symfony/tui: ^0.x
      - revolt/event-loop: ^1.0
```

### When to extract

- **Now**: no. Single consumer (KosmoKrator). Extracting adds repo management, versioning,
  and coordination overhead with no benefit.
- **When**: a second app wants to build a TUI with these primitives. Then the signal system
  and widget layer get extracted together.
- **The signal system alone**: could be extracted as `opcompany/reactive-signals` at any time.
  It's fully decoupled. But there's little value in publishing a reactive primitives package
  for PHP without the consumers that demonstrate the pattern.

---

## Alternatives Considered

### Event Dispatcher

Symfony's `EventDispatcher` — objects dispatch named events, listeners subscribe.

```php
// Verbose alternative
$this->dispatcher->dispatch(new ModeChangedEvent('Edit'));
// Somewhere else:
$this->dispatcher->addListener(ModeChangedEvent::class, function (ModeChangedEvent $e) {
    $this->rebuildStatusBar();
});
```

**Verdict**: works, but 3x the boilerplate per state→widget binding. Every state change
needs an event class + listener registration + manual wire-up. No auto-tracking.
No derived values.

### Observer / Property Binding

Observable objects with `onChange` callbacks. Widgets bind to properties.

```php
// Manual binding alternative
$mode->onChange(function (string $value) {
    $this->modeLabel->setText($value);
    $this->refreshStatusBar();
});
```

**Verdict**: manual wiring for every binding. No derived values. Combinatorial explosion
for multi-source updates (mode + perm + tokens → status bar = 3 subscriptions to manage).

### Immutable State + Render Diff

Single immutable value object for all state. Renderer diffs old vs new.

```php
// Functional alternative
$oldState = $state;
$state = $state->withMode('Edit');
$diff = StateDiff::compute($oldState, $state);
$this->renderer->patch($diff);
```

**Verdict**: PHP has no efficient structural sharing. Full diff on every tick is wasteful.
Doesn't match Symfony TUI's widget model (mutate-in-place via `setText()`, `invalidate()`).

### Polling / Dirty Flags

Set dirty flags on mutation, check on render tick.

```php
// Low-tech alternative
$this->modeLabel = 'Edit';
$this->modeDirty = true;

// In render tick:
if ($this->modeDirty) {
    $this->rebuildStatusBar();
    $this->modeDirty = false;
}
```

**Verdict**: coarse-grained. Can't skip rendering what didn't change without per-widget
dirty state. Basically what we have now but more structured.

### Why signals win for this use case

The TUI has **many interdependent state fragments** (mode, phase, tokens, cost, scroll,
permission) feeding into **few output locations** (status bar, context meter, animation).
That's exactly the signal sweet spot: fine-grained reactivity with automatic dependency
tracking, derived values, and batching — all without boilerplate.

The tradeoff: signals are unconventional in PHP. Anyone reading the code needs to understand
the auto-tracking model. That's a one-time learning cost vs. an ongoing maintenance cost of
remembering to call `refreshStatusBar()` in the right places.

---

## Code Organization for Future Extraction

The signal system and primitive layer stay inside KosmoKrator — no separate package. But the
directory structure enforces a hard dependency boundary so extraction is a future `cp -r` away.

### Three layers, one rule

**The rule: `Signal/` and `Primitive/` never import anything from a KosmoKrator domain
namespace.** No `Phase`, no `State`, no `Toast`, no `Builder`, no `Agent`, no `Theme`.
If that invariant holds, `Signal/` + `Primitive/` are a self-contained library.

```
src/UI/Tui/
│
├── Signal/                          ─┐
│   ├── Signal.php                    │ Pure PHP + Revolt\EventLoop only.
│   ├── Computed.php                  │ Zero Symfony TUI deps.
│   ├── Effect.php                    │ Zero KosmoKrator deps.
│   ├── EffectScope.php               │
│   ├── BatchScope.php                │ Extractable as opcompany/reactive-signals
│   └── Subscriber.php                │ (copy dir + add composer.json)
│                                     │
├── Primitive/                        │ Depends on Signal/ + Symfony TUI only.
│   ├── ReactiveWidget.php            │ Zero KosmoKrator deps.
│   ├── ReactiveBridge.php            │
│   ├── Layout/                       │ Extractable together with Signal/ as
│   │   ├── Column.php                │ opcompany/reactive-tui
│   │   ├── Row.php                   │
│   │   ├── Spacer.php                │
│   │   └── Conditional.php           │
│   └── Widget/                      ─┘
│       ├── Label.php
│       ├── Sep.php
│       ├── ContextMeter.php
│       ├── PhaseIcon.php
│       ├── TextField.php
│       └── Button.php
│
├── Phase/                          ─┐
│   ├── Phase.php                     │ KosmoKrator domain types.
│   ├── PhaseStateMachine.php         │ Use Signal/ but are NOT extractable.
│   └── InvalidTransitionException.php│
│                                     │
├── State/                            │ KosmoKrator agent UI state.
│   └── TuiStateStore.php             │ Uses Signal/ + Computed.
│                                     │
├── Toast/                            │ KosmoKrator toast system.
│   ├── ToastItem.php                 │ Uses Signal/.
│   ├── ToastManager.php              │ Uses Signal/ + TerminalNotification.
│   ├── ToastPhase.php                │
│   └── ToastType.php                 │
│                                     │
├── Builder/                          │ App-specific compositions.
│   ├── StatusBarBuilder.php          │ Uses Primitive/ + State/ + Theme.
│   ├── ToolExecutionCard.php         │
│   ├── AgentTreeView.php             │
│   ├── ToastStack.php                │
│   ├── PermissionPrompt.php          │
│   └── AppShell.php                  │
│                                     │
└── (existing TuiCoreRenderer etc)  ─┘ Gradually shrinks as builders take over.
```

### Dependency rules enforced per directory

| Directory | May import from | May NOT import from |
|---|---|---|
| `Signal/` | `Revolt\EventLoop` | Everything else in KosmoKrator, Symfony TUI |
| `Primitive/` | `Signal/`, `Symfony\Tui\*` | `Phase/`, `State/`, `Toast/`, `Builder/`, `Theme`, any KosmoKrator domain |
| `Phase/` | `Signal/` | `Primitive/`, `State/`, `Toast/`, `Builder/` |
| `State/` | `Signal/` | `Primitive/`, `Phase/`, `Toast/`, `Builder/` |
| `Toast/` | `Signal/`, `TerminalNotification` | `Primitive/`, `Phase/`, `State/`, `Builder/` |
| `Builder/` | Everything above | — |

### What "extractable" means in practice

**`Signal/` alone** — copy the 6 files, add `composer.json` with `revolt/event-loop` dep.
That's a published reactive primitives package. Tests in `tests/Unit/UI/Tui/Signal/` copy too.

**`Signal/` + `Primitive/`** — copy both directories, add `composer.json` with
`revolt/event-loop` + `symfony/tui` deps. That's a reactive TUI framework. Tests copy too.

**Extraction checklist** (for when the time comes):

1. Copy `src/UI/Tui/Signal/` and `src/UI/Tui/Primitive/` to new repo
2. Copy `tests/Unit/UI/Tui/Signal/` and any future `tests/Unit/UI/Tui/Primitive/`
3. Add `composer.json` with namespace mapping and deps
4. Run `grep -rn 'KosmoKrator\\' src/` — should find zero hits outside the namespace decl
5. Run phpunit, phpstan, pint — all must pass
6. Done

### Why this matters now

Enforcing the boundary from day one means:

- **No accidental coupling** — a reviewer can reject any PR that imports KosmoKrator types
  into `Signal/` or `Primitive/`. It's a one-line check.
- **Clean tests** — Signal and Primitive tests test pure logic with no TUI or app bootstrap.
- **Future-proof** — if a second project wants reactive TUI primitives, the extraction is
  mechanical, not archaeological.

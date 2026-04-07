# TabsWidget — Implementation Plan

> **File**: `src/UI/Tui/Widget/TabsWidget.php`
> **Depends on**: FocusManager (Symfony TUI), Theme system (`src/UI/Theme.php`)
> **Blocks**: Settings workspace navigation, main view tabs, tool result detail tabs

---

## 1. Problem Statement

KosmoKrator's TUI currently has no reusable tab navigation component. Several features need horizontal tab bars:

- **Settings workspace** (`SettingsWorkspaceWidget`) — navigating between categories (Provider, Model, Agent, etc.) uses a custom two-column layout with arrow-key navigation; a tab bar would be more discoverable and standard.
- **Main view** — switching between Conversation / Agents / Tasks / Files requires a tab metaphor.
- **Tool result details** — viewing multiple outputs (stdout, stderr, diff) in a single collapsible section.
- **Swarm dashboard** (`SwarmDashboardWidget`) — switching between active/failed/queued agent lists.

Each of these re-implements tab-like navigation from scratch. A shared `TabsWidget` centralizes the rendering, keyboard handling, and event dispatch.

## 2. Research: Existing Tabs Implementations

### 2.1 Ratatui (Rust) — `ratatui/src/widgets/tabs.rs`

Key design decisions:

- **Separation of widget and state**: `Tabs` holds titles + styling; `selected` index is set via `Tabs::select(usize)`. The app owns the index.
- **Styling options**: `style` (base), `highlight_style` (selected tab, defaults to `Modifier::REVERSED`), `divider` (separator between tabs, default `│`).
- **Padding**: Configurable `padding_left` / `padding_right` per tab (default 1 space each).
- **Block wrapping**: Optional `Block` widget wraps the tab bar for borders.
- **Rendering**: Iterates titles left-to-right, applies highlight style to the selected tab's area, inserts dividers between tabs. Stops when running out of horizontal space.
- **No keyboard handling**: Pure display widget; input is handled by the parent component.

**Lesson**: Keep the widget focused on rendering. State (selected index) is owned externally or managed by the widget but driven by the app's event loop.

### 2.2 php-tui — `TabsWidget.php` + `TabsRenderer.php`

php-tui's implementation:

- **Widget is a pure data holder**: `TabsWidget` holds `titles: Line[]`, `selected: int`, `style`, `highlightStyle`, `divider: Span`.
- **Separate renderer**: `TabsRenderer` writes to a `Buffer` — applies base style, iterates titles, applies highlight style to selected tab.
- **No event handling in widgets**: Events are handled at the `Component` level. The demo app's `App` class maintains an `ActivePage` enum and switches via `Tab`/`BackTab` keys.
- **Usage pattern**:
  ```php
  TabsWidget::fromTitles(
      Line::parse('<fg=red>[q]</>uit'),
      Line::fromString('Files'),
      Line::fromString('Branches'),
  )->select($this->activePage->index())->highlightStyle(Style::default()->white()->onBlue());
  ```

**Lesson**: The php-tui approach aligns with KosmoKrator's Symfony TUI architecture (widgets as renderers, events via `AbstractEvent`). However, KosmoKrator widgets have `FocusableInterface` + `KeybindingsTrait`, enabling self-contained keyboard handling.

### 2.3 Lazygit — Tab System

Lazygit uses tabs for top-level navigation (Files / Branches / Commits / Stash):

- **Numbered shortcuts**: `1`–`5` jump directly to a tab.
- **Active tab**: Highlighted with different foreground + background. Inactive tabs use dim text.
- **Tab bar**: Single line at the top of the main panel. Divider is a space or ` | `.
- **Keyboard**: Left/Right arrows cycle tabs. Number keys jump directly.
- **Content switch**: Below the tab bar, the entire panel content changes based on the active tab.

**Lesson**: Numbered shortcuts (1–9) are a major UX win for power users. Single-line tab bar is space-efficient.

## 3. Current Architecture: How It Fits

### KosmoKrator widget system:

```
AbstractWidget (vendor/symfony/tui/.../Widget/AbstractWidget.php)
├── DirtyWidgetTrait — render caching via revision counter
├── FocusableInterface — isFocused(), setFocused(), handleInput(), getKeybindings()
│   └── FocusableTrait — $focused bool, invalidate() on change
│   └── KeybindingsTrait — getDefaultKeybindings(), onInput(), resolution chain
├── Event dispatching — on(EventClass, callback), dispatch(AbstractEvent)
│   └── ChangeEvent — widget value changes
│   └── SelectionChangeEvent — highlighted item changes
│   └── FocusEvent — focus gained/lost
└── State flags — getStateFlags() returns ['root'] or ['focus']
```

### Existing focusable widget pattern (from `PermissionPromptWidget`):

```php
final class PermissionPromptWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private int $selectedIndex = 0;

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        if ($kb->matches($data, 'up')) { /* modify state */ $this->invalidate(); return; }
        if ($kb->matches($data, 'down')) { /* modify state */ $this->invalidate(); return; }
        if ($kb->matches($data, 'confirm')) { /* dispatch event */ return; }
        if ($kb->matches($data, 'cancel')) { /* dispatch event */ }
    }

    protected static function getDefaultKeybindings(): array
    {
        return ['up' => Key::UP, 'down' => Key::DOWN, 'confirm' => Key::ENTER, 'cancel' => Key::ESCAPE];
    }

    public function render(RenderContext $context): array
    {
        // Build ANSI lines using Theme helpers
    }
}
```

### FocusManager integration:

```php
$focusManager = new FocusManager();
$focusManager->add($tabsWidget);     // auto-focuses if first widget
$focusManager->add($otherWidget);
$focusManager->onFocusChanged(function (FocusEvent $event) { /* ... */ });
// F6 cycles focus between widgets; Shift+F6 goes backwards
```

### Event dispatching:

```php
// Register listener
$tabsWidget->on(ChangeEvent::class, function (ChangeEvent $event) {
    $newTab = $event->getValue();  // tab index or tab ID
});

// Dispatch from within widget
$this->dispatch(new ChangeEvent($this, (string) $this->activeIndex));
// → calls local listeners, then bubbles to WidgetContext → EventDispatcher → triggers re-render
```

## 4. Design

### 4.1 Tab Item Value Object

A tab is more than a label — it carries an ID, a label, and an optional keyboard shortcut hint:

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

/**
 * Represents a single tab in a TabsWidget.
 */
final class TabItem
{
    /**
     * @param string $id       Stable identifier (does not change with reorder). Used in ChangeEvent.
     * @param string $label    Display label shown in the tab bar.
     * @param int|null $shortcut  Optional keyboard shortcut digit (1–9). Null = no shortcut.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?int $shortcut = null,
    ) {}

    /**
     * Convenience factory for numbered tabs (shortcut auto-assigned from 1-based position).
     *
     * @param list<string> $labels
     * @return list<self>
     */
    public static function fromLabels(array $labels): array
    {
        $tabs = [];
        foreach ($labels as $i => $label) {
            $shortcut = ($i < 9) ? $i + 1 : null;
            $tabs[] = new self(
                id: strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $label)),
                label: $label,
                shortcut: $shortcut,
            );
        }
        return $tabs;
    }
}
```

### 4.2 TabsWidget

```php
<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Horizontal tab bar with keyboard navigation, numbered shortcuts, and event dispatch.
 *
 * Renders as a single line of styled text — active tab highlighted with inverted
 * colors (bright foreground + background), inactive tabs dimmed. Tab shortcuts
 * (1–9) are shown in the label area.
 *
 * ## Layout
 *
 * ```
 *  ┌─ Files ─ Branches ─ Commits ─ Stash ─────────────────────────┐
 *  │ [content area rendered by the parent]                        │
 * ```
 *
 * The tab bar is the first line. The content area below is NOT managed by this
 * widget — the parent responds to ChangeEvent and swaps child widgets.
 *
 * ## Events
 *
 * - `ChangeEvent` dispatched when the active tab changes. `getValue()` returns
 *   the tab's string ID.
 *
 * ## Keyboard
 *
 * - Left/Right arrows: cycle active tab
 * - Tab/Shift+Tab: cycle active tab (when focused)
 * - 1–9: jump to tab by shortcut number
 * - Home/End: jump to first/last tab
 *
 * ## FocusManager
 *
 * Implements FocusableInterface. Register with FocusManager to participate in
 * the F6 focus cycle. The tab bar shows a subtle underline when focused but
 * no tab is actively highlighted (edge case with 0 tabs).
 *
 * ## Styling
 *
 * Uses Theme helpers directly:
 * - Active tab: `Theme::accent()` foreground, inverted background
 * - Inactive tab: `Theme::dim()` foreground
 * - Focused frame: `Theme::borderAccent()` underline
 * - Divider: `Theme::dim()` ` │ `
 */
final class TabsWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    // ── State ─────────────────────────────────────────────────────────────

    /** @var list<TabItem> */
    private array $tabs = [];

    /** @var int Index of the currently active tab (0-based) */
    private int $activeIndex = 0;

    /** @var string|null Character used to separate tabs in the bar */
    private ?string $divider = ' │ ';

    /** @var callable(string $tabId, int $tabIndex): void|null */
    private $onTabChangeCallback = null;

    // ── Constructor ───────────────────────────────────────────────────────

    /**
     * @param list<TabItem>|null $tabs Initial tab items. Can be set later via setTabs().
     */
    public function __construct(?array $tabs = null)
    {
        if ($tabs !== null) {
            $this->tabs = $tabs;
        }
    }

    // ── Configuration ─────────────────────────────────────────────────────

    /**
     * Set the tabs to display.
     *
     * @param list<TabItem> $tabs
     */
    public function setTabs(array $tabs): static
    {
        $this->tabs = $tabs;
        if ($this->activeIndex >= count($this->tabs)) {
            $this->activeIndex = max(0, count($this->tabs) - 1);
        }
        $this->invalidate();

        return $this;
    }

    /**
     * Set the active tab by index (0-based).
     */
    public function setActiveIndex(int $index): static
    {
        $index = max(0, min($index, count($this->tabs) - 1));
        if ($index !== $this->activeIndex) {
            $this->activeIndex = $index;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * Set the active tab by its string ID.
     */
    public function setActiveTab(string $id): static
    {
        foreach ($this->tabs as $i => $tab) {
            if ($tab->id === $id) {
                return $this->setActiveIndex($i);
            }
        }

        return $this;
    }

    /**
     * Get the currently active tab index (0-based).
     */
    public function getActiveIndex(): int
    {
        return $this->activeIndex;
    }

    /**
     * Get the currently active tab's string ID.
     */
    public function getActiveTabId(): ?string
    {
        return $this->tabs[$this->activeIndex]->id ?? null;
    }

    /**
     * Set the divider string between tabs. Default: ' │ '.
     */
    public function setDivider(string $divider): static
    {
        $this->divider = $divider;
        $this->invalidate();

        return $this;
    }

    /**
     * Register a callback invoked when the active tab changes.
     *
     * @param callable(string $tabId, int $tabIndex): void $callback
     */
    public function onTabChange(callable $callback): static
    {
        $this->onTabChangeCallback = $callback;

        return $this;
    }

    // ── Keybindings ───────────────────────────────────────────────────────

    protected static function getDefaultKeybindings(): array
    {
        return [
            'left' => Key::LEFT,
            'right' => Key::RIGHT,
            'prev' => "\x1b[Z",       // Shift+Tab
            'next' => Key::TAB,
            'home' => Key::HOME,
            'end' => Key::END,
        ];
    }

    // ── Input Handling ────────────────────────────────────────────────────

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        // Number shortcuts 1–9
        if (strlen($data) === 1 && ctype_digit($data) && $data !== '0') {
            $targetIndex = (int) $data - 1;
            if ($targetIndex < count($this->tabs)) {
                $this->selectTab($targetIndex);
            }
            return;
        }

        // Arrow / Tab navigation
        if ($kb->matches($data, 'left') || $kb->matches($data, 'prev')) {
            $this->selectTab(($this->activeIndex - 1 + count($this->tabs)) % max(1, count($this->tabs)));
            return;
        }

        if ($kb->matches($data, 'right') || $kb->matches($data, 'next')) {
            $this->selectTab(($this->activeIndex + 1) % max(1, count($this->tabs)));
            return;
        }

        if ($kb->matches($data, 'home')) {
            $this->selectTab(0);
            return;
        }

        if ($kb->matches($data, 'end')) {
            $this->selectTab(count($this->tabs) - 1);
            return;
        }
    }

    /**
     * Switch to a tab and dispatch events.
     */
    private function selectTab(int $index): void
    {
        if ($index === $this->activeIndex || empty($this->tabs)) {
            return;
        }

        $this->activeIndex = $index;
        $tab = $this->tabs[$index];

        // Dispatch ChangeEvent for the event system
        $this->dispatch(new ChangeEvent($this, $tab->id));

        // Call the direct callback if registered
        if ($this->onTabChangeCallback !== null) {
            ($this->onTabChangeCallback)($tab->id, $index);
        }

        $this->invalidate();
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    /**
     * Render the tab bar as a single ANSI-formatted line.
     *
     * The output is always exactly one line (or empty if no tabs).
     * The parent widget places this as the first line and renders
     * content below it based on the active tab.
     *
     * @param RenderContext $context Terminal dimensions
     * @return list<string> One line containing the styled tab bar
     */
    public function render(RenderContext $context): array
    {
        if (empty($this->tabs)) {
            return [];
        }

        $columns = $context->getColumns();
        $r = Theme::reset();
        $dim = Theme::dim();
        $accent = Theme::accent();
        $borderAccent = Theme::borderAccent();
        $primary = Theme::primary();

        $parts = [];
        foreach ($this->tabs as $i => $tab) {
            $isActive = $i === $this->activeIndex;

            // Build label with optional shortcut hint
            $label = $tab->label;
            if ($tab->shortcut !== null) {
                $label = "{$dim}{$tab->shortcut}{$r}" . ($isActive ? "{$accent}" : "{$dim}") . ":{$label}";
            }

            if ($isActive) {
                // Active tab: bright foreground + accent background
                $parts[] = "{$accent}{$label}{$r}";
            } else {
                // Inactive tab: dimmed
                $parts[] = "{$dim}{$label}{$r}";
            }
        }

        $divider = $this->divider ?? ' │ ';
        $content = implode($dim . $divider . $r, $parts);

        // Add focus indicator (underline) when focused
        if ($this->isFocused()) {
            $content = $borderAccent . $content . $r;
        }

        // Right-fill with dim line to full width
        $visibleWidth = AnsiUtils::visibleWidth($content);
        $fillWidth = max(0, $columns - $visibleWidth);
        $content .= $dim . str_repeat('─', $fillWidth) . $r;

        // Truncate to terminal width
        $line = AnsiUtils::truncateToWidth($content, $columns);

        return [$line];
    }
}
```

### 4.3 Styling Details

The rendering uses `Theme` helpers directly — no stylesheet entries needed for the initial implementation. Future enhancement could add `resolveElement()` calls for theme customization.

**Color behavior:**

| Element | Style |
|---------|-------|
| Active tab label | `Theme::accent()` foreground (cyan/teal) |
| Inactive tab label | `Theme::dim()` foreground (gray) |
| Divider between tabs | `Theme::dim()` `│` |
| Shortcut digit | `Theme::dim()` always (power-user hint, not prominent) |
| Focus indicator | `Theme::borderAccent()` on the entire line when focused |
| Right fill | `Theme::dim()` `─` dashes extending to terminal edge |

**Active tab rendering (ASCII approximation):**
```
 dim  accent        dim     dim
  1:Files │ Branches │ Commits │ Stash ───────────────────────
  ^^^^^^^^   ^^^^^^^^   ^^^^^^^^   ^^^^^
  active     inactive   inactive   inactive
```

The active tab has bright foreground (`accent`). No background inversion initially — background colors in terminals are inconsistent. If needed, `Theme::bgRgb()` can be added later.

### 4.4 Content Area Switching Pattern

The `TabsWidget` does NOT manage the content area. The parent widget/composite is responsible for switching content based on tab changes:

```php
// In the parent widget's render() method:
$tabBar = $this->tabsWidget->render($context);

// Get content for the active tab
$activeTabId = $this->tabsWidget->getActiveTabId();
$contentLines = match ($activeTabId) {
    'conversation' => $this->conversationWidget->render($context),
    'agents' => $this->agentsWidget->render($context),
    'tasks' => $this->tasksWidget->render($context),
    default => [],
};

return array_merge($tabBar, $contentLines);
```

### 4.5 Integration with FocusManager

```php
// In TuiCoreRenderer or wherever the tab container is built:
$tabsWidget = new TabsWidget(TabItem::fromLabels(['Conversation', 'Agents', 'Tasks']));
$tabsWidget->setId('main-tabs');
$tabsWidget->onTabChange(function (string $tabId, int $index) {
    // Switch content, update child widgets, etc.
    $this->switchMainViewTab($tabId);
});

// Register with focus manager so F6 can reach it
$this->focusManager->add($tabsWidget);
```

### 4.6 Integration with Reactive State (Future)

When the reactive signal system from `01-reactive-state` is available:

```php
// Create a computed signal for the active tab ID
$activeTab = new Signal('conversation');

// Bind the tabs widget to the signal
new Effect(function () use ($activeTab, $tabsWidget) {
    $tabsWidget->setActiveTab($activeTab->get());
});

// Tab changes update the signal
$tabsWidget->onTabChange(function (string $tabId) use ($activeTab) {
    $activeTab->set($tabId);
});
```

## 5. Use Cases

### 5.1 Settings Workspace Navigation

Replace the current category sidebar in `SettingsWorkspaceWidget` with a horizontal tab bar:

```php
$tabs = TabItem::fromLabels(['Provider', 'Model', 'Agent', 'Guardian', 'Appearance', 'Advanced']);
$settingsTabs = new TabsWidget($tabs);
$settingsTabs->setId('settings-tabs');
$settingsTabs->onTabChange(function (string $tabId) {
    $this->switchSettingsCategory($tabId);
});
```

**Before (current):** Two-column layout with vertical category list on the left.
**After:** Tab bar at top, full-width content below. More space for settings fields.

### 5.2 Main View Tabs (Conversation / Agents / Tasks)

The primary view needs tab navigation between top-level sections:

```php
$tabs = [
    new TabItem(id: 'conversation', label: 'Conversation', shortcut: 1),
    new TabItem(id: 'agents', label: 'Agents', shortcut: 2),
    new TabItem(id: 'tasks', label: 'Tasks', shortcut: 3),
    new TabItem(id: 'files', label: 'Files', shortcut: 4),
];
$mainTabs = new TabsWidget($tabs);
$mainTabs->setId('main-tabs');
$mainTabs->onTabChange(function (string $tabId) use ($renderer) {
    $renderer->switchMainPanel($tabId);
});
```

User presses `2` → jumps to Agents tab. Arrows cycle. Tab bar stays at the top of the main panel.

### 5.3 Tool Result Detail Tabs

Inside a `BashCommandWidget` or `CollapsibleWidget`, when expanded, show tabs for different output views:

```php
$tabs = [
    new TabItem(id: 'stdout', label: 'Output', shortcut: 1),
    new TabItem(id: 'stderr', label: 'Errors', shortcut: 2),
    new TabItem(id: 'diff', label: 'Diff', shortcut: 3),
];
$resultTabs = new TabsWidget($tabs);
$resultTabs->setId("tool-result-tabs-{$toolCallId}");
```

Compact: tab bar is one line, doesn't waste vertical space in an already-expanded tool output block.

### 5.4 Swarm Dashboard Tabs

In `SwarmDashboardWidget`, switch between agent status lists:

```php
$tabs = TabItem::fromLabels(['Active', 'Queued', 'Completed', 'Failed']);
$swarmTabs = new TabsWidget($tabs);
$swarmTabs->setId('swarm-tabs');
```

## 6. Rendering Algorithm — Detailed

```
Input:
  tabs = [TabItem("files", "Files", 1), TabItem("branches", "Branches", 2), TabItem("commits", "Commits", 3)]
  activeIndex = 1
  focused = true
  columns = 80

Build parts:
  Tab 0 (inactive): dim + "1" + reset + dim + ":Files" + reset
  Tab 1 (active):   dim + "2" + reset + accent + ":Branches" + reset
  Tab 2 (inactive): dim + "3" + reset + dim + ":Commits" + reset

Join with divider: dim + " │ " + reset

Result: " 1:Files │ 2:Branches │ 3:Commits ────────────────────────────────────────"
          dim gray     accent/cyan    dim gray           dim dashes to fill 80 cols
          ^^^^^^^^^^   ^^^^^^^^^^^^   ^^^^^^^^^^^         ^^^^^^^^^^^^^^^^^^^^
          inactive     ACTIVE         inactive            right fill

Output: [one line]
```

**Edge cases:**

| Scenario | Behavior |
|----------|----------|
| No tabs set | Return `[]` — empty, nothing rendered |
| 1 tab | Single tab, always active. No dividers. |
| Tab label too long | Truncated via `AnsiUtils::truncateToWidth()` |
| More tabs than fit | Right-most tabs silently truncated (future: horizontal scrolling) |
| Tab index out of bounds | Clamped via `max(0, min(index, count - 1))` |

## 7. File Structure

```
src/UI/Tui/Widget/
├── TabsWidget.php          # The widget (render logic + input handling)
└── TabItem.php             # Value object for tab data

tests/Unit/UI/Tui/Widget/
├── TabsWidgetTest.php      # Rendering + input handling + event dispatch
└── TabItemTest.php         # fromLabels() factory, basic construction
```

## 8. Test Plan

### 8.1 `TabsWidgetTest`

| Test | Input | Expected |
|------|-------|----------|
| Empty tabs | `new TabsWidget([])` | `render()` returns `[]` |
| Single tab | `[TabItem('x', 'Only')]` | One part rendered, no dividers |
| Active tab styling | 3 tabs, index 1 | Second tab has `accent` in output |
| Inactive tab styling | 3 tabs, index 0 | Non-active tabs have `dim` in output |
| Divider between tabs | 3 tabs | Output contains `│` separator |
| Right fill to columns | 3 tabs, 80 columns | Line is padded with `─` to 80 visible width |
| Focus indicator | focused = true | Output contains `borderAccent` sequence |
| Number shortcut | Press '2' with 3 tabs | `activeIndex` becomes 1, ChangeEvent dispatched |
| Left arrow | Index 1, press left | Index becomes 0 |
| Right arrow wraps | Index 2 (last), press right | Index becomes 0 (wraps) |
| Left arrow wraps | Index 0, press left | Index becomes last tab |
| Home key | Any index | Index becomes 0 |
| End key | Any index | Index becomes last |
| setActiveIndex clamps | Index 99 with 3 tabs | Index becomes 2 |
| setActiveTab by ID | `setActiveTab('branches')` | Index matches branches tab position |
| ChangeEvent dispatched | Select tab via keyboard | `ChangeEvent::getValue()` returns tab ID |
| onTabChange callback | Select tab via keyboard | Callback called with `(tabId, tabIndex)` |
| Shortcut beyond 9 | 10+ tabs | Tab 10+ have `shortcut: null`, no shortcut rendered |
| Truncation | Labels exceed terminal width | Output truncated via `AnsiUtils::truncateToWidth()` |

### 8.2 `TabItemTest`

| Test | Input | Expected |
|------|-------|----------|
| fromLabels basic | `['Files', 'Branches']` | 2 items, IDs `files`, `branches`, shortcuts 1, 2 |
| fromLabels > 9 | 12 labels | Items 9+ have `shortcut: null` |
| ID sanitization | `'Foo Bar/Baz'` | ID is `foo-bar-baz` |

## 9. Accessibility Considerations

- **Keyboard-only navigation**: All tabs reachable via Left/Right, Tab/Shift+Tab, number shortcuts, Home/End.
- **Visible focus**: `borderAccent` underline on the entire tab bar when focused.
- **Color contrast**: Active tab uses `accent()` (bright cyan) which is high-contrast against dark terminal backgrounds. Inactive tabs use `dim()` (gray) which is readable but clearly secondary.
- **Future: screen reader**: The `ChangeEvent` payload includes both the tab ID and index, enabling aural feedback.

## 10. Future Enhancements (out of scope for initial implementation)

1. **Horizontal scrolling** — When tabs exceed terminal width, scroll the tab bar with Left/Right at the edges. Add `◄` / `►` overflow indicators.
2. **Custom active tab styling** — Allow per-tab highlight styles (e.g., red for error tabs, green for success).
3. **Closable tabs** — Add an `×` close button for tabs, dispatching a `CloseTabEvent`. Useful for multi-file editors.
4. **Tab badges** — Show count badges (e.g., "Files (3)") next to the label.
5. **Drag-to-reorder** — Mouse support for reordering tabs. Depends on `05-mouse-support`.
6. **Keyboard shortcut customization** — Allow overriding the 1–9 shortcuts with custom key bindings per tab.
7. **Animated tab switch** — Smooth transition via `08-animation` spring physics.
8. **Tab bar at bottom** — Option to render the tab bar below the content area instead of above.
9. **Nested tabs** — Support a second level of tab bars for hierarchical navigation.
10. **Tab completion** — Typing a tab label prefix to filter/jump to a tab (similar to command palette fuzzy matching).

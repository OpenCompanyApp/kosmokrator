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
 * Renders as a single line of styled text — active tab highlighted with the accent
 * color, inactive tabs dimmed. Tab shortcuts (1–9) are shown as a prefix hint.
 *
 * ## Layout
 *
 * ```
 *  1:Files │ 2:Branches │ 3:Commits ─────────────────────────────────────────
 * ```
 *
 * The tab bar is a single line. The content area below is NOT managed by this
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
 * ## Styling
 *
 * Uses Theme helpers directly:
 * - Active tab: `Theme::accent()` foreground
 * - Inactive tab: `Theme::dim()` foreground
 * - Focused frame: `Theme::borderAccent()` applied to the entire line when focused
 * - Divider: `Theme::dim()` `│`
 * - Right fill: `Theme::dim()` `─` dashes extending to terminal edge
 */
final class TabsWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    // ── State ─────────────────────────────────────────────────────────────────

    /** @var list<TabItem> */
    private array $tabs = [];

    /** 0-based index of the currently active tab. */
    private int $activeIndex = 0;

    /** Separator string rendered between tabs. */
    private string $divider = ' │ ';

    /** @var callable(string $tabId, int $tabIndex): void|null */
    private $onTabChangeCallback = null;

    // ── Constructor ───────────────────────────────────────────────────────────

    /**
     * @param list<TabItem>|null $tabs Initial tab items. Can be set later via setTabs().
     */
    public function __construct(?array $tabs = null)
    {
        if ($tabs !== null) {
            $this->tabs = $tabs;
        }
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    /**
     * Set the tabs to display.
     *
     * @param list<TabItem> $tabs
     */
    public function setTabs(array $tabs): static
    {
        $this->tabs = $tabs;
        if ($this->activeIndex >= \count($this->tabs)) {
            $this->activeIndex = max(0, \count($this->tabs) - 1);
        }
        $this->invalidate();

        return $this;
    }

    /**
     * Set the active tab by 0-based index (clamped to valid range).
     */
    public function setActiveIndex(int $index): static
    {
        $max = max(0, \count($this->tabs) - 1);
        $index = max(0, min($index, $max));
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
     * Get the currently active tab's 0-based index.
     */
    public function getActiveIndex(): int
    {
        return $this->activeIndex;
    }

    /**
     * Get the currently active tab's string ID, or null if no tabs are set.
     */
    public function getActiveTabId(): ?string
    {
        return $this->tabs[$this->activeIndex]->id ?? null;
    }

    /**
     * Set the divider string rendered between tabs. Default: ' │ '.
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

    // ── Keybindings ───────────────────────────────────────────────────────────

    protected static function getDefaultKeybindings(): array
    {
        return [
            'left' => [Key::LEFT],
            'right' => [Key::RIGHT],
            'prev' => [Key::shift('tab')],
            'next' => [Key::TAB],
            'home' => [Key::HOME],
            'end' => [Key::END],
        ];
    }

    // ── Input Handling ────────────────────────────────────────────────────────

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        $tabCount = \count($this->tabs);

        if (0 === $tabCount) {
            return;
        }

        // Number shortcuts 1–9
        if (1 === \strlen($data) && ctype_digit($data) && '0' !== $data) {
            $targetIndex = (int) $data - 1;
            if ($targetIndex < $tabCount) {
                $this->selectTab($targetIndex);
            }

            return;
        }

        // Left arrow or Shift+Tab
        if ($kb->matches($data, 'left') || $kb->matches($data, 'prev')) {
            $this->selectTab(($this->activeIndex - 1 + $tabCount) % $tabCount);

            return;
        }

        // Right arrow or Tab
        if ($kb->matches($data, 'right') || $kb->matches($data, 'next')) {
            $this->selectTab(($this->activeIndex + 1) % $tabCount);

            return;
        }

        // Home
        if ($kb->matches($data, 'home')) {
            $this->selectTab(0);

            return;
        }

        // End
        if ($kb->matches($data, 'end')) {
            $this->selectTab($tabCount - 1);
        }
    }

    /**
     * Switch to a tab and dispatch events.
     */
    private function selectTab(int $index): void
    {
        if ($index === $this->activeIndex) {
            return;
        }

        $this->activeIndex = $index;
        $tab = $this->tabs[$index];

        // Dispatch ChangeEvent for the event system
        $this->dispatch(new ChangeEvent($this, $tab->id));

        // Call the direct callback if registered
        if (null !== $this->onTabChangeCallback) {
            ($this->onTabChangeCallback)($tab->id, $index);
        }

        $this->invalidate();
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

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

        $parts = [];
        foreach ($this->tabs as $i => $tab) {
            $isActive = $i === $this->activeIndex;
            $labelColor = $isActive ? $accent : $dim;

            // Build label with optional shortcut hint
            if (null !== $tab->shortcut) {
                $label = "{$dim}{$tab->shortcut}{$r}{$labelColor}:{$tab->label}";
            } else {
                $label = "{$labelColor}{$tab->label}";
            }

            $parts[] = "{$label}{$r}";
        }

        $dividerStyled = $dim . $this->divider . $r;
        $content = implode($dividerStyled, $parts);

        // Add focus indicator when focused
        if ($this->isFocused()) {
            $content = $borderAccent . $content . $r;
        }

        // Right-fill with dim dashes to full terminal width
        $visibleWidth = AnsiUtils::visibleWidth($content);
        $fillWidth = max(0, $columns - $visibleWidth);
        $content .= $dim . str_repeat('─', $fillWidth) . $r;

        // Truncate to terminal width
        $line = AnsiUtils::truncateToWidth($content, $columns);

        return [$line];
    }
}

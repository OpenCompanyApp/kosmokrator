<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Input;

/**
 * Enumerates the available keybinding contexts (modes/states) in the TUI.
 *
 * Each context defines its own set of keybindings. At runtime the active
 * context determines which action a key resolves to — the same key can
 * perform different actions depending on the current UI state.
 */
enum KeybindingContext: string
{
    /**
     * Default mode — prompt editor is focused, browsing conversation.
     */
    case Normal = 'normal';

    /**
     * Slash/power/skill command completion dropdown is visible.
     */
    case Completion = 'completion';

    /**
     * Swarm dashboard / agents panel overlay is focused.
     */
    case Dashboard = 'dashboard';

    /**
     * Modal dialogs (permission prompt, plan approval, questions).
     */
    case Modal = 'modal';

    /**
     * Settings panel is focused.
     */
    case Settings = 'settings';
}

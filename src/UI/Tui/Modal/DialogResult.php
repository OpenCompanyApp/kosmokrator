<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Modal;

/**
 * Result values returned by modal dialogs.
 *
 * Provides semantic constants for common dialog outcomes. Custom button
 * values can also be used — these are the standard results for built-in
 * factory methods (confirm, alert, danger).
 */
enum DialogResult: string
{
    /** User confirmed / accepted the dialog action. */
    case Confirmed = 'confirm';

    /** User cancelled / dismissed the dialog. */
    case Cancelled = 'cancel';

    /** User acknowledged an alert / informational dialog. */
    case Acknowledged = 'ok';

    /** User triggered a destructive action (e.g., delete, reset). */
    case Danger = 'danger';

    /** Dialog was dismissed via Escape / Ctrl+C without selecting a button. */
    case Dismissed = 'dismissed';
}

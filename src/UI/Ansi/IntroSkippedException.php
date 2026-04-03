<?php

namespace Kosmokrator\UI\Ansi;

/**
 * Thrown when the ANSI intro animation is skipped (e.g. non-interactive terminal).
 * Part of the dual TUI/ANSI rendering layer — the TUI path handles this gracefully.
 */
class IntroSkippedException extends \RuntimeException {}

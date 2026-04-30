<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi\Concern;

use Kosmokrator\UI\Ansi\IntroSkippedException;

/**
 * Shared SIGINT handler for ANSI animations.
 *
 * Installs a pcntl_signal handler that throws IntroSkippedException on
 * Ctrl+C, allowing animations to be gracefully interrupted. The previous
 * handler is restored via {@see restoreSignalHandler()}.
 *
 * Usage inside animate():
 *   $this->installSignalHandler();
 *   try { ... phases ... } catch (IntroSkippedException) { ... skipped ... }
 *   finally { $this->restoreSignalHandler(); }
 */
trait AnimationSignalHandler
{
    private static bool $sigintHandlerInstalled = false;

    private static mixed $previousSigintHandler = null;

    /**
     * Install a SIGINT handler that throws IntroSkippedException.
     *
     * Safe to call even when the pcntl extension is not available —
     * in that case this is a no-op.
     */
    protected function installSignalHandler(): void
    {
        if (! \function_exists('pcntl_signal') || self::$sigintHandlerInstalled) {
            return;
        }

        self::$previousSigintHandler = \function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGINT)
            : SIG_DFL;
        self::$sigintHandlerInstalled = true;
        pcntl_signal(SIGINT, function (): void {
            throw new IntroSkippedException('Animation interrupted by SIGINT');
        });
    }

    /**
     * Restore the SIGINT handler that was active before the animation started.
     */
    protected function restoreSignalHandler(): void
    {
        if (! \function_exists('pcntl_signal') || ! self::$sigintHandlerInstalled) {
            return;
        }

        pcntl_signal(SIGINT, self::$previousSigintHandler ?? SIG_DFL);
        self::$previousSigintHandler = null;
        self::$sigintHandlerInstalled = false;
    }
}

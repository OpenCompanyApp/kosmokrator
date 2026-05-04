<?php

declare(strict_types=1);

namespace Kosmokrator\Bootstrap;

/**
 * Guards kernel boot from asynchronous termination signals.
 *
 * INT/TERM are blocked while service providers boot so schema migrations and
 * lock-protected initialization cannot be interrupted halfway through. Pending
 * signals are dispatched immediately after boot, preserving normal shell exit
 * codes for automation.
 */
final class BootstrapSignalGuard
{
    /** @var int[] */
    private array $previousMask = [];

    private bool $blocked = false;

    public static function install(): self
    {
        $guard = new self;

        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (int $signal): never {
                exit(self::exitCodeForSignal($signal));
            });
            pcntl_signal(SIGTERM, static function (int $signal): never {
                exit(self::exitCodeForSignal($signal));
            });
        }

        return $guard;
    }

    public function block(): void
    {
        if (! \function_exists('pcntl_sigprocmask')) {
            return;
        }

        $oldMask = [];
        if (pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM], $oldMask)) {
            $this->previousMask = $oldMask;
            $this->blocked = true;
        }
    }

    public function unblockAndDispatch(): void
    {
        if (! $this->blocked || ! \function_exists('pcntl_sigprocmask')) {
            return;
        }

        pcntl_sigprocmask(SIG_SETMASK, $this->previousMask);
        $this->blocked = false;
        $this->previousMask = [];

        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    public static function exitCodeForSignal(int $signal): int
    {
        return 128 + $signal;
    }
}

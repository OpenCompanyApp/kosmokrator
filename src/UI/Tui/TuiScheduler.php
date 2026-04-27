<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Revolt\EventLoop;
use Symfony\Component\Tui\Tui;

/**
 * Small adapter over Symfony TUI's internal scheduler.
 *
 * Runtime TUI code should schedule UI animation work through this class so
 * ticks, renders, and input are coordinated by Symfony's guarded TUI loop.
 * Tests can use the fallback scheduler without constructing a full Tui.
 */
final class TuiScheduler
{
    /**
     * @param  \Closure(callable(): void, float): string  $schedule
     * @param  \Closure(string): void  $cancel
     */
    private function __construct(
        private readonly \Closure $schedule,
        private readonly \Closure $cancel,
    ) {}

    public static function fromTui(Tui $tui): self
    {
        return new self(
            static fn (callable $callback, float $intervalSeconds): string => $tui->scheduleInterval($callback, $intervalSeconds),
            static function (string $id) use ($tui): void {
                $tui->cancelInterval($id);
            },
        );
    }

    public static function fallback(): self
    {
        return new self(
            static fn (callable $callback, float $intervalSeconds): string => EventLoop::repeat($intervalSeconds, $callback),
            static function (string $id): void {
                EventLoop::cancel($id);
            },
        );
    }

    /**
     * @param  callable(): void  $callback
     */
    public function every(float $intervalSeconds, callable $callback): string
    {
        return ($this->schedule)($callback, $intervalSeconds);
    }

    /**
     * @param  callable(): void  $callback
     */
    public function after(float $delaySeconds, callable $callback): string
    {
        $id = null;
        $id = $this->every($delaySeconds, function () use (&$id, $callback): void {
            if ($id !== null) {
                $this->cancel($id);
            }

            $callback();
        });

        return $id;
    }

    public function cancel(string $id): void
    {
        ($this->cancel)($id);
    }
}

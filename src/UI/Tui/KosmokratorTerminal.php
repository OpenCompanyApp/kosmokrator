<?php

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Kosmokrator\UI\Tui;

use Revolt\EventLoop;
use Symfony\Component\Tui\Input\StdinBuffer;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Real terminal implementation using stdin/stdout.
 *
 * @experimental
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class KosmokratorTerminal implements TerminalInterface
{
    private ?StdinBuffer $stdinBuffer = null;

    private string $initialSttyState = '';

    private bool $kittyProtocolActive = false;

    private bool $started = false;

    private ?string $stdinCallbackId = null;

    private ?string $signalCallbackId = null;

    /** @var resource[]|null */
    private ?array $resizePipe = null;

    private ?string $resizePipeCallbackId = null;

    /** @var callable(string): void|null */
    private $onInput;

    /** @var callable(bool=): void|null */
    private $onResize;

    /** @var callable(): void|null */
    private $onKittyProtocolActivated;

    // Cached terminal dimensions (refreshed on SIGWINCH)
    private ?int $cachedColumns = null;

    private ?int $cachedRows = null;

    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        if ($this->started) {
            return;
        }

        $this->onInput = $onInput;
        $this->onResize = $onResize;
        $this->onKittyProtocolActivated = $onKittyProtocolActivated;
        $this->started = true;

        // Save initial terminal state and enable raw mode
        if ($this->hasSttyAvailable()) {
            $this->initialSttyState = (string) shell_exec('stty -g');

            // Enable raw mode, equivalent to cfmakeraw(), matching Node.js
            // setRawMode(true) used by the Pi reference implementation.
            // This disables canonical mode, echo, signal interpretation, and
            // extended input processing so that ALL key combinations (including
            // Ctrl+C, Ctrl+Z, Alt+Backspace) are delivered as raw bytes to the
            // application rather than being intercepted by the kernel.
            shell_exec('stty raw -echo');
        }

        // Set up stdin buffer for proper sequence parsing - must be done
        // BEFORE sending any queries so responses can be captured
        $this->setupStdinBuffer();

        // Enable bracketed paste mode
        $this->write("\x1b[?2004h");

        $this->setupResizeHandling();

        // Query for Kitty keyboard protocol support
        // If terminal supports it, it will respond with \x1b[?<flags>u
        // which is handled in setupStdinBuffer()
        $this->write("\x1b[?u");

        // Register STDIN watcher with Revolt's event loop for non-blocking input
        $this->stdinCallbackId = EventLoop::onReadable(\STDIN, function (): void {
            $data = fread(\STDIN, 4096);
            $stdinBuffer = $this->stdinBuffer;
            if ($data !== false && $data !== '' && $stdinBuffer !== null) {
                $stdinBuffer->process($data);
                // Flush any pending lone ESC byte. OS terminals deliver
                // complete escape sequences atomically, so a lone \x1b
                // remaining after process() can only mean the Escape key.
                // An InputEvent listener may call stop(), which sets stdinBuffer
                // to null during process().
                $bufferAfterProcess = $this->stdinBuffer;
                if ($bufferAfterProcess !== null) {
                    $bufferAfterProcess->flush();
                }
            }
        });
    }

    public function stop(): void
    {
        if (! $this->started) {
            return;
        }
        $this->started = false;

        // Cancel STDIN watcher
        if ($this->stdinCallbackId !== null) {
            EventLoop::cancel($this->stdinCallbackId);
            $this->stdinCallbackId = null;
        }

        // Cancel signal watcher
        if ($this->signalCallbackId !== null) {
            EventLoop::cancel($this->signalCallbackId);
            $this->signalCallbackId = null;
        }

        if ($this->resizePipeCallbackId !== null) {
            EventLoop::cancel($this->resizePipeCallbackId);
            $this->resizePipeCallbackId = null;
        }

        if ($this->resizePipe !== null) {
            if (\defined('SIGWINCH') && \function_exists('pcntl_signal')) {
                pcntl_signal(\SIGWINCH, \SIG_DFL);
            }

            fclose($this->resizePipe[0]);
            fclose($this->resizePipe[1]);
            $this->resizePipe = null;
        }

        // Disable bracketed paste mode
        $this->write("\x1b[?2004l");

        // Disable Kitty keyboard protocol if we enabled it
        if ($this->kittyProtocolActive) {
            $this->write("\x1b[<u");
            $this->kittyProtocolActive = false;
        }

        // Clear stdin buffer
        if ($this->stdinBuffer !== null) {
            $this->stdinBuffer->clear();
            $this->stdinBuffer = null;
        }

        // Restore terminal state
        if ($this->initialSttyState !== '') {
            shell_exec('stty '.escapeshellarg(trim($this->initialSttyState)));
        }

        $this->onInput = null;
        $this->onResize = null;
        $this->onKittyProtocolActivated = null;
    }

    public function write(string $data): void
    {
        fwrite(\STDOUT, $data);
        fflush(\STDOUT);
    }

    public function getColumns(): int
    {
        if ($this->cachedColumns === null) {
            $this->refreshDimensions();
        }

        return $this->cachedColumns ?? 80;
    }

    public function getRows(): int
    {
        if ($this->cachedRows === null) {
            $this->refreshDimensions();
        }

        return $this->cachedRows ?? 24;
    }

    public function isKittyProtocolActive(): bool
    {
        return $this->kittyProtocolActive;
    }

    public function moveBy(int $lines): void
    {
        if ($lines > 0) {
            $this->write("\x1b[{$lines}B");
        } elseif ($lines < 0) {
            $this->write("\x1b[".(-$lines).'A');
        }
    }

    public function hideCursor(): void
    {
        $this->write("\x1b[?25l");
    }

    public function showCursor(): void
    {
        $this->write("\x1b[?25h");
    }

    public function clearLine(): void
    {
        $this->write("\x1b[2K");
    }

    public function clearFromCursor(): void
    {
        $this->write("\x1b[0J");
    }

    public function clearScreen(): void
    {
        $this->write("\x1b[2J\x1b[H");
    }

    public function setTitle(string $title): void
    {
        $safe = preg_replace("/[\x00-\x1f\x7f]/", '', $title);
        $this->write("\x1b]0;{$safe}\x07");
    }

    public function bell(): void
    {
        if ('Darwin' === \PHP_OS_FAMILY && file_exists('/System/Library/Sounds/Glass.aiff')) {
            // On macOS, play the system sound in the background to avoid
            // blocking the event loop.
            $this->fireAndForget(['afplay', '/System/Library/Sounds/Glass.aiff']);

            return;
        }

        $this->write("\x07");
    }

    public function isVirtual(): bool
    {
        return false;
    }

    /**
     * Start a command in the background (fire-and-forget).
     *
     * The command is backgrounded via the shell so that proc_close()
     * returns immediately without waiting for it to finish, and without
     * leaking process resources or accumulating zombies.
     *
     * @param  list<string>  $command
     */
    private function fireAndForget(array $command): void
    {
        $cmd = implode(' ', array_map('escapeshellarg', $command)).' >/dev/null 2>&1 &';
        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (\is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /**
     * Refresh terminal dimensions from stty.
     */
    private function refreshDimensions(): void
    {
        // Query terminal size directly using stty
        // shell_exec is required here because stty must operate on the
        // process's own tty; proc_open gives the child a pipe, not the tty.
        $sttyOutput = shell_exec('stty size 2>/dev/null');

        if ($sttyOutput !== null && $sttyOutput !== false && preg_match('/^(\d+)\s+(\d+)$/', trim($sttyOutput), $matches)) {
            $this->cachedRows = (int) $matches[1];
            $this->cachedColumns = (int) $matches[2];
        } else {
            // Default fallback
            $this->cachedColumns = 80;
            $this->cachedRows = 24;
        }
    }

    /**
     * Set up StdinBuffer to split batched input into individual sequences.
     */
    private function setupStdinBuffer(): void
    {
        $this->stdinBuffer = new StdinBuffer;

        // Kitty protocol response pattern: \x1b[?<flags>u
        $kittyResponsePattern = '/^\x1b\[\?(\d+)u$/';

        // Forward individual sequences to the input handler
        $this->stdinBuffer->onData(function (string $sequence) use ($kittyResponsePattern): void {
            // Check for Kitty protocol response (only if not already enabled)
            if (! $this->kittyProtocolActive && preg_match($kittyResponsePattern, $sequence)) {
                $this->kittyProtocolActive = true;
                // Enable Kitty keyboard protocol with enhanced features
                // Flag 1 = disambiguate escape codes
                // Flag 2 = report event types (press/repeat/release)
                // Flag 4 = report alternate keys
                $this->write("\x1b[>7u");

                // Notify the TUI that Kitty protocol is active
                if ($this->onKittyProtocolActivated !== null) {
                    ($this->onKittyProtocolActivated)();
                }

                return; // Don't forward protocol response to TUI
            }

            if ($this->onInput !== null) {
                ($this->onInput)($sequence);
            }
        });

        // Re-wrap paste content with bracketed paste markers
        $this->stdinBuffer->onPaste(function (string $content): void {
            if ($this->onInput !== null) {
                ($this->onInput)("\x1b[200~".$content."\x1b[201~");
            }
        });
    }

    private function setupResizeHandling(): void
    {
        if (! \defined('SIGWINCH')) {
            return;
        }

        if (\function_exists('pcntl_signal') && \function_exists('pcntl_async_signals')) {
            $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            if ($pair !== false) {
                stream_set_blocking($pair[0], false);
                stream_set_blocking($pair[1], false);
                $this->resizePipe = $pair;

                $this->resizePipeCallbackId = EventLoop::onReadable($pair[0], function () use ($pair): void {
                    fread($pair[0], 64);
                    $this->triggerResize();
                });

                pcntl_async_signals(true);

                $writeFd = $pair[1];
                pcntl_signal(\SIGWINCH, static function () use ($writeFd): void {
                    @fwrite($writeFd, 'R');
                });

                return;
            }
        }

        $this->signalCallbackId = EventLoop::onSignal(\SIGWINCH, function (): void {
            $this->triggerResize();
        });
    }

    private function triggerResize(): void
    {
        $this->cachedColumns = null;
        $this->cachedRows = null;

        if ($this->onResize !== null) {
            ($this->onResize)(true);
        }
    }

    /**
     * Check if stty is available on this system.
     */
    private function hasSttyAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        if ('\\' === \DIRECTORY_SEPARATOR) {
            return $available = false;
        }

        return $available = (bool) shell_exec('stty 2>/dev/null');
    }
}

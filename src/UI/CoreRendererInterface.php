<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;

/**
 * Core lifecycle and display methods for the rendering layer.
 *
 * Covers initialization, LLM streaming, status display, phase tracking,
 * and teardown. Extended by RendererInterface and implemented by
 * AnsiRenderer, TuiRenderer, NullRenderer, and UIManager (delegating).
 */
interface CoreRendererInterface
{
    /** Bootstrap the renderer (terminal setup, screen state). */
    public function initialize(): void;

    /** Display the KosmoKrator intro splash. */
    public function renderIntro(bool $animated): void;

    /** Read and return user input from the prompt. */
    public function prompt(): string;

    /** Echo the user's submitted message text. */
    public function showUserMessage(string $text): void;

    /** Update the active agent phase for status display. */
    public function setPhase(AgentPhase $phase): void;

    /** Show a thinking/waiting indicator. */
    public function showThinking(): void;

    /** Remove the thinking indicator. */
    public function clearThinking(): void;

    /** Show a context-compacting indicator. */
    public function showCompacting(): void;

    /** Remove the compacting indicator. */
    public function clearCompacting(): void;

    /** Return the current cancellation token, or null if none active. */
    public function getCancellation(): ?Cancellation;

    /** Append a chunk of streamed LLM output to the display. */
    public function streamChunk(string $text): void;

    /** Finalize the current stream (flush buffers, move cursor). */
    public function streamComplete(): void;

    /** Display an error message to the user. */
    public function showError(string $message): void;

    /** Display a non-critical notice to the user. */
    public function showNotice(string $message): void;

    /** Display the current operational mode label (e.g. "Edit", "Plan"). */
    public function showMode(string $label, string $color = ''): void;

    /**
     * Set the current permission mode display for the status bar.
     */
    public function setPermissionMode(string $label, string $color): void;

    /**
     * Display token usage stats and cost in the status bar.
     *
     * @param string $model      Active model identifier
     * @param int    $tokensIn   Input tokens consumed
     * @param int    $tokensOut  Output tokens generated
     * @param float  $cost       Accumulated API cost in USD
     * @param int    $maxContext Maximum context window size
     */
    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void;

    /** Update the displayed provider/model after a runtime model switch. */
    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void;

    /**
     * Consume a message queued during thinking.
     * Returns null if no message was queued.
     */
    public function consumeQueuedMessage(): ?string;

    /**
     * Set a handler for immediate slash commands during agent execution.
     * The closure receives the raw input and returns true if handled.
     *
     * @param  (\Closure(string): bool)|null  $handler
     */
    public function setImmediateCommandHandler(?\Closure $handler): void;

    /** Restore terminal state and clean up resources. */
    public function teardown(): void;

    /** Display the welcome/help screen. */
    public function showWelcome(): void;

    /** Inject the task store for task bar rendering. */
    public function setTaskStore(TaskStore $store): void;

    /** Refresh the task bar display from the store. */
    public function refreshTaskBar(): void;

    /** Play the Theogony intro animation. */
    public function playTheogony(): void;

    /** Play the Prometheus mode animation. */
    public function playPrometheus(): void;

    /** Play the Unleash swarm animation. */
    public function playUnleash(): void;
}

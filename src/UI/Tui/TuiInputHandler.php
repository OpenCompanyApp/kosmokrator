<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Input\InputHistory;
use Kosmokrator\UI\Tui\Input\KeybindingRegistry;
use Kosmokrator\UI\Tui\Toast\ToastManager;
use Kosmokrator\UI\Tui\Widget\CommandPaletteWidget;
use Kosmokrator\UI\Tui\Widget\HelpOverlayWidget;
use Kosmokrator\UI\Tui\Terminal\MouseAction;
use Kosmokrator\UI\Tui\Terminal\MouseParser;
use Kosmokrator\UI\Tui\Widget\ToggleableWidgetInterface;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Encapsulates all EditorWidget event handlers for the TUI prompt.
 *
 * Extracted from TuiCoreRenderer::bindInputHandlers() to isolate the dense
 * callback logic for input, cancel, change, and submit events. Owns the
 * slash/power/skill completion widget and delegates back to TuiCoreRenderer
 * for rendering and shared state access.
 */
final class TuiInputHandler
{
    private ?SelectListWidget $slashCompletion = null;

    private ?HelpOverlayWidget $helpOverlay = null;

    private ?CommandPaletteWidget $commandPalette = null;

    private ?InputHistory $inputHistory = null;

    /** @var array<array{value: string, label: string, description: string}> */
    private array $skillCompletions = [];

    public const SLASH_COMMANDS = [
        ['value' => '/edit', 'label' => '/edit', 'description' => 'Switch to edit mode (full tool access)'],
        ['value' => '/plan', 'label' => '/plan', 'description' => 'Switch to plan mode (read-only)'],
        ['value' => '/ask', 'label' => '/ask', 'description' => 'Switch to ask mode (read-only, conversational)'],
        ['value' => '/guardian', 'label' => '/guardian', 'description' => 'Guardian mode — smart auto-approve for safe operations'],
        ['value' => '/argus', 'label' => '/argus', 'description' => 'Argus mode — ask before every write and command'],
        ['value' => '/prometheus', 'label' => '/prometheus', 'description' => 'Prometheus mode — auto-approve all tool calls'],
        ['value' => '/compact', 'label' => '/compact', 'description' => 'Compact conversation context'],
        ['value' => '/new', 'label' => '/new', 'description' => 'Start a new session (clear history)'],
        ['value' => '/clear', 'label' => '/clear', 'description' => 'Clear the screen'],
        ['value' => '/quit', 'label' => '/quit', 'description' => 'Exit KosmoKrator'],
        ['value' => '/seed', 'label' => '/seed', 'description' => 'Show a mock demo session'],
        ['value' => '/settings', 'label' => '/settings', 'description' => 'Open the settings panel'],
        ['value' => '/resume', 'label' => '/resume', 'description' => 'Resume a previous session'],
        ['value' => '/sessions', 'label' => '/sessions', 'description' => 'List recent sessions'],
        ['value' => '/memories', 'label' => '/memories', 'description' => 'Show stored memories'],
        ['value' => '/forget', 'label' => '/forget', 'description' => 'Delete a memory by ID'],
        ['value' => '/agents', 'label' => '/agents', 'description' => 'Show swarm progress dashboard'],
        ['value' => '/theogony', 'label' => '/theogony', 'description' => 'Play the KosmoKrator origin spectacle'],
        ['value' => '/update', 'label' => '/update', 'description' => 'Check for and install updates'],
        ['value' => '/feedback', 'label' => '/feedback', 'description' => 'Submit feedback or a bug report'],
        ['value' => '/rename', 'label' => '/rename', 'description' => 'Rename the current session'],
    ];

    public const POWER_COMMANDS = [
        ['value' => ':unleash', 'label' => ':unleash', 'description' => 'Unleash a massive swarm of agents on a task'],
        ['value' => ':trace', 'label' => ':trace', 'description' => 'Evidence-driven deep trace analysis'],
        ['value' => ':autopilot', 'label' => ':autopilot', 'description' => 'Full autonomous pipeline from idea to verified code'],
        ['value' => ':deslop', 'label' => ':deslop', 'description' => 'Regression-safe cleanup of AI-generated bloat'],
        ['value' => ':deepinit', 'label' => ':deepinit', 'description' => 'Deep codebase documentation and knowledge map'],
        ['value' => ':ralph', 'label' => ':ralph', 'description' => 'Persistent retry loop — the boulder never stops'],
        ['value' => ':team', 'label' => ':team', 'description' => 'Staged pipeline with specialized agent roles'],
        ['value' => ':ultraqa', 'label' => ':ultraqa', 'description' => 'Autonomous QA cycling until all tests pass'],
        ['value' => ':interview', 'label' => ':interview', 'description' => 'Socratic requirements gathering'],
        ['value' => ':doctor', 'label' => ':doctor', 'description' => 'Self-diagnostic check of environment and project'],
        ['value' => ':learner', 'label' => ':learner', 'description' => 'Extract a reusable pattern from this conversation'],
        ['value' => ':cancel', 'label' => ':cancel', 'description' => 'Gracefully cancel any active workflow or swarm'],
        ['value' => ':replay', 'label' => ':replay', 'description' => 'Replay and modify a previous workflow'],
        ['value' => ':review', 'label' => ':review', 'description' => 'Parallel code review across 4 dimensions'],
        ['value' => ':research', 'label' => ':research', 'description' => 'Parallel research agents for investigation'],
        ['value' => ':deepdive', 'label' => ':deepdive', 'description' => 'Trace the WHY, then define the WHAT'],
        ['value' => ':babysit', 'label' => ':babysit', 'description' => 'Monitor a PR until merged'],
        ['value' => ':release', 'label' => ':release', 'description' => 'Automated release: bump, test, tag, publish'],
        ['value' => ':docs', 'label' => ':docs', 'description' => 'Audit and refresh documentation'],
        ['value' => ':consensus', 'label' => ':consensus', 'description' => 'Planner → Architect → Critic deliberation'],
    ];

    public const DOLLAR_COMMANDS = [
        ['value' => '$list', 'label' => '$list', 'description' => 'List all available skills'],
        ['value' => '$create', 'label' => '$create', 'description' => 'Create a new skill'],
        ['value' => '$show', 'label' => '$show', 'description' => 'Show skill details'],
        ['value' => '$edit', 'label' => '$edit', 'description' => 'Edit an existing skill'],
        ['value' => '$delete', 'label' => '$delete', 'description' => 'Delete a skill'],
    ];

    /**
     * @param  EditorWidget  $input  The prompt editor widget to bind handlers on
     * @param  ContainerWidget  $conversation  The conversation container (for toggling tool results)
     * @param  ContainerWidget  $overlay  The overlay container (for completion dropdown)
     * @param  TuiModalManager  $modalManager  Modal manager (for ask suspension state)
     * @param  \Closure(): void  $flushRender  Triggers a non-forced render
     * @param  \Closure(): void  $forceRender  Triggers a forced render
     * @param  \Closure(): void  $scrollHistoryUp  Scrolls conversation history up
     * @param  \Closure(): void  $scrollHistoryDown  Scrolls conversation history down
     * @param  \Closure(): void  $jumpToLiveOutput  Jumps scroll to live output
     * @param  \Closure(): bool  $isBrowsingHistory  Whether the user is browsing scroll history
     * @param  \Closure(): string  $cycleMode  Returns the next mode name
     * @param  \Closure(string, string): void  $showMode  Sets the display mode label+color
     * @param  \Closure(string): void  $queueMessage  Queues a user message and shows it in the conversation
     * @param  \Closure(string): void  $queueMessageSilent  Queues a user message without displaying it
     * @param  \Closure(): ((\Closure(string): bool)|null)  $getImmediateCommandHandler  Returns the current immediate command handler
     * @param  \Closure(): (?Suspension)  $getPromptSuspension  Returns the current prompt suspension
     * @param  \Closure(null): void  $clearPromptSuspension  Clears the prompt suspension
     * @param  \Closure(?string): void  $setPendingEditorRestore  Sets pending editor restore value
     * @param  \Closure(): ?\Amp\DeferredCancellation  $getRequestCancellation  Returns the current request cancellation
     * @param  \Closure(null): void  $clearRequestCancellation  Clears the request cancellation
     */
    public function __construct(
        private readonly EditorWidget $input,
        private readonly ContainerWidget $conversation,
        private readonly ContainerWidget $overlay,
        private readonly TuiModalManager $modalManager,
        private readonly \Closure $flushRender,
        private readonly \Closure $forceRender,
        private readonly \Closure $scrollHistoryUp,
        private readonly \Closure $scrollHistoryDown,
        private readonly \Closure $jumpToLiveOutput,
        private readonly \Closure $isBrowsingHistory,
        private readonly \Closure $cycleMode,
        private readonly \Closure $showMode,
        private readonly \Closure $queueMessage,
        private readonly \Closure $queueMessageSilent,
        private readonly \Closure $getImmediateCommandHandler,
        private readonly \Closure $getPromptSuspension,
        private readonly \Closure $clearPromptSuspension,
        private readonly \Closure $setPendingEditorRestore,
        private readonly \Closure $getRequestCancellation,
        private readonly \Closure $clearRequestCancellation,
        private readonly ?KeybindingRegistry $keybindingRegistry = null,
    ) {}

    /**
     * Inject the input history store for Up/Down navigation and Ctrl+R search.
     */
    public function setInputHistory(InputHistory $history): void
    {
        $this->inputHistory = $history;
    }

    /**
     * Inject the command palette for Ctrl+K handling.
     */
    public function setCommandPalette(CommandPaletteWidget $palette): void
    {
        $this->commandPalette = $palette;
    }

    /**
     * @param  array<array{value: string, label: string, description: string}>  $completions
     */
    public function setSkillCompletions(array $completions): void
    {
        $this->skillCompletions = $completions;
    }

    /**
     * Register all event handlers on the input widget.
     */
    public function bind(): void
    {
        $this->input->onInput($this->handleInput(...));
        $this->input->onCancel($this->handleCancel(...));
        $this->input->onChange($this->handleChange(...));
        $this->input->onSubmit($this->handleSubmit(...));
    }

    private function handleInput(string $data): bool
    {
        $kb = $this->input->getKeybindings();

        // -- Mouse scroll events (SGR-1006) --
        if (str_starts_with($data, "\x1b[<")) {
            $mouseEvent = (new MouseParser())->parse($data);
            if ($mouseEvent !== null) {
                if ($mouseEvent->action === MouseAction::ScrollUp) {
                    ($this->scrollHistoryUp)();

                    return true;
                }
                if ($mouseEvent->action === MouseAction::ScrollDown) {
                    ($this->scrollHistoryDown)();

                    return true;
                }
            }

            // Consume unrecognised mouse events so they don't leak
            return true;
        }

        // -- Help overlay toggle (?) or dismiss (Esc) --
        if ($kb->matches($data, 'help')) {
            if ($this->helpOverlay !== null) {
                $this->hideHelpOverlay();
            } else {
                $this->showHelpOverlay();
            }

            return true;
        }

        // Escape also dismisses help overlay
        if ($data === "\x1b" && $this->helpOverlay !== null) {
            $this->hideHelpOverlay();

            return true;
        }

        // -- Command palette (Ctrl+K) --
        if ($this->commandPalette !== null && $this->commandPalette->isVisible()) {
            return $this->commandPalette->handleInput($data);
        }

        if ($kb->matches($data, 'command_palette') && $this->commandPalette !== null) {
            $this->commandPalette->show();
            ($this->flushRender)();

            return true;
        }

        // -- Reverse-search mode (Ctrl+R) --
        if ($this->inputHistory?->isReverseSearching() === true) {
            // Ctrl+R again → cycle to next match
            if ($data === "\x12") {
                $match = $this->inputHistory->cycleReverseSearch();
                if ($match !== null) {
                    $this->input->setText($match);
                }
                ($this->flushRender)();

                return true;
            }

            // Enter → accept match
            if ($kb->matches($data, 'submit')) {
                $match = $this->inputHistory->acceptReverseSearch();
                if ($match !== null) {
                    $this->input->setText($match);
                }
                ($this->flushRender)();

                return true;
            }

            // Escape / Ctrl+C → cancel reverse search
            if ($data === "\x1b" || $data === "\x03") {
                $original = $this->inputHistory->cancelReverseSearch();
                $this->input->setText($original ?? '');
                ($this->flushRender)();

                return true;
            }

            // Backspace → shorten query
            if ($kb->matches($data, 'delete_char_backward') && $this->inputHistory->getReverseSearchQuery() !== '') {
                $query = mb_substr($this->inputHistory->getReverseSearchQuery(), 0, -1);
                $match = $this->inputHistory->updateReverseSearch($query);
                $this->input->setText($match ?? '');
                ($this->flushRender)();

                return true;
            }

            // Regular printable character → extend query
            $ord = ord($data[0] ?? "\x00");
            if ($ord >= 32 && ! str_starts_with($data, "\x1b") && mb_strlen($data) === 1) {
                $query = $this->inputHistory->getReverseSearchQuery() . $data;
                $match = $this->inputHistory->updateReverseSearch($query);
                $this->input->setText($match ?? '');
                ($this->flushRender)();

                return true;
            }

            // Any other key in reverse-search mode is swallowed
            return true;
        }

        if ($this->slashCompletion !== null) {
            if ($kb->matches($data, 'cursor_up') || $kb->matches($data, 'cursor_down')) {
                $this->slashCompletion->handleInput($data);
                ($this->flushRender)();

                return true;
            }
            if ($kb->matches($data, 'submit')) {
                $selected = $this->slashCompletion->getSelectedItem();
                if ($selected !== null) {
                    $command = $selected['value'];
                    // For combined power commands, replace only the last :segment
                    $currentText = $this->input->getText();
                    if (str_starts_with($command, ':') && ($lastColon = strrpos($currentText, ':')) > 0) {
                        $command = substr($currentText, 0, $lastColon).$command;
                    }
                    $this->input->setText('');
                    $this->hideSlashCompletion();
                    $suspension = ($this->getPromptSuspension)();
                    if ($suspension !== null) {
                        ($this->clearPromptSuspension)(null);
                        $suspension->resume($command);
                    }
                }

                return true;
            }
            if ($data === "\t") {
                $selected = $this->slashCompletion->getSelectedItem();
                if ($selected !== null) {
                    $tabValue = $selected['value'];
                    // For combined power commands, replace only the last :segment
                    $currentText = $this->input->getText();
                    if (str_starts_with($tabValue, ':') && ($lastColon = strrpos($currentText, ':')) > 0) {
                        $tabValue = substr($currentText, 0, $lastColon).$tabValue;
                    }
                    $this->input->setText($tabValue.' ');
                }
                $this->hideSlashCompletion();

                return true;
            }
            if ($data === "\x1b") {
                $this->hideSlashCompletion();

                return true;
            }
        }

        // -- Input history navigation (Up/Down arrows) --
        if ($kb->matches($data, 'cursor_up') && $this->inputHistory !== null) {
            $currentText = $this->input->getText();
            $recalled = $this->inputHistory->navigateOlder($currentText);
            if ($recalled !== null) {
                $this->input->setText($recalled);
                ($this->flushRender)();

                return true;
            }
            // No older entry: fall through to default cursor_up handling
        }

        if ($kb->matches($data, 'cursor_down') && $this->inputHistory !== null) {
            if ($this->inputHistory->isNavigating()) {
                $recalled = $this->inputHistory->navigateNewer();
                if ($recalled !== null) {
                    $this->input->setText($recalled);
                    ($this->flushRender)();

                    return true;
                }
            }
        }

        if ($this->keybindingRegistry !== null && !$this->inputHistory?->isReverseSearching()) {
            // Check if the actual input data is Ctrl+A (\x01)
            if ($data === "\x01") {
                $action = $this->keybindingRegistry->resolve('normal', 'ctrl+a');
                if ($action === 'agents_dashboard') {
                    $handler = ($this->getImmediateCommandHandler)();
                    if ($handler !== null) {
                        $handler('/agents');
                    }

                    return true;
                }
            }
        }

        if ($kb->matches($data, 'history_up')) {
            ($this->scrollHistoryUp)();

            return true;
        }

        if ($kb->matches($data, 'history_down')) {
            ($this->scrollHistoryDown)();

            return true;
        }

        if (($this->isBrowsingHistory)() && $kb->matches($data, 'history_end')) {
            ($this->jumpToLiveOutput)();

            return true;
        }

        if ($data === "\x0C") {
            ($this->forceRender)();

            return true;
        }

        if ($kb->matches($data, 'expand_tools')) {
            $this->toggleAllToolResults();

            return true;
        }

        if ($kb->matches($data, 'cycle_mode')) {
            $nextMode = ($this->cycleMode)();

            $suspension = ($this->getPromptSuspension)();
            if ($suspension !== null) {
                $savedText = $this->input->getText();
                ($this->clearPromptSuspension)(null);
                ($this->setPendingEditorRestore)($savedText);
                $suspension->resume("/{$nextMode}");
            } else {
                $modeColors = [
                    'edit' => Theme::rgb(80, 200, 120),
                    'plan' => Theme::agentPlan(),
                    'ask' => Theme::rgb(255, 180, 60),
                ];
                ($this->showMode)(ucfirst($nextMode), $modeColors[$nextMode] ?? '');
                ToastManager::info("Mode: {$nextMode}", 2000);
                ($this->queueMessageSilent)("/{$nextMode}");
                ($this->getRequestCancellation)()?->cancel();
                ($this->clearRequestCancellation)(null);
            }

            return true;
        }

        // -- Enter reverse-search mode (Ctrl+R) --
        if ($data === "\x12" && $this->inputHistory !== null) {
            $this->inputHistory->startReverseSearch($this->input->getText());
            $this->input->setText('');
            ($this->flushRender)();

            return true;
        }

        return false;
    }

    private function handleCancel(): void
    {
        $askSuspension = $this->modalManager->getAskSuspension();
        if ($askSuspension !== null) {
            $this->modalManager->clearAskSuspension();
            $askSuspension->resume('');

            return;
        }

        $cancellation = ($this->getRequestCancellation)();
        if ($cancellation !== null) {
            $cancellation->cancel();
            ($this->clearRequestCancellation)(null);

            return;
        }

        $suspension = ($this->getPromptSuspension)();
        if ($suspension !== null) {
            ($this->clearPromptSuspension)(null);
            $suspension->resume('/quit');

            return;
        }

        $handler = ($this->getImmediateCommandHandler)();
        if ($handler !== null) {
            $handler('/quit');
        }
    }

    private function handleChange(ChangeEvent $event): void
    {
        $value = $event->getValue();

        // Reset history navigation when the user types while navigating
        $this->inputHistory?->resetNavigation();

        if (str_starts_with($value, '/') && $value !== '/') {
            $this->showCommandCompletion($value, self::SLASH_COMMANDS);
        } elseif ($value === '/') {
            $this->showCommandCompletion('', self::SLASH_COMMANDS);
        } elseif (str_starts_with($value, ':')) {
            // For combined commands, complete only the last :segment
            $lastColon = strrpos($value, ':');
            $filter = substr($value, $lastColon);
            $this->showCommandCompletion($filter === ':' ? '' : $filter, self::POWER_COMMANDS);
        } elseif (str_starts_with($value, '$')) {
            $filter = $value === '$' ? '' : $value;
            $this->showCommandCompletion($filter, array_merge(self::DOLLAR_COMMANDS, $this->skillCompletions));
        } else {
            $this->hideSlashCompletion();
        }
    }

    private function handleSubmit(SubmitEvent $event): void
    {
        $value = $event->getValue();

        // Record the submitted text in input history (deduplication handled inside)
        $this->inputHistory?->add($value);

        $this->input->setText('');
        $this->hideSlashCompletion();

        $askSuspension = $this->modalManager->getAskSuspension();
        if ($askSuspension !== null) {
            $this->modalManager->clearAskSuspension();
            $askSuspension->resume($value);

            return;
        }

        $cancellation = ($this->getRequestCancellation)();
        if ($cancellation !== null) {
            if (trim($value) !== '') {
                $handler = ($this->getImmediateCommandHandler)();
                if ($handler !== null && $handler($value)) {
                    return;
                }
                ($this->queueMessage)($value);
            }

            return;
        }

        $suspension = ($this->getPromptSuspension)();
        if ($suspension !== null) {
            ($this->clearPromptSuspension)(null);
            $suspension->resume($value);

            return;
        }

        if (trim($value) !== '') {
            ($this->queueMessage)($value);
        }
    }

    /**
     * @param  array<array{value: string, label: string, description: string}>  $commands
     */
    private function showCommandCompletion(string $filter, array $commands): void
    {
        $filtered = array_values(array_filter(
            $commands,
            fn (array $cmd) => $filter === '' || str_starts_with($cmd['value'], $filter),
        ));

        if ($filtered === []) {
            $this->hideSlashCompletion();

            return;
        }

        if ($this->slashCompletion === null) {
            $this->slashCompletion = new SelectListWidget($filtered);
            $this->slashCompletion->setId('slash-completion');
            $this->slashCompletion->addStyleClass('slash-completion');
            $this->overlay->add($this->slashCompletion);
        } else {
            $this->slashCompletion->setItems($filtered);
        }

        ($this->flushRender)();
    }

    private function hideSlashCompletion(): void
    {
        if ($this->slashCompletion !== null) {
            $this->overlay->remove($this->slashCompletion);
            $this->slashCompletion = null;
            ($this->flushRender)();
        }
    }

    private function toggleAllToolResults(): void
    {
        $toggle = function (array $widgets) use (&$toggle): void {
            foreach ($widgets as $widget) {
                if ($widget instanceof ToggleableWidgetInterface) {
                    $widget->toggle();
                }
                if ($widget instanceof ContainerWidget) {
                    $toggle($widget->all());
                }
            }
        };
        $toggle($this->conversation->all());
        ($this->flushRender)();
    }

    /**
     * Show the help overlay widget on top of the conversation.
     */
    private function showHelpOverlay(): void
    {
        if ($this->helpOverlay !== null) {
            return;
        }

        $this->helpOverlay = new HelpOverlayWidget($this->keybindingRegistry);
        $this->helpOverlay->setId('help-overlay');
        $this->helpOverlay->addStyleClass('overlay');
        $this->conversation->add($this->helpOverlay);
        ($this->flushRender)();
    }

    /**
     * Hide the help overlay widget.
     */
    private function hideHelpOverlay(): void
    {
        if ($this->helpOverlay === null) {
            return;
        }

        $this->conversation->remove($this->helpOverlay);
        $this->helpOverlay = null;
        ($this->flushRender)();
    }
}

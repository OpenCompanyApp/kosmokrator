<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\State;

use Amp\DeferredCancellation;
use Athanor\BatchScope;
use Athanor\Computed;
use Athanor\Signal;

/**
 * Centralized reactive state store for the TUI.
 *
 * Every piece of observable UI state lives here as a Signal, so that
 * widgets and renderers can subscribe to fine-grained changes instead of
 * polling or re-rendering everything on each frame.
 *
 * Computed values (e.g. statusBarMessage) are derived from the signals and
 * auto-update when their dependencies change.
 */
final class TuiStateStore
{
    // ── Mode / Permission ──────────────────────────────────────────────

    /** @var Signal<string> */
    private Signal $modeLabel;

    /** @var Signal<string> */
    private Signal $modeColor;

    /** @var Signal<string> */
    private Signal $permissionLabel;

    /** @var Signal<string> */
    private Signal $permissionColor;

    // ── Status / Tokens ────────────────────────────────────────────────

    /** @var Signal<string> */
    private Signal $statusDetail;

    /** @var Signal<?int> */
    private Signal $tokensIn;

    /** @var Signal<?int> */
    private Signal $tokensOut;

    /** @var Signal<?float> */
    private Signal $cost;

    /** @var Signal<?int> */
    private Signal $maxContext;

    /** @var Signal<string> */
    private Signal $model;

    // ── Phase ──────────────────────────────────────────────────────────

    /** @var Signal<string> */
    private Signal $phase;

    // ── Scroll / History ───────────────────────────────────────────────

    /** @var Signal<int> */
    private Signal $scrollOffset;

    /** @var Signal<bool> */
    private Signal $hasHiddenActivityBelow;

    // ── Session ────────────────────────────────────────────────────────

    /** @var Signal<string> */
    private Signal $sessionTitle;

    /** @var Signal<int> */
    private Signal $errorCount;

    // ── Streaming ──────────────────────────────────────────────────────

    /** @var Signal<mixed> KosmokratorMarkdownWidget|AnsiArtWidget|null */
    private Signal $activeResponse;

    /** @var Signal<bool> */
    private Signal $activeResponseIsAnsi;

    // ── Input / Prompt ─────────────────────────────────────────────────

    /** @var Signal<?string> */
    private Signal $pendingEditorRestore;

    /** @var Signal<?DeferredCancellation> */
    private Signal $requestCancellation;

    /** @var Signal<?string> */
    private Signal $focusedWidgetId;

    /** @var Signal<list<string>> */
    private Signal $messageQueue;

    /** @var Signal<list<array>> */
    private Signal $pendingQuestionRecap;

    // ── Animation ──────────────────────────────────────────────────────

    /** @var Signal<?string> ANSI color escape */
    private Signal $breathColor;

    /** @var Signal<?string> */
    private Signal $thinkingPhrase;

    /** @var Signal<float> */
    private Signal $thinkingStartTime;

    /** @var Signal<int> */
    private Signal $breathTick;

    /** @var Signal<float> */
    private Signal $compactingStartTime;

    /** @var Signal<int> */
    private Signal $compactingBreathTick;

    /** @var Signal<int> */
    private Signal $spinnerIndex;

    // ── Subagent ───────────────────────────────────────────────────────

    /** @var Signal<bool> */
    private Signal $batchDisplayed;

    /** @var Signal<int> */
    private Signal $loaderBreathTick;

    /** @var Signal<string> */
    private Signal $cachedLoaderLabel;

    /** @var Signal<float> */
    private Signal $startTime;

    /** @var Signal<bool> */
    private Signal $hasRunningAgents;

    // ── Tool state ─────────────────────────────────────────────────────

    /** @var Signal<array> */
    private Signal $lastToolArgs;

    /** @var Signal<array<string, array>> */
    private Signal $lastToolArgsByName;

    /** @var Signal<mixed> BashCommandWidget|null */
    private Signal $activeBashWidget;

    /** @var Signal<?string> */
    private Signal $toolExecutingPreview;

    /** @var Signal<list<array>> */
    private Signal $activeDiscoveryItems;

    /** @var Signal<int> */
    private Signal $toolExecutingBreathTick;

    /** @var Signal<float> */
    private Signal $toolExecutingStartTime;

    /** @var Signal<bool> */
    private Signal $hasThinkingLoader;

    /** @var Signal<bool> */
    private Signal $hasCompactingLoader;

    // ── Modal ──────────────────────────────────────────────────────────

    /** @var Signal<bool> */
    private Signal $activeModal;

    // ── Task / Has tasks ───────────────────────────────────────────────

    /** @var Signal<bool> */
    private Signal $hasTasks;

    /** @var Signal<bool> */
    private Signal $hasSubagentActivity;

    // ── Render trigger ─────────────────────────────────────────────────

    /** @var Signal<int> Monotonically increasing counter to trigger renders */
    private Signal $renderTrigger;

    // ── Computed ───────────────────────────────────────────────────────

    private Computed $contextPercent;

    private Computed $isBrowsingHistory;

    private Computed $statusBarMessage;

    public function __construct()
    {
        // Mode / Permission
        $this->modeLabel = new Signal('Edit');
        $this->modeColor = new Signal("\033[38;2;80;200;120m");
        $this->permissionLabel = new Signal('Guardian ◈');
        $this->permissionColor = new Signal("\033[38;2;180;180;200m");

        // Status / Tokens
        $this->statusDetail = new Signal('Ready');
        $this->tokensIn = self::nullable();
        $this->tokensOut = self::nullable();
        $this->cost = self::nullable();
        $this->maxContext = self::nullable();
        $this->model = new Signal('');

        // Phase
        $this->phase = new Signal('idle');

        // Scroll / History
        $this->scrollOffset = new Signal(0);
        $this->hasHiddenActivityBelow = new Signal(false);

        // Session
        $this->sessionTitle = new Signal('');
        $this->errorCount = new Signal(0);

        // Streaming
        $this->activeResponse = self::nullable();
        $this->activeResponseIsAnsi = new Signal(false);

        // Input / Prompt
        $this->pendingEditorRestore = self::nullable();
        $this->requestCancellation = self::nullable();
        $this->focusedWidgetId = self::nullable();
        $this->messageQueue = self::arrayOf();
        $this->pendingQuestionRecap = self::arrayOf();

        // Animation
        $this->breathColor = self::nullable();
        $this->thinkingPhrase = self::nullable();
        $this->thinkingStartTime = new Signal(0.0);
        $this->breathTick = new Signal(0);
        $this->compactingStartTime = new Signal(0.0);
        $this->compactingBreathTick = new Signal(0);
        $this->spinnerIndex = new Signal(0);

        // Subagent
        $this->batchDisplayed = new Signal(false);
        $this->loaderBreathTick = new Signal(0);
        $this->cachedLoaderLabel = new Signal('Agents running...');
        $this->startTime = new Signal(0.0);
        $this->hasRunningAgents = new Signal(false);

        // Tool state
        $this->lastToolArgs = self::arrayOf();
        $this->lastToolArgsByName = self::arrayOf();
        $this->activeBashWidget = self::nullable();
        $this->toolExecutingPreview = self::nullable();
        $this->activeDiscoveryItems = self::arrayOf();
        $this->toolExecutingBreathTick = new Signal(0);
        $this->toolExecutingStartTime = new Signal(0.0);
        $this->hasThinkingLoader = new Signal(false);
        $this->hasCompactingLoader = new Signal(false);

        // Modal
        $this->activeModal = new Signal(false);

        // Task / Has tasks
        $this->hasTasks = new Signal(false);
        $this->hasSubagentActivity = new Signal(false);

        // Render trigger
        $this->renderTrigger = new Signal(0);

        // ── Computed values ────────────────────────────────────────────

        $this->contextPercent = new Computed(function (): float {
            $max = $this->maxContext->get();

            if ($max === null || $max <= 0) {
                return 0.0;
            }

            $in = $this->tokensIn->get() ?? 0;

            return ($in / $max) * 100.0;
        });

        $this->isBrowsingHistory = new Computed(fn (): bool => $this->scrollOffset->get() > 0);

        $this->statusBarMessage = new Computed(function (): string {
            $r = "\033[0m";
            $sep = "\033[2m·{$r}";

            return "{$this->modeColor->get()}{$this->modeLabel->get()}{$r} {$sep} "
                ."{$this->permissionColor->get()}{$this->permissionLabel->get()}{$r} {$sep} "
                .$this->statusDetail->get();
        });
    }

    // ── Mode / Permission ──────────────────────────────────────────────

    public function getModeLabel(): string
    {
        return $this->modeLabel->get();
    }

    public function setModeLabel(string $v): void
    {
        $this->modeLabel->set($v);
    }

    public function modeLabelSignal(): Signal
    {
        return $this->modeLabel;
    }

    public function getModeColor(): string
    {
        return $this->modeColor->get();
    }

    public function setModeColor(string $v): void
    {
        $this->modeColor->set($v);
    }

    public function modeColorSignal(): Signal
    {
        return $this->modeColor;
    }

    public function getPermissionLabel(): string
    {
        return $this->permissionLabel->get();
    }

    public function setPermissionLabel(string $v): void
    {
        $this->permissionLabel->set($v);
    }

    public function permissionLabelSignal(): Signal
    {
        return $this->permissionLabel;
    }

    public function getPermissionColor(): string
    {
        return $this->permissionColor->get();
    }

    public function setPermissionColor(string $v): void
    {
        $this->permissionColor->set($v);
    }

    public function permissionColorSignal(): Signal
    {
        return $this->permissionColor;
    }

    // ── Status / Tokens ────────────────────────────────────────────────

    public function getStatusDetail(): string
    {
        return $this->statusDetail->get();
    }

    public function setStatusDetail(string $v): void
    {
        $this->statusDetail->set($v);
    }

    public function statusDetailSignal(): Signal
    {
        return $this->statusDetail;
    }

    public function getTokensIn(): ?int
    {
        return $this->tokensIn->get();
    }

    public function setTokensIn(?int $v): void
    {
        $this->tokensIn->set($v);
    }

    public function tokensInSignal(): Signal
    {
        return $this->tokensIn;
    }

    public function getTokensOut(): ?int
    {
        return $this->tokensOut->get();
    }

    public function setTokensOut(?int $v): void
    {
        $this->tokensOut->set($v);
    }

    public function tokensOutSignal(): Signal
    {
        return $this->tokensOut;
    }

    public function getCost(): ?float
    {
        return $this->cost->get();
    }

    public function setCost(?float $v): void
    {
        $this->cost->set($v);
    }

    public function costSignal(): Signal
    {
        return $this->cost;
    }

    public function getMaxContext(): ?int
    {
        return $this->maxContext->get();
    }

    public function setMaxContext(?int $v): void
    {
        $this->maxContext->set($v);
    }

    public function maxContextSignal(): Signal
    {
        return $this->maxContext;
    }

    public function getModel(): string
    {
        return $this->model->get();
    }

    public function setModel(string $v): void
    {
        $this->model->set($v);
    }

    public function modelSignal(): Signal
    {
        return $this->model;
    }

    // ── Phase ──────────────────────────────────────────────────────────

    public function getPhase(): string
    {
        return $this->phase->get();
    }

    public function setPhase(string $v): void
    {
        $this->phase->set($v);
    }

    public function phaseSignal(): Signal
    {
        return $this->phase;
    }

    // ── Scroll / History ───────────────────────────────────────────────

    public function getScrollOffset(): int
    {
        return $this->scrollOffset->get();
    }

    public function setScrollOffset(int $v): void
    {
        $this->scrollOffset->set($v);
    }

    public function scrollOffsetSignal(): Signal
    {
        return $this->scrollOffset;
    }

    public function getHasHiddenActivityBelow(): bool
    {
        return $this->hasHiddenActivityBelow->get();
    }

    public function setHasHiddenActivityBelow(bool $v): void
    {
        $this->hasHiddenActivityBelow->set($v);
    }

    public function hasHiddenActivityBelowSignal(): Signal
    {
        return $this->hasHiddenActivityBelow;
    }

    // ── Session ────────────────────────────────────────────────────────

    public function getSessionTitle(): string
    {
        return $this->sessionTitle->get();
    }

    public function setSessionTitle(string $v): void
    {
        $this->sessionTitle->set($v);
    }

    public function sessionTitleSignal(): Signal
    {
        return $this->sessionTitle;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount->get();
    }

    public function setErrorCount(int $v): void
    {
        $this->errorCount->set($v);
    }

    public function errorCountSignal(): Signal
    {
        return $this->errorCount;
    }

    // ── Streaming ──────────────────────────────────────────────────────

    public function getActiveResponse(): mixed
    {
        return $this->activeResponse->get();
    }

    public function setActiveResponse(mixed $v): void
    {
        $this->activeResponse->set($v);
    }

    public function activeResponseSignal(): Signal
    {
        return $this->activeResponse;
    }

    public function getActiveResponseIsAnsi(): bool
    {
        return $this->activeResponseIsAnsi->get();
    }

    public function setActiveResponseIsAnsi(bool $v): void
    {
        $this->activeResponseIsAnsi->set($v);
    }

    public function activeResponseIsAnsiSignal(): Signal
    {
        return $this->activeResponseIsAnsi;
    }

    // ── Input / Prompt ─────────────────────────────────────────────────

    public function getPendingEditorRestore(): ?string
    {
        return $this->pendingEditorRestore->get();
    }

    public function setPendingEditorRestore(?string $v): void
    {
        $this->pendingEditorRestore->set($v);
    }

    public function pendingEditorRestoreSignal(): Signal
    {
        return $this->pendingEditorRestore;
    }

    public function getRequestCancellation(): ?DeferredCancellation
    {
        return $this->requestCancellation->get();
    }

    public function setRequestCancellation(?DeferredCancellation $v): void
    {
        $this->requestCancellation->set($v);
    }

    public function requestCancellationSignal(): Signal
    {
        return $this->requestCancellation;
    }

    public function getFocusedWidgetId(): ?string
    {
        return $this->focusedWidgetId->get();
    }

    public function setFocusedWidgetId(?string $v): void
    {
        $this->focusedWidgetId->set($v);
    }

    public function focusedWidgetIdSignal(): Signal
    {
        return $this->focusedWidgetId;
    }

    public function getMessageQueue(): array
    {
        return $this->messageQueue->get();
    }

    public function setMessageQueue(array $v): void
    {
        $this->messageQueue->set($v);
    }

    public function messageQueueSignal(): Signal
    {
        return $this->messageQueue;
    }

    /** Push a message onto the queue. */
    public function pushMessage(string $message): void
    {
        $this->messageQueue->update(fn (array $q): array => [...$q, $message]);
    }

    /** Shift a message off the queue. */
    public function shiftMessage(): ?string
    {
        $queue = $this->messageQueue->get();
        if ($queue === []) {
            return null;
        }

        $message = array_shift($queue);
        $this->messageQueue->set($queue);

        return $message;
    }

    public function getPendingQuestionRecap(): array
    {
        return $this->pendingQuestionRecap->get();
    }

    public function setPendingQuestionRecap(array $v): void
    {
        $this->pendingQuestionRecap->set($v);
    }

    public function pendingQuestionRecapSignal(): Signal
    {
        return $this->pendingQuestionRecap;
    }

    /** Push a Q&A pair onto the recap list. */
    public function pushQuestionRecap(string $question, string $answer, bool $answered, bool $recommended = false): void
    {
        $this->pendingQuestionRecap->update(function (array $recap) use ($question, $answer, $answered, $recommended): array {
            $recap[] = [
                'question' => $question,
                'answer' => $answer,
                'answered' => $answered,
                'recommended' => $answered && $recommended,
            ];

            return $recap;
        });
    }

    /** Clear and return the pending Q&A pairs. */
    public function drainQuestionRecap(): array
    {
        $recap = $this->pendingQuestionRecap->get();
        $this->pendingQuestionRecap->set([]);

        return $recap;
    }

    // ── Animation ──────────────────────────────────────────────────────

    public function getBreathColor(): ?string
    {
        return $this->breathColor->get();
    }

    public function setBreathColor(?string $v): void
    {
        $this->breathColor->set($v);
    }

    public function breathColorSignal(): Signal
    {
        return $this->breathColor;
    }

    public function getThinkingPhrase(): ?string
    {
        return $this->thinkingPhrase->get();
    }

    public function setThinkingPhrase(?string $v): void
    {
        $this->thinkingPhrase->set($v);
    }

    public function thinkingPhraseSignal(): Signal
    {
        return $this->thinkingPhrase;
    }

    public function getThinkingStartTime(): float
    {
        return $this->thinkingStartTime->get();
    }

    public function setThinkingStartTime(float $v): void
    {
        $this->thinkingStartTime->set($v);
    }

    public function thinkingStartTimeSignal(): Signal
    {
        return $this->thinkingStartTime;
    }

    public function getBreathTick(): int
    {
        return $this->breathTick->get();
    }

    public function setBreathTick(int $v): void
    {
        $this->breathTick->set($v);
    }

    public function breathTickSignal(): Signal
    {
        return $this->breathTick;
    }

    /** Increment breath tick by 1. */
    public function tickBreath(): void
    {
        $this->breathTick->update(fn (int $t): int => $t + 1);
    }

    public function getCompactingStartTime(): float
    {
        return $this->compactingStartTime->get();
    }

    public function setCompactingStartTime(float $v): void
    {
        $this->compactingStartTime->set($v);
    }

    public function compactingStartTimeSignal(): Signal
    {
        return $this->compactingStartTime;
    }

    public function getCompactingBreathTick(): int
    {
        return $this->compactingBreathTick->get();
    }

    public function setCompactingBreathTick(int $v): void
    {
        $this->compactingBreathTick->set($v);
    }

    public function compactingBreathTickSignal(): Signal
    {
        return $this->compactingBreathTick;
    }

    /** Increment compacting breath tick by 1. */
    public function tickCompactingBreath(): void
    {
        $this->compactingBreathTick->update(fn (int $t): int => $t + 1);
    }

    public function getSpinnerIndex(): int
    {
        return $this->spinnerIndex->get();
    }

    public function setSpinnerIndex(int $v): void
    {
        $this->spinnerIndex->set($v);
    }

    public function spinnerIndexSignal(): Signal
    {
        return $this->spinnerIndex;
    }

    /** Increment and return the spinner allocation index. */
    public function allocateSpinner(): int
    {
        $idx = $this->spinnerIndex->get();
        $this->spinnerIndex->set($idx + 1);

        return $idx;
    }

    // ── Subagent ───────────────────────────────────────────────────────

    public function getBatchDisplayed(): bool
    {
        return $this->batchDisplayed->get();
    }

    public function setBatchDisplayed(bool $v): void
    {
        $this->batchDisplayed->set($v);
    }

    public function batchDisplayedSignal(): Signal
    {
        return $this->batchDisplayed;
    }

    public function getLoaderBreathTick(): int
    {
        return $this->loaderBreathTick->get();
    }

    public function setLoaderBreathTick(int $v): void
    {
        $this->loaderBreathTick->set($v);
    }

    public function loaderBreathTickSignal(): Signal
    {
        return $this->loaderBreathTick;
    }

    /** Increment loader breath tick by 1. */
    public function tickLoaderBreath(): void
    {
        $this->loaderBreathTick->update(fn (int $t): int => $t + 1);
    }

    public function getCachedLoaderLabel(): string
    {
        return $this->cachedLoaderLabel->get();
    }

    public function setCachedLoaderLabel(string $v): void
    {
        $this->cachedLoaderLabel->set($v);
    }

    public function cachedLoaderLabelSignal(): Signal
    {
        return $this->cachedLoaderLabel;
    }

    public function getStartTime(): float
    {
        return $this->startTime->get();
    }

    public function setStartTime(float $v): void
    {
        $this->startTime->set($v);
    }

    public function startTimeSignal(): Signal
    {
        return $this->startTime;
    }

    public function getHasRunningAgents(): bool
    {
        return $this->hasRunningAgents->get();
    }

    public function setHasRunningAgents(bool $v): void
    {
        $this->hasRunningAgents->set($v);
    }

    public function hasRunningAgentsSignal(): Signal
    {
        return $this->hasRunningAgents;
    }

    // ── Tool state ─────────────────────────────────────────────────────

    public function getLastToolArgs(): array
    {
        return $this->lastToolArgs->get();
    }

    public function setLastToolArgs(array $v): void
    {
        $this->lastToolArgs->set($v);
    }

    public function lastToolArgsSignal(): Signal
    {
        return $this->lastToolArgs;
    }

    public function getLastToolArgsByName(): array
    {
        return $this->lastToolArgsByName->get();
    }

    public function setLastToolArgsByName(array $v): void
    {
        $this->lastToolArgsByName->set($v);
    }

    public function lastToolArgsByNameSignal(): Signal
    {
        return $this->lastToolArgsByName;
    }

    public function getActiveBashWidget(): mixed
    {
        return $this->activeBashWidget->get();
    }

    public function setActiveBashWidget(mixed $v): void
    {
        $this->activeBashWidget->set($v);
    }

    public function activeBashWidgetSignal(): Signal
    {
        return $this->activeBashWidget;
    }

    public function getToolExecutingPreview(): ?string
    {
        return $this->toolExecutingPreview->get();
    }

    public function setToolExecutingPreview(?string $v): void
    {
        $this->toolExecutingPreview->set($v);
    }

    public function toolExecutingPreviewSignal(): Signal
    {
        return $this->toolExecutingPreview;
    }

    public function getActiveDiscoveryItems(): array
    {
        return $this->activeDiscoveryItems->get();
    }

    public function setActiveDiscoveryItems(array $v): void
    {
        $this->activeDiscoveryItems->set($v);
    }

    public function activeDiscoveryItemsSignal(): Signal
    {
        return $this->activeDiscoveryItems;
    }

    // ── Tool execution animation ───────────────────────────────────────

    public function getToolExecutingBreathTick(): int
    {
        return $this->toolExecutingBreathTick->get();
    }

    public function setToolExecutingBreathTick(int $v): void
    {
        $this->toolExecutingBreathTick->set($v);
    }

    public function toolExecutingBreathTickSignal(): Signal
    {
        return $this->toolExecutingBreathTick;
    }

    /** Increment tool executing breath tick by 1. */
    public function tickToolExecutingBreath(): void
    {
        $this->toolExecutingBreathTick->update(fn (int $t): int => $t + 1);
    }

    public function getToolExecutingStartTime(): float
    {
        return $this->toolExecutingStartTime->get();
    }

    public function setToolExecutingStartTime(float $v): void
    {
        $this->toolExecutingStartTime->set($v);
    }

    public function toolExecutingStartTimeSignal(): Signal
    {
        return $this->toolExecutingStartTime;
    }

    public function getHasThinkingLoader(): bool
    {
        return $this->hasThinkingLoader->get();
    }

    public function setHasThinkingLoader(bool $v): void
    {
        $this->hasThinkingLoader->set($v);
    }

    public function hasThinkingLoaderSignal(): Signal
    {
        return $this->hasThinkingLoader;
    }

    public function getHasCompactingLoader(): bool
    {
        return $this->hasCompactingLoader->get();
    }

    public function setHasCompactingLoader(bool $v): void
    {
        $this->hasCompactingLoader->set($v);
    }

    public function hasCompactingLoaderSignal(): Signal
    {
        return $this->hasCompactingLoader;
    }

    // ── Modal ──────────────────────────────────────────────────────────

    public function getActiveModal(): bool
    {
        return $this->activeModal->get();
    }

    public function setActiveModal(bool $v): void
    {
        $this->activeModal->set($v);
    }

    public function activeModalSignal(): Signal
    {
        return $this->activeModal;
    }

    // ── Task / Has tasks ───────────────────────────────────────────────

    public function getHasTasks(): bool
    {
        return $this->hasTasks->get();
    }

    public function setHasTasks(bool $v): void
    {
        $this->hasTasks->set($v);
    }

    public function hasTasksSignal(): Signal
    {
        return $this->hasTasks;
    }

    public function getHasSubagentActivity(): bool
    {
        return $this->hasSubagentActivity->get();
    }

    public function setHasSubagentActivity(bool $v): void
    {
        $this->hasSubagentActivity->set($v);
    }

    public function hasSubagentActivitySignal(): Signal
    {
        return $this->hasSubagentActivity;
    }

    // ── Render trigger ─────────────────────────────────────────────────

    public function getRenderTrigger(): int
    {
        return $this->renderTrigger->get();
    }

    public function triggerRender(): void
    {
        $this->renderTrigger->update(fn (int $v): int => $v + 1);
    }

    public function renderTriggerSignal(): Signal
    {
        return $this->renderTrigger;
    }

    // ── Computed ───────────────────────────────────────────────────────

    public function getContextPercent(): float
    {
        return $this->contextPercent->get();
    }

    public function contextPercentComputed(): Computed
    {
        return $this->contextPercent;
    }

    public function getIsBrowsingHistory(): bool
    {
        return $this->isBrowsingHistory->get();
    }

    public function isBrowsingHistoryComputed(): Computed
    {
        return $this->isBrowsingHistory;
    }

    public function getStatusBarMessage(): string
    {
        return $this->statusBarMessage->get();
    }

    public function statusBarMessageComputed(): Computed
    {
        return $this->statusBarMessage;
    }

    // ── Batch helpers ──────────────────────────────────────────────────

    /**
     * Batch-update multiple signals and trigger a single render.
     *
     * @param  callable(self): void  $updater
     */
    public function batch(callable $updater): void
    {
        BatchScope::run(function () use ($updater): void {
            $updater($this);
        });
        $this->triggerRender();
    }

    /**
     * Create a nullable Signal with proper type widening.
     *
     * Phpstan infers Signal<null> from new Signal(null), but properties
     * are typed as Signal<T|null>. Returning Signal<mixed> is accepted
     * by all nullable property types without suppressions.
     *
     * @return Signal<mixed>
     */
    private static function nullable(mixed $value = null): Signal
    {
        return new Signal($value);
    }

    /**
     * Create an array-typed Signal with proper type widening.
     *
     * Phpstan infers Signal<array{}> from new Signal([]). Returning
     * Signal<mixed> is accepted by all array property types.
     *
     * @return Signal<mixed>
     */
    private static function arrayOf(): Signal
    {
        return new Signal([]);
    }
}

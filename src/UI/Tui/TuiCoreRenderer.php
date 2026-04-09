<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Athanor\Effect;
use Athanor\EffectScope;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiAnimation;
use Kosmokrator\UI\Ansi\AnsiIntro;
use Kosmokrator\UI\Ansi\AnsiPrometheus;
use Kosmokrator\UI\Ansi\AnsiTheogony;
use Kosmokrator\UI\Ansi\AnsiUnleash;
use Kosmokrator\UI\CoreRendererInterface;
use Kosmokrator\UI\TerminalNotification;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Builder\StatusBarBuilder;
use Kosmokrator\UI\Tui\Builder\TaskBarBuilder;
use Kosmokrator\UI\Tui\Composition\StatusBar;
use Kosmokrator\UI\Tui\Composition\TaskTree;
use Kosmokrator\UI\Tui\Phase\Phase;
use Kosmokrator\UI\Tui\Phase\PhaseStateMachine;
use Kosmokrator\UI\Tui\Primitive\ReactiveBridge;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\Widget\AnsiArtWidget;
use Kosmokrator\UI\Tui\Widget\AnsweredQuestionsWidget;
use Kosmokrator\UI\Tui\Widget\HistoryStatusWidget;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * TUI implementation of core lifecycle and display methods.
 *
 * Manages the Tui instance, layout, streaming, status bar, phase transitions,
 * prompt/input, scroll history, thinking/compacting, and ANSI intro/animations.
 *
 * All mutable UI state lives in {@see TuiStateStore} as reactive signals.
 * Effects auto-propagate changes to widgets. Phase transitions are validated
 * through a {@see PhaseStateMachine}.
 */
final class TuiCoreRenderer implements CoreRendererInterface
{
    private Tui $tui;

    private ContainerWidget $session;

    private ContainerWidget $conversation;

    private HistoryStatusWidget $historyStatus;

    private StatusBarBuilder $statusBarBuilder;

    private ProgressBarWidget $statusBarWidget;

    private ContainerWidget $overlay;

    private TaskBarBuilder $taskBarBuilder;

    private ?TaskTree $taskTree = null;

    private ContainerWidget $thinkingBar;

    private ?ReactiveBridge $reactiveBridge = null;

    private EditorWidget $input;

    private SubagentDisplayManager $subagentDisplay;

    private TuiAnimationManager $animationManager;

    private TuiModalManager $modalManager;

    private readonly TuiStateStore $state;

    private PhaseStateMachine $phaseMachine;

    private readonly EffectScope $effectScope;

    /** @var (\Closure(string): bool)|null */
    private ?\Closure $immediateCommandHandler = null;

    private ?Suspension $promptSuspension = null;

    private ?TuiInputHandler $inputHandler = null;

    private ?TaskStore $taskStore = null;

    public function __construct()
    {
        $this->state = new TuiStateStore;
    }

    // ── Public accessors for shared state ───────────────────────────────

    public function getTui(): Tui
    {
        return $this->tui;
    }

    public function getConversation(): ContainerWidget
    {
        return $this->conversation;
    }

    public function getOverlay(): ContainerWidget
    {
        return $this->overlay;
    }

    public function getSession(): ContainerWidget
    {
        return $this->session;
    }

    public function getInput(): EditorWidget
    {
        return $this->input;
    }

    public function getAnimationManager(): TuiAnimationManager
    {
        return $this->animationManager;
    }

    public function getSubagentDisplay(): SubagentDisplayManager
    {
        return $this->subagentDisplay;
    }

    public function getModalManager(): TuiModalManager
    {
        return $this->modalManager;
    }

    public function getRequestCancellation(): ?DeferredCancellation
    {
        return $this->state->getRequestCancellation();
    }

    public function getCurrentModeLabel(): string
    {
        return $this->state->getModeLabel();
    }

    public function getLastToolArgs(): array
    {
        return [];
    } // Placeholder — tool args live in TuiToolRenderer

    public function getTaskStore(): ?TaskStore
    {
        return $this->taskStore;
    }

    public function getState(): TuiStateStore
    {
        return $this->state;
    }

    // ── CoreRendererInterface ───────────────────────────────────────────

    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
        $this->taskTree?->setTaskStore($store);
        $this->taskBarBuilder->setTaskStore($store);
        $this->state->setHasTasks(! $store->isEmpty());
    }

    public function initialize(): void
    {
        $this->tui = new Tui(KosmokratorStyleSheet::create());
        $this->effectScope = new EffectScope;

        $this->session = new ContainerWidget;
        $this->session->setId('session');
        $this->session->addStyleClass('session');
        $this->session->expandVertically(true);

        $this->conversation = new ContainerWidget;
        $this->conversation->setId('conversation');
        $this->conversation->expandVertically(true);

        $this->historyStatus = new HistoryStatusWidget;
        $this->historyStatus->setId('history-status');

        // Status bar — new composition (replaces StatusBarBuilder)
        $this->statusBarWidget = StatusBar::createProgressBar($this->state);

        // Legacy status bar builder — kept for parallel operation during migration
        $this->statusBarBuilder = StatusBarBuilder::create($this->state);

        $this->overlay = new ContainerWidget;
        $this->overlay->setId('overlay');

        // Task tree — new composition (replaces TaskBarBuilder)
        $this->taskTree = TaskTree::of($this->taskStore, $this->state);

        // Legacy task bar builder — kept for parallel operation during migration
        $this->taskBarBuilder = TaskBarBuilder::create($this->state);

        $this->thinkingBar = new ContainerWidget;
        $this->thinkingBar->setId('thinking-bar');

        $this->subagentDisplay = new SubagentDisplayManager(
            state: $this->state,
            conversation: $this->conversation,
            ensureSpinners: fn () => $this->animationManager->ensureSpinnersRegistered(),
        );

        $this->animationManager = new TuiAnimationManager(
            state: $this->state,
            thinkingBar: $this->thinkingBar,
            subagentTickCallback: fn () => $this->subagentDisplay->tickTreeRefresh(),
            subagentCleanupCallback: fn () => $this->subagentDisplay->cleanup(),
            renderCallback: fn () => $this->flushRender(),
            forceRenderCallback: fn () => $this->forceRender(),
        );

        $this->input = new EditorWidget;
        $this->input->setId('prompt');
        $this->input->setMinVisibleLines(1);
        $this->input->setMaxVisibleLines(2);
        $this->input->setKeybindings(new Keybindings([
            'copy' => [],
            'new_line' => ['shift+enter', 'alt+enter'],
            'cycle_mode' => ['shift+tab'],
            'history_up' => [Key::PAGE_UP],
            'history_down' => [Key::PAGE_DOWN],
            'history_end' => [Key::END],
        ]));

        $this->modalManager = new TuiModalManager(
            state: $this->state,
            overlay: $this->overlay,
            sessionRoot: $this->session,
            tui: $this->tui,
            input: $this->input,
            renderCallback: fn () => $this->flushRender(),
            forceRenderCallback: fn () => $this->forceRender(),
        );

        $this->bindInputHandlers();

        // ── Layout: use task tree widget instead of task bar builder ───
        $this->session->add($this->conversation);
        $this->session->add($this->historyStatus);
        $this->session->add($this->overlay);
        $this->session->add($this->taskTree);
        $this->session->add($this->thinkingBar);
        $this->session->add($this->input);
        $this->session->add($this->statusBarWidget);

        $this->tui->add($this->session);
        $this->tui->setFocus($this->input);

        $this->tui->start();

        // ── Wire PhaseStateMachine ────────────────────────────────────
        $this->phaseMachine = new PhaseStateMachine;

        // Phase transitions drive the animation manager
        $this->phaseMachine->onAny(function ($transition, Phase $from, Phase $to): void {
            $agentPhase = $this->tuiPhaseToAgentPhase($to);
            $this->animationManager->setPhase($agentPhase, $this->state->getRequestCancellation());
        });

        // ── Wire ReactiveBridge ───────────────────────────────────────
        // Single Effect that replaces the 4 separate Effects below.
        // Touches all display signals → auto-tracks → requestRender() on change.
        $this->reactiveBridge = new ReactiveBridge;
        $this->reactiveBridge->start($this->tui, $this->state);

        // ── Remaining Effects (will be removed after full migration) ──

        // Status bar sync: updates the ProgressBarWidget when status signals change
        $this->effectScope->effect(function (): void {
            StatusBar::sync($this->statusBarWidget, $this->state);
        });

        // History status effect: show/hide based on scroll state
        $this->effectScope->effect(function (): void {
            $scrollOffset = $this->state->getScrollOffset();
            if ($scrollOffset <= 0) {
                $this->historyStatus->hide();

                return;
            }

            $hasHidden = $this->state->getHasHiddenActivityBelow();
            $this->historyStatus->show($hasHidden);
        });

        // Task tree sync: TaskTree is a ReactiveWidget — it self-syncs via
        // beforeRender() → syncFromSignals(). But it still needs the render
        // trigger to fire. The ReactiveBridge handles this now.
        // Legacy: $this->taskBarBuilder->update() — no longer needed.

        // Render trigger effect: no longer needed — ReactiveBridge handles it.
        // The renderTrigger signal is still touched by ReactiveBridge for
        // backwards compatibility during migration.
        $this->effectScope->effect(function (): void {
            $this->state->renderTriggerSignal()->get();
            // flushRender is now handled by ReactiveBridge's requestRender()
        });
    }

    public function renderIntro(bool $animated): void
    {
        $intro = new AnsiIntro;
        $noAnim = getenv('KOSMOKRATOR_NO_ANIM') === '1';

        if ($noAnim || ! $animated) {
            $intro->renderStatic();
            sleep(1);
            echo "\033[2J\033[H";
        } else {
            $skipped = $intro->animate();
            if (! $skipped) {
                usleep(800000);
            }
            echo "\033[2J\033[H";
        }

        $this->tui->requestRender(force: true);

        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();
        $gold = Theme::accent();
        $border = Theme::primaryDim();
        $orbit = Theme::rgb(60, 50, 70);
        $sun = Theme::rgb(255, 220, 80);
        $mercury = Theme::rgb(180, 180, 200);
        $venus = Theme::rgb(255, 180, 100);
        $earth = Theme::rgb(80, 160, 255);
        $mars = Theme::rgb(255, 80, 60);
        $jupiter = Theme::rgb(255, 200, 130);
        $saturn = Theme::rgb(210, 180, 140);
        $neptune = Theme::rgb(70, 100, 220);
        $uranus = Theme::rgb(130, 210, 230);
        $ring = Theme::rgb(80, 70, 90);
        $ringDim = Theme::rgb(50, 45, 60);

        $orrery = <<<ART
                  {$ringDim}·  ·  ·  {$uranus}♅{$r}  {$ringDim}·  ·  ·{$r}
              {$orbit}·{$r}        {$ring}·{$r} {$earth}♁{$r} {$ring}·{$r}        {$orbit}·{$r}
           {$orbit}·{$r}     {$ring}·{$r}    {$ring}·{$mercury}☿{$ring}·{$r}    {$ring}·{$r}     {$orbit}·{$r}
         {$saturn}♄{$r}   {$ring}·{$r}         {$sun}☉{$r}         {$ring}·{$r}   {$jupiter}♃{$r}
           {$orbit}·{$r}     {$ring}·{$r}    {$ring}·{$venus}♀{$ring}·{$r}    {$ring}·{$r}     {$orbit}·{$r}
              {$orbit}·{$r}        {$ring}·{$r} {$mars}♂{$r} {$ring}·{$r}        {$orbit}·{$r}
                  {$ringDim}·  ·  ·  {$neptune}♆{$r}  {$ringDim}·  ·  ·{$r}
ART;

        $green = Theme::rgb(80, 200, 120);
        $purple = Theme::rgb(160, 120, 255);
        $orange = Theme::rgb(255, 180, 60);
        $silver = Theme::rgb(180, 180, 200);
        $steel = Theme::rgb(100, 140, 200);
        $cyan = Theme::rgb(100, 200, 200);
        $muted = Theme::rgb(160, 160, 170);

        $tutorial = <<<HELP

{$gold}Quick Reference{$r}
{$border}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}
{$green}/edit{$dim}  {$purple}/plan{$dim}  {$orange}/ask{$r}               {$dim}Agent mode (write / read-only / Q&A){$r}
{$silver}/guardian{$dim}  {$steel}/argus{$dim}  {$gold}/prometheus{$r}    {$dim}Permission mode (smart / strict / auto){$r}
{$cyan}/compact{$dim}  {$cyan}/new{$dim}  {$cyan}/resume{$dim}  {$cyan}/tasks clear{$r}  {$dim}Context and session management{$r}
{$muted}/settings{$dim}  {$muted}/memories{$dim}  {$muted}/sessions{$dim}  {$muted}/agents{$r}  {$dim}Configuration and monitoring{$r}
{$border}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}
HELP;

        $header = new TextWidget("{$gold}⚡ KosmoKrator — Ruler of the Cosmos ⚡{$r}");
        $header->addStyleClass('subtitle');
        $this->addConversationWidget($header);

        $orreryWidget = new TextWidget($orrery);
        $orreryWidget->addStyleClass('welcome');
        $this->addConversationWidget($orreryWidget);

        $tutorialWidget = new TextWidget($tutorial);
        $tutorialWidget->addStyleClass('welcome');
        $this->addConversationWidget($tutorialWidget);
    }

    public function prompt(): string
    {
        $this->flushPendingQuestionRecap();

        $pendingRestore = $this->state->getPendingEditorRestore();
        if ($pendingRestore !== null) {
            $this->input->setText($pendingRestore);
            $this->state->setPendingEditorRestore(null);
        }

        $this->tui->setFocus($this->input);
        $this->promptSuspension = EventLoop::getSuspension();

        return $this->promptSuspension->suspend();
    }

    public function showUserMessage(string $text): void
    {
        $this->flushPendingQuestionRecap();

        $r = Theme::reset();
        $bg = Theme::bgRgb(35, 35, 45);
        $white = Theme::white();
        $content = "⟡ {$text}";
        $cols = $this->tui->getTerminal()->getColumns();

        $visible = AnsiUtils::visibleWidth($content);
        $pad = max(0, $cols - $visible - 4);
        $widget = new TextWidget("{$bg}{$white}{$content}".str_repeat(' ', $pad)."{$r}");
        $widget->addStyleClass('user-message');
        $this->addConversationWidget($widget);
    }

    public function setPhase(AgentPhase $phase): void
    {
        $tuiPhase = $this->agentPhaseToTuiPhase($phase);

        if ($tuiPhase === $this->phaseMachine->current()) {
            return;
        }

        if ($phase === AgentPhase::Thinking && $this->state->getRequestCancellation() === null) {
            $this->state->setRequestCancellation(new DeferredCancellation);
        }

        $this->phaseMachine->transition($tuiPhase);

        if ($phase === AgentPhase::Idle) {
            $this->state->setRequestCancellation(null);
            TerminalNotification::notify();
        }
    }

    public function showThinking(): void
    {
        $this->setPhase(AgentPhase::Thinking);
    }

    public function clearThinking(): void
    {
        $this->setPhase(AgentPhase::Idle);
    }

    public function showCompacting(): void
    {
        $this->animationManager->showCompacting();
    }

    public function clearCompacting(): void
    {
        $this->animationManager->clearCompacting();
    }

    public function getCancellation(): ?Cancellation
    {
        $cancellation = $this->state->getRequestCancellation();

        return $cancellation?->getCancellation();
    }

    public function showReasoningContent(string $content): void
    {
        $this->clearThinking();
        $this->flushPendingQuestionRecap();

        $dim = Theme::dim();
        $r = Theme::reset();
        $border = Theme::borderTask();

        $lines = explode("\n", $content);
        $header = "{$dim}{$border}⟐{$r} {$dim}Reasoning{$r}";

        // Add spacing between user message and reasoning block
        $this->addConversationWidget(new TextWidget(''));

        $widget = new Widget\CollapsibleWidget(
            header: $header,
            content: $content,
            lineCount: count($lines),
        );
        $widget->addStyleClass('tool-result');
        $this->addConversationWidget($widget);
    }

    public function streamChunk(string $text): void
    {
        $this->flushPendingQuestionRecap();
        $this->finalizeDiscoveryBatch();

        $activeResponse = $this->state->getActiveResponse();
        $activeResponseIsAnsi = $this->state->getActiveResponseIsAnsi();

        if ($activeResponse === null) {
            $this->clearThinking();

            if ($this->containsAnsiEscapes($text)) {
                $activeResponse = new AnsiArtWidget('');
                $activeResponse->addStyleClass('ansi-art');
                $this->state->setActiveResponseIsAnsi(true);
            } else {
                $activeResponse = new MarkdownWidget('');
                $activeResponse->addStyleClass('response');
                $this->state->setActiveResponseIsAnsi(false);
            }

            $this->state->setActiveResponse($activeResponse);
            $this->addConversationWidget($activeResponse);
        } elseif (! $activeResponseIsAnsi && $this->containsAnsiEscapes($text)) {
            $accumulated = $activeResponse->getText();
            $this->conversation->remove($activeResponse);

            $activeResponse = new AnsiArtWidget($accumulated);
            $activeResponse->addStyleClass('ansi-art');
            $this->state->setActiveResponseIsAnsi(true);
            $this->state->setActiveResponse($activeResponse);
            $this->addConversationWidget($activeResponse);
        }

        $current = $activeResponse->getText();
        $activeResponse->setText($current.$text);
        $this->markHiddenConversationActivity();
        $this->state->triggerRender();
    }

    public function streamComplete(): void
    {
        $this->state->setActiveResponse(null);
        $this->state->setActiveResponseIsAnsi(false);
        $this->finalizeDiscoveryBatch();
        $this->flushRender();
    }

    public function showError(string $message): void
    {
        $this->showMessage("✗ Error: {$message}", 'tool-error');
    }

    public function showNotice(string $message): void
    {
        $this->showMessage($message, 'subtitle');
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->state->setModeLabel($label);
        if ($color !== '') {
            $this->state->setModeColor($color);
        }
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->state->setPermissionLabel($label);
        $this->state->setPermissionColor($color);
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->state->setTokensIn($tokensIn);
        $this->state->setTokensOut($tokensOut);
        $this->state->setCost($cost);
        $this->state->setMaxContext($maxContext);
        $this->state->setModel($model);

        StatusBar::formatTokenDetail($this->state, $model, $tokensIn, $maxContext);
        StatusBar::sync($this->statusBarWidget, $this->state);
        $this->state->triggerRender();
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void
    {
        $tokensIn = min($this->state->getTokensIn() ?? 0, $maxContext);

        StatusBar::formatRuntimeDetail($this->state, $provider, $model, $tokensIn, $maxContext);
        StatusBar::sync($this->statusBarWidget, $this->state);
        $this->state->triggerRender();
    }

    public function consumeQueuedMessage(): ?string
    {
        return $this->state->shiftMessage();
    }

    public function setImmediateCommandHandler(?\Closure $handler): void
    {
        $this->immediateCommandHandler = $handler;
    }

    public function teardown(): void
    {
        $this->effectScope->dispose();
        $this->reactiveBridge?->stop();

        if ($this->tui->isRunning()) {
            $this->tui->stop();
        }
    }

    public function showWelcome(): void
    {
        // Already handled in renderIntro
    }

    public function playTheogony(): void
    {
        $this->playAnimation(new AnsiTheogony);
    }

    public function playPrometheus(): void
    {
        $this->playAnimation(new AnsiPrometheus);
    }

    public function playUnleash(): void
    {
        $this->playAnimation(new AnsiUnleash);
    }

    public function playAnimation(AnsiAnimation $animation): void
    {
        $this->tui->stop();
        echo "\033[2J\033[H";

        $animation->animate();

        echo "\033[2J\033[H";
        $this->tui->start();
        $this->forceRender();
    }

    public function setSkillCompletions(array $completions): void
    {
        $this->inputHandler?->setSkillCompletions($completions);
    }

    public function refreshTaskBar(): void
    {
        // TaskTree is a ReactiveWidget — it auto-syncs via beforeRender().
        // Still refresh for immediate imperative callers during migration.
        $this->taskTree?->invalidate();
    }

    // ── Public helpers for other sub-renderers ──────────────────────────

    public function flushRender(): void
    {
        $this->tui->requestRender();
        $this->tui->processRender();
    }

    public function forceRender(): void
    {
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    public function addConversationWidget(AbstractWidget $widget): void
    {
        $this->conversation->add($widget);
        $this->state->triggerRender();
    }

    public function getLastConversationWidget(): ?AbstractWidget
    {
        $children = $this->conversation->all();

        return $children === [] ? null : $children[array_key_last($children)];
    }

    public function queueQuestionRecap(string $question, string $answer, bool $answered, bool $recommended = false): void
    {
        $this->state->pushQuestionRecap($question, $answer, $answered, $recommended);
    }

    public function flushPendingQuestionRecap(): void
    {
        $recap = $this->state->drainQuestionRecap();
        if ($recap === []) {
            return;
        }

        $this->addConversationWidget(new AnsweredQuestionsWidget($recap));
    }

    public function clearPendingQuestionRecap(): void
    {
        $this->state->setPendingQuestionRecap([]);
    }

    public function clearConversationState(): void
    {
        $this->conversation->clear();
        $this->state->setActiveResponse(null);
        $this->state->setActiveResponseIsAnsi(false);
        $this->state->setPendingQuestionRecap([]);
        $this->state->setScrollOffset(0);
        $this->state->setHasHiddenActivityBelow(false);
        $this->historyStatus->hide();
        $this->tui->setScrollOffset(0);

        if ($this->toolStateResetCallback !== null) {
            ($this->toolStateResetCallback)();
        }
    }

    /** @var (\Closure(): void)|null */
    private ?\Closure $discoveryBatchFinalizer = null;

    public function setDiscoveryBatchFinalizer(\Closure $finalizer): void
    {
        $this->discoveryBatchFinalizer = $finalizer;
    }

    /** @var (\Closure(): void)|null */
    private ?\Closure $toolStateResetCallback = null;

    public function setToolStateResetCallback(\Closure $callback): void
    {
        $this->toolStateResetCallback = $callback;
    }

    public function finalizeDiscoveryBatch(): void
    {
        if ($this->discoveryBatchFinalizer !== null) {
            ($this->discoveryBatchFinalizer)();
        }
    }

    public function queueMessage(string $message): void
    {
        $this->state->pushMessage($message);
        $this->showUserMessage($message);
    }

    // ── Private helpers ─────────────────────────────────────────────────

    private function containsAnsiEscapes(string $text): bool
    {
        return str_contains($text, "\x1b[");
    }

    private function markHiddenConversationActivity(): void
    {
        if (! $this->isBrowsingHistory()) {
            return;
        }

        $this->state->setHasHiddenActivityBelow(true);
    }

    private function scrollHistoryUp(): void
    {
        $newOffset = $this->state->getScrollOffset() + $this->historyScrollStep();
        $this->state->setScrollOffset($newOffset);
        $this->applyScrollOffset();
    }

    private function scrollHistoryDown(): void
    {
        $newOffset = max(0, $this->state->getScrollOffset() - $this->historyScrollStep());
        $this->state->setScrollOffset($newOffset);
        if ($newOffset === 0) {
            $this->state->setHasHiddenActivityBelow(false);
        }

        $this->applyScrollOffset();
    }

    private function jumpToLiveOutput(): void
    {
        $this->state->setScrollOffset(0);
        $this->state->setHasHiddenActivityBelow(false);
        $this->applyScrollOffset();
    }

    private function applyScrollOffset(): void
    {
        $this->tui->setScrollOffset($this->state->getScrollOffset());
        $this->flushRender();
    }

    private function isBrowsingHistory(): bool
    {
        return $this->state->getScrollOffset() > 0;
    }

    private function historyScrollStep(): int
    {
        return max(6, $this->tui->getTerminal()->getRows() - 10);
    }

    private function showMessage(string $text, string $styleClass): void
    {
        $this->flushPendingQuestionRecap();
        $widget = new TextWidget($text);
        $widget->addStyleClass($styleClass);
        $this->addConversationWidget($widget);
    }

    private function cycleMode(): string
    {
        $modes = ['edit', 'plan', 'ask'];
        $current = strtolower($this->state->getModeLabel());
        $index = array_search($current, $modes, true);
        if ($index === false) {
            $index = -1;
        }
        $next = $modes[($index + 1) % count($modes)];

        return $next;
    }

    /**
     * Convert an AgentPhase to a TUI Phase for the state machine.
     */
    private function agentPhaseToTuiPhase(AgentPhase $phase): Phase
    {
        return match ($phase) {
            AgentPhase::Thinking => Phase::Thinking,
            AgentPhase::Tools => Phase::Tools,
            AgentPhase::Idle => Phase::Idle,
        };
    }

    /**
     * Convert a TUI Phase back to an AgentPhase for the animation manager.
     */
    private function tuiPhaseToAgentPhase(Phase $phase): AgentPhase
    {
        return match ($phase) {
            Phase::Thinking => AgentPhase::Thinking,
            Phase::Tools => AgentPhase::Tools,
            Phase::Idle => AgentPhase::Idle,
            Phase::Compacting => AgentPhase::Idle,
        };
    }

    public function bindInputHandlers(): void
    {
        $state = $this->state;

        $this->inputHandler = new TuiInputHandler(
            input: $this->input,
            conversation: $this->conversation,
            overlay: $this->overlay,
            modalManager: $this->modalManager,
            flushRender: $this->flushRender(...),
            forceRender: $this->forceRender(...),
            scrollHistoryUp: $this->scrollHistoryUp(...),
            scrollHistoryDown: $this->scrollHistoryDown(...),
            jumpToLiveOutput: $this->jumpToLiveOutput(...),
            isBrowsingHistory: $this->isBrowsingHistory(...),
            cycleMode: $this->cycleMode(...),
            showMode: $this->showMode(...),
            queueMessage: fn (string $msg) => $this->queueMessage($msg),
            queueMessageSilent: fn (string $msg) => $state->pushMessage($msg),
            getImmediateCommandHandler: fn () => $this->immediateCommandHandler,
            getPromptSuspension: fn () => $this->promptSuspension,
            clearPromptSuspension: fn () => $this->promptSuspension = null,
            setPendingEditorRestore: fn (?string $v) => $state->setPendingEditorRestore($v),
            getRequestCancellation: fn () => $state->getRequestCancellation(),
            clearRequestCancellation: fn () => $state->setRequestCancellation(null),
        );
        $this->inputHandler->bind();
    }
}

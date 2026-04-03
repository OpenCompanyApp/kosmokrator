<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiIntro;
use Kosmokrator\UI\Ansi\AnsiPrometheus;
use Kosmokrator\UI\Ansi\AnsiTheogony;
use Kosmokrator\UI\Ansi\AnsiUnleash;
use Kosmokrator\UI\CoreRendererInterface;
use Kosmokrator\UI\TerminalNotification;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Widget\AnsiArtWidget;
use Kosmokrator\UI\Tui\Widget\AnsweredQuestionsWidget;
use Kosmokrator\UI\Tui\Widget\HistoryStatusWidget;
use Kosmokrator\UI\Tui\Widget\ToggleableWidgetInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * TUI implementation of core lifecycle and display methods.
 *
 * Manages the Tui instance, layout, streaming, status bar, phase transitions,
 * prompt/input, scroll history, thinking/compacting, and ANSI intro/animations.
 */
final class TuiCoreRenderer implements CoreRendererInterface
{
    private Tui $tui;

    private ContainerWidget $session;

    private ContainerWidget $conversation;

    private HistoryStatusWidget $historyStatus;

    private ProgressBarWidget $statusBar;

    private ContainerWidget $overlay;

    private TextWidget $taskBar;

    private ContainerWidget $thinkingBar;

    private EditorWidget $input;

    private SubagentDisplayManager $subagentDisplay;

    private TuiAnimationManager $animationManager;

    private TuiModalManager $modalManager;

    private ?string $pendingEditorRestore = null;

    private ?DeferredCancellation $requestCancellation = null;

    /** @var string[] */
    private array $messageQueue = [];

    private string $currentModeLabel = 'Edit';

    private string $currentModeColor = "\033[38;2;80;200;120m";

    private string $statusDetail = 'Ready';

    private string $currentPermissionLabel = 'Guardian ◈';

    private string $currentPermissionColor = "\033[38;2;180;180;200m";

    private ?int $lastStatusTokensIn = null;

    private ?int $lastStatusTokensOut = null;

    private ?float $lastStatusCost = null;

    private ?int $lastStatusMaxContext = null;

    private MarkdownWidget|AnsiArtWidget|null $activeResponse = null;

    private bool $activeResponseIsAnsi = false;

    /** @var (\Closure(string): bool)|null */
    private ?\Closure $immediateCommandHandler = null;

    private ?Suspension $promptSuspension = null;

    private ?SelectListWidget $slashCompletion = null;

    private ?TaskStore $taskStore = null;

    /** @var array<array{question: string, answer: string, answered: bool, recommended: bool}> */
    private array $pendingQuestionRecap = [];

    private int $scrollOffset = 0;

    private bool $hasHiddenActivityBelow = false;

    private const SLASH_COMMANDS = [
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
        ['value' => '/unleash', 'label' => '/unleash', 'description' => 'Unleash a massive swarm of agents on a task'],
        ['value' => '/update', 'label' => '/update', 'description' => 'Check for and install updates'],
        ['value' => '/feedback', 'label' => '/feedback', 'description' => 'Submit feedback or a bug report'],
        ['value' => '/rename', 'label' => '/rename', 'description' => 'Rename the current session'],
    ];

    // ── Public accessors for shared state ───────────────────────────────

    public function getTui(): Tui { return $this->tui; }

    public function getConversation(): ContainerWidget { return $this->conversation; }

    public function getOverlay(): ContainerWidget { return $this->overlay; }

    public function getSession(): ContainerWidget { return $this->session; }

    public function getInput(): EditorWidget { return $this->input; }

    public function getAnimationManager(): TuiAnimationManager { return $this->animationManager; }

    public function getSubagentDisplay(): SubagentDisplayManager { return $this->subagentDisplay; }

    public function getModalManager(): TuiModalManager { return $this->modalManager; }

    public function getRequestCancellation(): ?DeferredCancellation { return $this->requestCancellation; }

    public function getCurrentModeLabel(): string { return $this->currentModeLabel; }

    public function getLastToolArgs(): array { return []; } // Placeholder — tool args live in TuiToolRenderer

    public function getTaskStore(): ?TaskStore { return $this->taskStore; }

    // ��─ CoreRendererInterface ───────────────────────────────────���───────

    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    public function initialize(): void
    {
        $this->tui = new Tui(KosmokratorStyleSheet::create());

        $this->session = new ContainerWidget;
        $this->session->setId('session');
        $this->session->addStyleClass('session');
        $this->session->expandVertically(true);

        $this->conversation = new ContainerWidget;
        $this->conversation->setId('conversation');
        $this->conversation->expandVertically(true);

        $this->historyStatus = new HistoryStatusWidget;
        $this->historyStatus->setId('history-status');

        $this->statusBar = new ProgressBarWidget(200_000, '%message%  %bar%');
        $this->statusBar->setId('status-bar');
        $this->statusBar->setBarCharacter('━');
        $this->statusBar->setEmptyBarCharacter('─');
        $this->statusBar->setProgressCharacter('━');
        $this->statusBar->setBarWidth(20);
        $this->refreshStatusBar();
        $this->statusBar->start(200_000, 0);

        $this->overlay = new ContainerWidget;
        $this->overlay->setId('overlay');

        $this->taskBar = new TextWidget('');
        $this->taskBar->setId('task-bar');

        $this->thinkingBar = new ContainerWidget;
        $this->thinkingBar->setId('thinking-bar');

        $this->subagentDisplay = new SubagentDisplayManager(
            conversation: $this->conversation,
            breathColorProvider: fn () => $this->animationManager->getBreathColor(),
            renderCallback: fn () => $this->flushRender(),
            ensureSpinners: fn () => $this->animationManager->ensureSpinnersRegistered(),
        );

        $this->animationManager = new TuiAnimationManager(
            thinkingBar: $this->thinkingBar,
            hasTasksProvider: fn () => $this->taskStore !== null && ! $this->taskStore->isEmpty(),
            hasSubagentActivityProvider: fn () => $this->subagentDisplay->hasRunningAgents(),
            refreshTaskBarCallback: fn () => $this->refreshTaskBar(),
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
            overlay: $this->overlay,
            sessionRoot: $this->session,
            tui: $this->tui,
            input: $this->input,
            renderCallback: fn () => $this->flushRender(),
            forceRenderCallback: fn () => $this->forceRender(),
        );

        $this->bindInputHandlers();

        $this->session->add($this->conversation);
        $this->session->add($this->historyStatus);
        $this->session->add($this->overlay);
        $this->session->add($this->taskBar);
        $this->session->add($this->thinkingBar);
        $this->session->add($this->input);
        $this->session->add($this->statusBar);

        $this->tui->add($this->session);
        $this->tui->setFocus($this->input);

        $this->tui->start();
    }

    public function renderIntro(bool $animated): void
    {
        $intro = new AnsiIntro;
        if ($animated) {
            $skipped = $intro->animate();
            if (! $skipped) {
                usleep(800000);
            }
            echo "\033[2J\033[H";
        } else {
            $intro->renderStatic();
            sleep(1);
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

        $this->flushRender();
    }

    public function prompt(): string
    {
        $this->flushPendingQuestionRecap();

        if ($this->pendingEditorRestore !== null) {
            $this->input->setText($this->pendingEditorRestore);
            $this->pendingEditorRestore = null;
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
        $this->flushRender();
    }

    public function setPhase(AgentPhase $phase): void
    {
        if ($phase === $this->animationManager->getCurrentPhase()) {
            return;
        }

        if ($phase === AgentPhase::Thinking && $this->requestCancellation === null) {
            $this->requestCancellation = new DeferredCancellation;
        }

        $this->animationManager->setPhase($phase, $this->requestCancellation);

        if ($phase !== AgentPhase::Idle) {
            $this->refreshStatusBar();
        }

        if ($phase === AgentPhase::Idle) {
            $this->requestCancellation = null;
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
        return $this->requestCancellation?->getCancellation();
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

        $widget = new Widget\CollapsibleWidget(
            header: $header,
            content: $content,
            lineCount: count($lines),
        );
        $widget->addStyleClass('tool-result');
        $this->addConversationWidget($widget);
        $this->flushRender();
    }

    public function streamChunk(string $text): void
    {
        $this->flushPendingQuestionRecap();
        $this->finalizeDiscoveryBatch();

        if ($this->activeResponse === null) {
            $this->clearThinking();

            if ($this->containsAnsiEscapes($text)) {
                $this->activeResponse = new AnsiArtWidget('');
                $this->activeResponse->addStyleClass('ansi-art');
                $this->activeResponseIsAnsi = true;
            } else {
                $this->activeResponse = new MarkdownWidget('');
                $this->activeResponse->addStyleClass('response');
                $this->activeResponseIsAnsi = false;
            }

            $this->addConversationWidget($this->activeResponse);
        } elseif (! $this->activeResponseIsAnsi && $this->containsAnsiEscapes($text)) {
            $accumulated = $this->activeResponse->getText();
            $this->conversation->remove($this->activeResponse);

            $this->activeResponse = new AnsiArtWidget($accumulated);
            $this->activeResponse->addStyleClass('ansi-art');
            $this->activeResponseIsAnsi = true;
            $this->addConversationWidget($this->activeResponse);
        }

        $current = $this->activeResponse->getText();
        $this->activeResponse->setText($current.$text);
        $this->markHiddenConversationActivity();
        $this->flushRender();
    }

    public function streamComplete(): void
    {
        $this->activeResponse = null;
        $this->activeResponseIsAnsi = false;
        $this->finalizeDiscoveryBatch();
        $this->flushRender();
    }

    public function showError(string $message): void
    {
        $this->flushPendingQuestionRecap();
        $widget = new TextWidget("✗ Error: {$message}");
        $widget->addStyleClass('tool-error');
        $this->addConversationWidget($widget);
        $this->flushRender();
    }

    public function showNotice(string $message): void
    {
        $this->flushPendingQuestionRecap();
        $widget = new TextWidget($message);
        $widget->addStyleClass('subtitle');
        $this->addConversationWidget($widget);
        $this->flushRender();
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->currentModeLabel = $label;
        if ($color !== '') {
            $this->currentModeColor = $color;
        }
        $this->refreshStatusBar();
        $this->flushRender();
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->currentPermissionLabel = $label;
        $this->currentPermissionColor = $color;
        $this->refreshStatusBar();
        $this->flushRender();
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->lastStatusTokensIn = $tokensIn;
        $this->lastStatusTokensOut = $tokensOut;
        $this->lastStatusCost = $cost;
        $this->lastStatusMaxContext = $maxContext;

        if ($this->statusBar->getMaxSteps() !== $maxContext) {
            $this->statusBar->start($maxContext, $tokensIn);
        } else {
            $this->statusBar->setProgress($tokensIn);
        }

        $inLabel = Theme::formatTokenCount($tokensIn);
        $maxLabel = Theme::formatTokenCount($maxContext);
        $ratio = min(1.0, $tokensIn / max(1, $maxContext));
        $r = "\033[0m";
        $sep = "\033[38;5;240m·{$r}";
        $dimWhite = "\033[38;2;140;140;150m";
        $ctxColor = Theme::contextColor($ratio);
        $this->statusDetail = "{$ctxColor}{$inLabel}/{$maxLabel}{$r} {$sep} {$dimWhite}{$model}{$r}";
        $this->refreshStatusBar();
        $this->flushRender();
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void
    {
        $tokensIn = min($this->lastStatusTokensIn ?? 0, $maxContext);

        if ($this->statusBar->getMaxSteps() !== $maxContext) {
            $this->statusBar->start($maxContext, $tokensIn);
        } else {
            $this->statusBar->setProgress($tokensIn);
        }

        $label = $provider.'/'.$model;
        $r = "\033[0m";
        $dimWhite = "\033[38;2;140;140;150m";

        if ($this->lastStatusMaxContext === null) {
            $this->statusDetail = "{$dimWhite}{$label}{$r}";
        } else {
            $inLabel = Theme::formatTokenCount($tokensIn);
            $maxLabel = Theme::formatTokenCount($maxContext);
            $ratio = min(1.0, $tokensIn / max(1, $maxContext));
            $sep = "\033[38;5;240m·{$r}";
            $ctxColor = Theme::contextColor($ratio);
            $this->statusDetail = "{$ctxColor}{$inLabel}/{$maxLabel}{$r} {$sep} {$dimWhite}{$label}{$r}";
        }

        $this->refreshStatusBar();
        $this->flushRender();
    }

    public function consumeQueuedMessage(): ?string
    {
        if ($this->messageQueue === []) {
            return null;
        }

        return array_shift($this->messageQueue);
    }

    public function setImmediateCommandHandler(?\Closure $handler): void
    {
        $this->immediateCommandHandler = $handler;
    }

    public function teardown(): void
    {
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

    public function refreshTaskBar(): void
    {
        if ($this->taskStore === null || $this->taskStore->isEmpty()) {
            $this->taskBar->setText('');

            return;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        $border = Theme::borderTask();
        $accent = Theme::accent();

        $breathColor = $this->animationManager->getBreathColor();
        $tree = $this->taskStore->renderAnsiTree($breathColor);
        $lines = explode("\n", $tree);

        $bar = "  {$border}┌ {$accent}Tasks{$r}";
        foreach ($lines as $line) {
            $bar .= "\n  {$border}│{$r} {$line}";
        }

        $thinkingPhrase = $this->animationManager->getThinkingPhrase();
        if ($thinkingPhrase !== null && ! $this->taskStore->hasInProgress() && $this->animationManager->getLoader() === null) {
            $color = $breathColor ?? "\033[38;2;112;160;208m";
            $bar .= "\n  {$border}│{$r}";
            $bar .= "\n  {$border}│{$r} {$color}{$thinkingPhrase}{$r}";

            if (! $this->subagentDisplay->hasRunningAgents()) {
                $elapsed = (int) (microtime(true) - $this->animationManager->getThinkingStartTime());
                $formatted = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $bar .= "{$dim} · {$formatted}{$r}";
            }
        }

        $bar .= "\n  {$border}└{$r}";

        $this->taskBar->setText($bar);
    }

    // ���─ Public helpers for other sub-renderers ──────────────────────────

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
        $this->markHiddenConversationActivity();
    }

    public function queueQuestionRecap(string $question, string $answer, bool $answered, bool $recommended = false): void
    {
        $this->pendingQuestionRecap[] = [
            'question' => $question,
            'answer' => $answer,
            'answered' => $answered,
            'recommended' => $answered && $recommended,
        ];
    }

    public function flushPendingQuestionRecap(): void
    {
        if ($this->pendingQuestionRecap === []) {
            return;
        }

        $this->addConversationWidget(new AnsweredQuestionsWidget($this->pendingQuestionRecap));
        $this->pendingQuestionRecap = [];
        $this->flushRender();
    }

    public function clearPendingQuestionRecap(): void
    {
        $this->pendingQuestionRecap = [];
    }

    public function clearConversationState(): void
    {
        $this->conversation->clear();
        $this->activeResponse = null;
        $this->activeResponseIsAnsi = false;
        $this->pendingQuestionRecap = [];
        $this->scrollOffset = 0;
        $this->hasHiddenActivityBelow = false;
        $this->historyStatus->hide();
        $this->tui->setScrollOffset(0);
    }

    /** @var (\Closure(): void)|null */
    private ?\Closure $discoveryBatchFinalizer = null;

    public function setDiscoveryBatchFinalizer(\Closure $finalizer): void
    {
        $this->discoveryBatchFinalizer = $finalizer;
    }

    public function finalizeDiscoveryBatch(): void
    {
        if ($this->discoveryBatchFinalizer !== null) {
            ($this->discoveryBatchFinalizer)();
        }
    }

    public function queueMessage(string $message): void
    {
        $this->messageQueue[] = $message;
        $this->showUserMessage($message);
    }

    // ── Private helpers ─────────────────────────────────────────────────

    private function refreshStatusBar(): void
    {
        $r = "\033[0m";
        $sep = "\033[38;5;240m·{$r}";
        $this->statusBar->setMessage(
            "{$this->currentModeColor}{$this->currentModeLabel}{$r} {$sep} "
            ."{$this->currentPermissionColor}{$this->currentPermissionLabel}{$r} {$sep} "
            .$this->statusDetail
        );
    }

    private function containsAnsiEscapes(string $text): bool
    {
        return str_contains($text, "\x1b[");
    }

    private function markHiddenConversationActivity(): void
    {
        if (! $this->isBrowsingHistory()) {
            return;
        }

        $this->hasHiddenActivityBelow = true;
        $this->refreshHistoryStatus();
    }

    private function scrollHistoryUp(): void
    {
        $this->scrollOffset += $this->historyScrollStep();
        $this->applyScrollOffset();
    }

    private function scrollHistoryDown(): void
    {
        $this->scrollOffset = max(0, $this->scrollOffset - $this->historyScrollStep());
        if ($this->scrollOffset === 0) {
            $this->hasHiddenActivityBelow = false;
        }

        $this->applyScrollOffset();
    }

    private function jumpToLiveOutput(): void
    {
        $this->scrollOffset = 0;
        $this->hasHiddenActivityBelow = false;
        $this->applyScrollOffset();
    }

    private function applyScrollOffset(): void
    {
        $this->tui->setScrollOffset($this->scrollOffset);
        $this->refreshHistoryStatus();
        $this->flushRender();
    }

    private function refreshHistoryStatus(): void
    {
        if (! $this->isBrowsingHistory()) {
            $this->historyStatus->hide();

            return;
        }

        $this->historyStatus->show($this->hasHiddenActivityBelow);
    }

    private function isBrowsingHistory(): bool
    {
        return $this->scrollOffset > 0;
    }

    private function historyScrollStep(): int
    {
        return max(6, $this->tui->getTerminal()->getRows() - 10);
    }

    private function cycleMode(): string
    {
        $modes = ['edit', 'plan', 'ask'];
        $current = strtolower($this->currentModeLabel);
        $index = array_search($current, $modes, true);
        $next = $modes[($index + 1) % count($modes)];

        return $next;
    }

    private function showSlashCompletion(string $filter): void
    {
        $filtered = array_values(array_filter(
            self::SLASH_COMMANDS,
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

        $this->flushRender();
    }

    private function hideSlashCompletion(): void
    {
        if ($this->slashCompletion !== null) {
            $this->overlay->remove($this->slashCompletion);
            $this->slashCompletion = null;
            $this->flushRender();
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
        $this->flushRender();
    }

    public function bindInputHandlers(): void
    {
        $this->input->onInput(function (string $data): bool {
            $kb = $this->input->getKeybindings();

            if ($this->slashCompletion !== null) {
                if ($kb->matches($data, 'cursor_up') || $kb->matches($data, 'cursor_down')) {
                    $this->slashCompletion->handleInput($data);
                    $this->flushRender();

                    return true;
                }
                if ($kb->matches($data, 'submit')) {
                    $selected = $this->slashCompletion->getSelectedItem();
                    if ($selected !== null) {
                        $command = $selected['value'];
                        $this->input->setText('');
                        $this->hideSlashCompletion();
                        if ($this->promptSuspension !== null) {
                            $suspension = $this->promptSuspension;
                            $this->promptSuspension = null;
                            $suspension->resume($command);
                        }
                    }

                    return true;
                }
                if ($data === "\t") {
                    $selected = $this->slashCompletion->getSelectedItem();
                    if ($selected !== null) {
                        $this->input->setText($selected['value']);
                    }
                    $this->hideSlashCompletion();

                    return true;
                }
                if ($data === "\x1b") {
                    $this->hideSlashCompletion();

                    return true;
                }
            }

            if ($data === "\x01") {
                if ($this->immediateCommandHandler !== null) {
                    ($this->immediateCommandHandler)('/agents');
                }

                return true;
            }

            if ($kb->matches($data, 'history_up')) {
                $this->scrollHistoryUp();

                return true;
            }

            if ($kb->matches($data, 'history_down')) {
                $this->scrollHistoryDown();

                return true;
            }

            if ($this->isBrowsingHistory() && $kb->matches($data, 'history_end')) {
                $this->jumpToLiveOutput();

                return true;
            }

            if ($data === "\x0C") {
                $this->forceRender();

                return true;
            }

            if ($kb->matches($data, 'expand_tools')) {
                $this->toggleAllToolResults();

                return true;
            }

            if ($kb->matches($data, 'cycle_mode')) {
                $nextMode = $this->cycleMode();

                if ($this->promptSuspension !== null) {
                    $savedText = $this->input->getText();
                    $suspension = $this->promptSuspension;
                    $this->promptSuspension = null;
                    $this->pendingEditorRestore = $savedText;
                    $suspension->resume("/{$nextMode}");
                } else {
                    $modeColors = [
                        'edit' => "\033[38;2;80;200;120m",
                        'plan' => "\033[38;2;160;120;255m",
                        'ask' => "\033[38;2;255;180;60m",
                    ];
                    $this->showMode(ucfirst($nextMode), $modeColors[$nextMode] ?? '');
                    $this->messageQueue[] = "/{$nextMode}";
                    $this->requestCancellation?->cancel();
                }

                return true;
            }

            return false;
        });

        $this->input->onCancel(function () {
            $askSuspension = $this->modalManager->getAskSuspension();
            if ($askSuspension !== null) {
                $this->modalManager->clearAskSuspension();
                $askSuspension->resume('');

                return;
            }

            if ($this->requestCancellation !== null) {
                $this->requestCancellation->cancel();

                return;
            }

            if ($this->promptSuspension !== null) {
                $suspension = $this->promptSuspension;
                $this->promptSuspension = null;
                $suspension->resume('/quit');

                return;
            }

            if ($this->immediateCommandHandler !== null) {
                ($this->immediateCommandHandler)('/quit');
            }
        });

        $this->input->onChange(function (ChangeEvent $event) {
            $value = $event->getValue();

            if (str_starts_with($value, '/') && $value !== '/') {
                $this->showSlashCompletion($value);
            } elseif ($value === '/') {
                $this->showSlashCompletion('');
            } else {
                $this->hideSlashCompletion();
            }
        });

        $this->input->onSubmit(function (SubmitEvent $event) {
            $value = $event->getValue();
            $this->input->setText('');
            $this->hideSlashCompletion();

            $askSuspension = $this->modalManager->getAskSuspension();
            if ($askSuspension !== null) {
                $this->modalManager->clearAskSuspension();
                $askSuspension->resume($value);

                return;
            }

            if ($this->requestCancellation !== null) {
                if (trim($value) !== '') {
                    if ($this->immediateCommandHandler !== null && ($this->immediateCommandHandler)($value)) {
                        return;
                    }
                    $this->queueMessage($value);
                }

                return;
            }

            if ($this->promptSuspension !== null) {
                $suspension = $this->promptSuspension;
                $this->promptSuspension = null;
                $suspension->resume($value);

                return;
            }

            if (trim($value) !== '') {
                $this->queueMessage($value);
            }
        });
    }
}

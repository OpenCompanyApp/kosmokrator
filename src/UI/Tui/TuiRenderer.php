<?php

namespace Kosmokrator\UI\Tui;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiIntro;
use Kosmokrator\UI\Ansi\AnsiPrometheus;
use Kosmokrator\UI\Ansi\AnsiTheogony;
use Kosmokrator\UI\Ansi\KosmokratorTerminalTheme;
use Kosmokrator\UI\Diff\DiffRenderer;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\TerminalNotification;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Widget\AnsiArtWidget;
use Kosmokrator\UI\Tui\Widget\AnsweredQuestionsWidget;
use Kosmokrator\UI\Tui\Widget\BashCommandWidget;
use Kosmokrator\UI\Tui\Widget\CollapsibleWidget;
use Kosmokrator\UI\Tui\Widget\DiscoveryBatchWidget;
use Kosmokrator\UI\Tui\Widget\HistoryStatusWidget;
use Kosmokrator\UI\Tui\Widget\ToggleableWidgetInterface;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Tempest\Highlight\Highlighter;

/**
 * Main TUI renderer implementing the full-screen terminal interface.
 *
 * Delegates phase/animation to TuiAnimationManager, overlay dialogs to TuiModalManager,
 * subagent lifecycle to SubagentDisplayManager, tool classification to ExplorationClassifier,
 * and permission previews to PermissionPreviewBuilder.
 */
class TuiRenderer implements RendererInterface
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

    private ?DiffRenderer $diffRenderer = null;

    private ?BashCommandWidget $activeBashWidget = null;

    private ?CancellableLoaderWidget $toolExecutingLoader = null;

    private ?string $toolExecutingTimerId = null;

    private float $toolExecutingStartTime = 0.0;

    private int $toolExecutingBreathTick = 0;

    private ?string $toolExecutingPreview = null;

    /** @var (\Closure(string): bool)|null */
    private ?\Closure $immediateCommandHandler = null;

    private ?Suspension $promptSuspension = null;

    private ?SelectListWidget $slashCompletion = null;

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
    ];

    private ?Highlighter $highlighter = null;

    private array $lastToolArgs = [];

    private ?TaskStore $taskStore = null;

    /** @var array<array{question: string, answer: string, answered: bool, recommended: bool}> */
    private array $pendingQuestionRecap = [];

    private ?DiscoveryBatchWidget $activeDiscoveryBatch = null;

    /** @var list<array{name: string, label: string, detail: string, summary: string, status: 'pending'|'success'|'error'}> */
    private array $activeDiscoveryItems = [];

    private int $scrollOffset = 0;

    private bool $hasHiddenActivityBelow = false;

    /** Injects the TaskStore for rendering the task tree in the task bar. */
    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    /** Builds the full TUI layout: conversation, overlay, task bar, thinking bar, editor, and status bar. */
    public function initialize(): void
    {
        $this->tui = new Tui(KosmokratorStyleSheet::create());

        // Root layout: conversation + status bar + input (vertical)
        $this->session = new ContainerWidget;
        $this->session->setId('session');
        $this->session->addStyleClass('session');
        $this->session->expandVertically(true);

        // Scrollable conversation
        $this->conversation = new ContainerWidget;
        $this->conversation->setId('conversation');
        $this->conversation->expandVertically(true);

        $this->historyStatus = new HistoryStatusWidget;
        $this->historyStatus->setId('history-status');

        // Status bar at bottom — context progress bar
        $this->statusBar = new ProgressBarWidget(200_000, '%message%  %bar%');
        $this->statusBar->setId('status-bar');
        $this->statusBar->setBarCharacter('━');
        $this->statusBar->setEmptyBarCharacter('─');
        $this->statusBar->setProgressCharacter('━');
        $this->statusBar->setBarWidth(20);
        $this->refreshStatusBar();
        $this->statusBar->start(200_000, 0);

        // Overlay container — pinned between status bar and input, zero-height when empty
        $this->overlay = new ContainerWidget;
        $this->overlay->setId('overlay');

        // Task bar — persistent task tree above the input, empty when no tasks
        $this->taskBar = new TextWidget('');
        $this->taskBar->setId('task-bar');

        // Thinking bar — loader sits between task bar and status bar
        $this->thinkingBar = new ContainerWidget;
        $this->thinkingBar->setId('thinking-bar');

        // Subagent display — owns spawn/running/batch lifecycle and timer state
        $this->subagentDisplay = new SubagentDisplayManager(
            conversation: $this->conversation,
            breathColorProvider: fn () => $this->animationManager->getBreathColor(),
            renderCallback: fn () => $this->flushRender(),
            ensureSpinners: fn () => $this->animationManager->ensureSpinnersRegistered(),
        );

        // Animation manager — owns thinking/compacting loaders, breathing timers, spinners
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

        // Multi-line editor prompt (Enter = submit, Shift+Enter / Alt+Enter = newline)
        $this->input = new EditorWidget;
        $this->input->setId('prompt');
        $this->input->setMinVisibleLines(1);
        $this->input->setMaxVisibleLines(2);
        $this->input->setKeybindings(new Keybindings([
            'copy' => [],                                  // Free ctrl+c for cancel
            'new_line' => ['shift+enter', 'alt+enter'],    // Both work: shift+enter with kitty, alt+enter without
            'cycle_mode' => ['shift+tab'],                 // Cycle through edit → plan → ask
            'history_up' => [Key::PAGE_UP],
            'history_down' => [Key::PAGE_DOWN],
            'history_end' => [Key::END],
        ]));

        // Modal manager — owns all overlay + Suspension dialogs
        $this->modalManager = new TuiModalManager(
            overlay: $this->overlay,
            sessionRoot: $this->session,
            tui: $this->tui,
            input: $this->input,
            renderCallback: fn () => $this->flushRender(),
            forceRenderCallback: fn () => $this->forceRender(),
        );

        $this->bindInputHandlers();

        // Assemble layout: conversation → overlay → taskBar → thinkingBar → input → statusBar
        $this->session->add($this->conversation);
        $this->session->add($this->historyStatus);
        $this->session->add($this->overlay);
        $this->session->add($this->taskBar);
        $this->session->add($this->thinkingBar);
        $this->session->add($this->input);
        $this->session->add($this->statusBar);

        $this->tui->add($this->session);
        $this->tui->setFocus($this->input);

        // Start TUI — registers stdin/signal watchers with Revolt
        $this->tui->start();
    }

    /**
     * Renders the startup splash: ANSI art animation, orrery diagram, and quick-reference tutorial.
     *
     * @param  bool  $animated  Whether to play the full ANSI animation or show a static splash
     */
    public function renderIntro(bool $animated): void
    {
        // Run the full ANSI animated intro first (before TUI takes over the screen)
        $intro = new AnsiIntro;
        if ($animated) {
            $skipped = $intro->animate();
            if (! $skipped) {
                // Pause to admire the full animation
                usleep(800000);
            }
            echo "\033[2J\033[H";
        } else {
            $intro->renderStatic();
            sleep(1);
            echo "\033[2J\033[H";
        }

        // Force full re-render so ScreenWriter doesn't diff against stale state
        $this->tui->requestRender(force: true);

        // Now add a compact header + tutorial inside the TUI conversation
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

    /**
     * Suspends execution until the user submits input, then returns the entered text.
     *
     * @return string The user's input text (or a slash command)
     */
    public function prompt(): string
    {
        $this->flushPendingQuestionRecap();

        // Restore editor text if mode was cycled via Shift+Tab
        if ($this->pendingEditorRestore !== null) {
            $this->input->setText($this->pendingEditorRestore);
            $this->pendingEditorRestore = null;
        }

        $this->tui->setFocus($this->input);
        $this->promptSuspension = EventLoop::getSuspension();

        return $this->promptSuspension->suspend();
    }

    /**
     * Displays a user message bubble in the conversation area.
     *
     * @param  string  $text  The raw user message to display
     */
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

    /**
     * Transitions the renderer to a new agent phase, updating loaders and cancellation state.
     *
     * @param  AgentPhase  $phase  The target phase (Thinking, RunningTools, Idle)
     */
    public function setPhase(AgentPhase $phase): void
    {
        if ($phase === $this->animationManager->getCurrentPhase()) {
            return;
        }

        if ($phase === AgentPhase::Thinking && $this->requestCancellation === null) {
            $this->requestCancellation = new DeferredCancellation;
        }

        $this->animationManager->setPhase($phase, $this->requestCancellation);

        // The status bar always shows model/context/cost (set by showStatus()).
        // Don't overwrite it with "Thinking..." / "Running tools..." — the thinking
        // loader widget already conveys phase information.
        if ($phase !== AgentPhase::Idle) {
            $this->refreshStatusBar();
        }

        if ($phase === AgentPhase::Idle) {
            $this->requestCancellation = null;
            TerminalNotification::notify();
        }
    }

    /** Shortcut to transition into the Thinking phase. */
    public function showThinking(): void
    {
        $this->setPhase(AgentPhase::Thinking);
    }

    /** Shortcut to transition back to Idle phase. */
    public function clearThinking(): void
    {
        $this->setPhase(AgentPhase::Idle);
    }

    /** Delegates to TuiAnimationManager to show the compacting loader. */
    public function showCompacting(): void
    {
        $this->animationManager->showCompacting();
    }

    /** Delegates to TuiAnimationManager to clear the compacting loader. */
    public function clearCompacting(): void
    {
        $this->animationManager->clearCompacting();
    }

    /**
     * Returns the current request's cancellation token, or null if no request is active.
     *
     * @return Cancellation|null Cancellation token for the in-flight LLM request
     */
    public function getCancellation(): ?Cancellation
    {
        return $this->requestCancellation?->getCancellation();
    }

    /**
     * Appends a streaming text chunk to the active response widget, auto-detecting ANSI art.
     *
     * @param  string  $text  The incremental text chunk from the LLM response
     */
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
            // Mid-stream ANSI detection: swap MarkdownWidget → AnsiArtWidget
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

    /** Finalizes the current streaming response and resets the active response state. */
    public function streamComplete(): void
    {
        $this->activeResponse = null;
        $this->activeResponseIsAnsi = false;
        $this->finalizeDiscoveryBatch();
        $this->flushRender();
    }

    /**
     * Displays a tool call in the conversation, dispatching to specialized handlers
     * for bash, discovery/omens, task, and ask tools.
     *
     * @param  string  $name  The tool name (e.g. 'file_read', 'bash', 'subagent')
     * @param  array   $args  The tool call arguments
     */
    public function showToolCall(string $name, array $args): void
    {
        if (! in_array($name, ['ask_user', 'ask_choice'], true)) {
            $this->flushPendingQuestionRecap();
        }

        $this->lastToolArgs = $args;
        $icon = Theme::toolIcon($name);
        $friendly = Theme::toolLabel($name);
        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();

        // Task tools: update task bar only, no conversation widget (task bar shows the tree)
        if ($this->isTaskTool($name)) {
            $this->finalizeDiscoveryBatch();
            $this->refreshTaskBar();
            $this->flushRender();

            return;
        }

        // Ask tools: silent — the question is shown by the tool's UI method
        if (in_array($name, ['ask_user', 'ask_choice'], true)) {
            $this->finalizeDiscoveryBatch();

            return;
        }

        // Subagent: handled by showSubagentSpawn/showSubagentBatch
        if ($name === 'subagent') {
            $this->finalizeDiscoveryBatch();

            return;
        }

        if ($name === 'bash' && ! $this->isOmensTool($name, $args)) {
            $this->finalizeDiscoveryBatch();
            $this->beginBashCommand((string) ($args['command'] ?? ''));
            $this->flushRender();

            return;
        }

        if ($this->isOmensTool($name, $args)) {
            $this->appendDiscoveryToolCall($name, $args);
            $this->flushRender();

            return;
        }

        $this->finalizeDiscoveryBatch();

        // Compact single-line display for file tools
        if (in_array($name, ['file_read', 'file_write', 'file_edit']) && isset($args['path'])) {
            $path = Theme::relativePath($args['path']);
            $label = "{$icon} {$friendly}  {$path}";
            if (isset($args['offset'])) {
                $label .= ":{$args['offset']}";
            }
        } else {
            // Skip large content args
            $skipKeys = ['content', 'old_string', 'new_string'];
            $parts = [];
            foreach ($args as $key => $value) {
                if (in_array($key, $skipKeys, true)) {
                    continue;
                }
                $display = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                $parts[] = "{$key}: {$display}";
            }
            $label = "{$icon} {$friendly}  ".implode('  ', $parts);
        }

        $maxToolCallWidth = 120;

        if (mb_strlen($label) > $maxToolCallWidth) {
            $header = "{$icon} {$friendly}";
            $argsStr = mb_substr($label, mb_strlen($header) + 2); // strip "icon label  " prefix
            $widget = new CollapsibleWidget($header, $argsStr, 1, $maxToolCallWidth);
            $widget->addStyleClass('tool-call');
        } else {
            $widget = new TextWidget($label);
            $widget->addStyleClass('tool-call');
        }

        $this->addConversationWidget($widget);
        $this->flushRender();
    }

    /**
     * Displays a tool result with success/failure indicator, using diff views for edits
     * and syntax highlighting for file reads.
     *
     * @param  string  $name     The tool name
     * @param  string  $output   The raw tool output
     * @param  bool    $success  Whether the tool call succeeded
     */
    public function showToolResult(string $name, string $output, bool $success): void
    {
        if (! in_array($name, ['ask_user', 'ask_choice'], true)) {
            $this->flushPendingQuestionRecap();
        }

        $statusColor = $success ? Theme::success() : Theme::error();
        $indicator = $success ? '✓' : '✗';
        $r = Theme::reset();
        $text = Theme::text();

        $header = "{$statusColor}{$indicator}{$r}";

        // Task tools: silent result — the call line + sticky task bar are enough
        if ($this->isTaskTool($name)) {
            $this->refreshTaskBar();
            $this->flushRender();

            return;
        }

        // Ask tools: silent result — the user already saw their own answer
        if (in_array($name, ['ask_user', 'ask_choice'], true)) {
            return;
        }

        // Subagent: handled by showSubagentBatch
        if ($name === 'subagent') {
            return;
        }

        if ($name === 'bash' && ! $this->isOmensTool($name, $this->lastToolArgs)) {
            $this->completeBashCommand($output, $success);
            $this->flushRender();

            return;
        }

        if ($this->isOmensTool($name, $this->lastToolArgs)) {
            $this->completeDiscoveryToolResult($name, $output, $success);
            $this->flushRender();

            return;
        }

        // Diff view for file_edit
        if ($name === 'file_edit' && $success && isset($this->lastToolArgs['old_string'])) {
            $content = $this->buildDiffView(
                $this->lastToolArgs['old_string'],
                $this->lastToolArgs['new_string'] ?? '',
                $this->lastToolArgs['path'] ?? '',
            );
            $lineCount = count(explode("\n", $content));
        } elseif ($name === 'file_read' && $success) {
            // Syntax-highlight file_read content
            $content = $this->highlightFileOutput($output);
            $lineCount = count(explode("\n", $output));
        } else {
            $content = implode("\n", array_map(fn (string $l) => "{$text}{$l}{$r}", explode("\n", $output)));
            $lineCount = count(explode("\n", $output));
        }

        $widget = new CollapsibleWidget($header, $content, $lineCount);
        $widget->addStyleClass('tool-result');
        if ($name === 'file_edit' && $success) {
            $widget->setExpanded(true);
        }
        $this->addConversationWidget($widget);
        $this->flushRender();
    }

    /**
     * Delegates to TuiModalManager to show a permission prompt for a tool call.
     *
     * @param  string  $toolName  The tool requesting permission
     * @param  array   $args      The tool call arguments for preview
     * @return string The user's decision ('allow', 'deny', 'always', 'guardian', or 'prometheus')
     */
    public function askToolPermission(string $toolName, array $args): string
    {
        return $this->modalManager->askToolPermission($toolName, $args);
    }

    public function showAutoApproveIndicator(string $toolName): void
    {
        // Intentionally silent — auto-approve is already visible in the status bar
    }

    /**
     * Shows an animated loader for a tool that is currently executing, with elapsed time.
     *
     * @param  string  $name  The tool name being executed
     */
    public function showToolExecuting(string $name): void
    {
        if ($this->isTaskTool($name)
            || $name === 'bash'
            || $this->isOmensTool($name, [])
            || in_array($name, ['ask_user', 'ask_choice', 'subagent'], true)) {
            return;
        }

        $this->animationManager->ensureSpinnersRegistered();

        $r = Theme::reset();
        $dim = Theme::dim();
        $blue = "\033[38;2;112;160;208m";

        $this->toolExecutingLoader = new CancellableLoaderWidget("{$blue}running...{$r}");
        $this->toolExecutingLoader->setId('tool-executing');
        $this->toolExecutingLoader->addStyleClass('tool-result');
        $this->toolExecutingLoader->setSpinner('cosmos', 120);
        $this->toolExecutingStartTime = microtime(true);
        $this->toolExecutingBreathTick = 0;

        $this->addConversationWidget($this->toolExecutingLoader);

        $this->toolExecutingTimerId = EventLoop::repeat(0.05, function () use ($dim, $r): void {
            if ($this->toolExecutingLoader === null) {
                return;
            }
            $this->toolExecutingBreathTick++;
            $t = sin($this->toolExecutingBreathTick * 0.07);
            $cr = (int) (112 + 40 * $t);
            $cg = (int) (160 + 40 * $t);
            $cb = (int) (208 + 47 * $t);
            $color = "\033[38;2;{$cr};{$cg};{$cb}m";

            $elapsed = (int) (microtime(true) - $this->toolExecutingStartTime);
            $time = $elapsed > 0 ? " {$dim}({$elapsed}s){$r}" : '';

            $preview = $this->toolExecutingPreview ?? 'running...';
            $this->toolExecutingLoader->setMessage("{$color}{$preview}{$r}{$time}");
            $this->flushRender();
        });

        $this->flushRender();
    }

    /**
     * Updates the executing-tool loader with the latest output preview.
     *
     * @param  string  $output  Recent tool output to preview
     */
    public function updateToolExecuting(string $output): void
    {
        // Show last non-empty line as preview
        $lines = explode("\n", trim($output));
        $last = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed !== '') {
                $last = $trimmed;
                break;
            }
        }
        if ($last !== '') {
            $this->toolExecutingPreview = mb_strlen($last) > 100 ? mb_substr($last, 0, 100).'…' : $last;
        }
    }

    /** Removes the tool-executing loader and cancels its animation timer. */
    public function clearToolExecuting(): void
    {
        if ($this->toolExecutingTimerId !== null) {
            EventLoop::cancel($this->toolExecutingTimerId);
            $this->toolExecutingTimerId = null;
        }
        if ($this->toolExecutingLoader !== null) {
            $this->toolExecutingLoader->setFinishedIndicator('');
            $this->toolExecutingLoader->stop();
            $this->conversation->remove($this->toolExecutingLoader);
            $this->toolExecutingLoader = null;
        }
        $this->toolExecutingPreview = null;
    }

    /**
     * Updates the status bar to reflect the active permission mode.
     *
     * @param  string  $label  The permission mode label (e.g. 'Guardian ◈')
     * @param  string  $color  ANSI color code for the label
     */
    public function setPermissionMode(string $label, string $color): void
    {
        $this->currentPermissionLabel = $label;
        $this->currentPermissionColor = $color;
        $this->refreshStatusBar();
        $this->flushRender();
    }

    /**
     * Delegates to TuiModalManager to show plan approval dialog.
     *
     * @param  string  $currentPermissionMode  The current permission mode identifier
     * @return array{permission: string, context: string}|null Approved plan data, or null if rejected
     */
    public function approvePlan(string $currentPermissionMode): ?array
    {
        return $this->modalManager->approvePlan($currentPermissionMode);
    }

    /**
     * Shows a free-text question modal and queues the answer for later recap display.
     *
     * @param  string  $question  The question to ask
     * @return string The user's answer
     */
    public function askUser(string $question): string
    {
        $answer = $this->modalManager->askUser($question);
        $trimmed = trim($answer);

        $this->queueQuestionRecap(
            question: $question,
            answer: $trimmed,
            answered: $trimmed !== '',
        );

        return $answer;
    }

    /**
     * Shows a multiple-choice modal and queues the selection for later recap display.
     *
     * @param  string  $question  The question prompt
     * @param  array   $choices   Array of choice options with labels and details
     * @return string The selected choice label, or 'dismissed'
     */
    public function askChoice(string $question, array $choices): string
    {
        $result = $this->modalManager->askChoice($question, $choices);
        $selected = $this->findChoice($choices, $result);

        $this->queueQuestionRecap(
            question: $question,
            answer: $result === 'dismissed' ? '' : $result,
            answered: $result !== 'dismissed',
            recommended: (bool) ($selected['recommended'] ?? false),
        );

        return $result;
    }

    /** Applies syntax highlighting to file_read output, preserving line-number prefixes. */
    private function highlightFileOutput(string $output, ?string $path = null): string
    {
        $path ??= $this->lastToolArgs['path'] ?? '';
        $language = KosmokratorTerminalTheme::detectLanguage($path);
        if ($language === '') {
            return $output;
        }

        $lines = explode("\n", $output);
        $lineNums = [];
        $codeLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\s*\d+)\t(.*)$/', $line, $m)) {
                $lineNums[] = $m[1];
                $codeLines[] = $m[2];
            } else {
                $lineNums[] = null;
                $codeLines[] = $line;
            }
        }

        $code = implode("\n", $codeLines);
        try {
            $highlighted = $this->getHighlighter()->parse($code, $language);
        } catch (\Throwable) {
            return $output;
        }

        $highlightedLines = explode("\n", $highlighted);
        $result = [];
        foreach ($highlightedLines as $i => $hLine) {
            if (isset($lineNums[$i]) && $lineNums[$i] !== null) {
                $result[] = "\033[38;5;240m{$lineNums[$i]}\033[0m\t{$hLine}";
            } else {
                $result[] = $hLine;
            }
        }

        return implode("\n", $result);
    }

    /** Renders a unified diff between old and new strings via DiffRenderer. */
    private function buildDiffView(string $old, string $new, string $path): string
    {
        return $this->getDiffRenderer()->render($old, $new, $path);
    }

    private function getDiffRenderer(): DiffRenderer
    {
        return $this->diffRenderer ??= new DiffRenderer;
    }

    private function getHighlighter(): Highlighter
    {
        return $this->highlighter ??= new Highlighter(new KosmokratorTerminalTheme);
    }

    /** Clears all conversation widgets and resets scroll/discovery state. */
    public function clearConversation(): void
    {
        $this->conversation->clear();
        $this->activeResponse = null;
        $this->activeResponseIsAnsi = false;
        $this->activeBashWidget = null;
        $this->pendingQuestionRecap = [];
        $this->activeDiscoveryBatch = null;
        $this->activeDiscoveryItems = [];
        $this->scrollOffset = 0;
        $this->hasHiddenActivityBelow = false;
        $this->historyStatus->hide();
        $this->tui->setScrollOffset(0);
        $this->flushRender();
    }

    /**
     * Rebuilds the conversation from a message history array, pairing tool calls with their results.
     *
     * @param  array  $messages  Array of Prism message objects (UserMessage, AssistantMessage, ToolResultMessage)
     */
    public function replayHistory(array $messages): void
    {
        $this->activeBashWidget = null;
        $this->pendingQuestionRecap = [];
        $this->activeDiscoveryBatch = null;
        $this->activeDiscoveryItems = [];
        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();
        $text = Theme::text();

        // Index tool results by toolCallId for pairing with tool calls
        $resultsByCallId = [];
        foreach ($messages as $msg) {
            if ($msg instanceof ToolResultMessage) {
                foreach ($msg->toolResults as $toolResult) {
                    $resultsByCallId[$toolResult->toolCallId] = $toolResult;
                }
            }
        }

        foreach ($messages as $msg) {
            if ($msg instanceof SystemMessage
                || $msg instanceof ToolResultMessage) {
                continue; // Results are rendered inline with their tool calls
            }

            if ($msg instanceof UserMessage) {
                $this->flushPendingQuestionRecap();
                $widget = new TextWidget('⟡ '.$msg->content);
                $widget->addStyleClass('user-message');
                $this->addConversationWidget($widget);

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                $discoveryGroup = [];
                $flushDiscoveryGroup = function () use (&$discoveryGroup): void {
                    if ($discoveryGroup === []) {
                        return;
                    }

                    $widget = new DiscoveryBatchWidget($discoveryGroup);
                    $widget->addStyleClass('tool-batch');
                    $this->addConversationWidget($widget);
                    $discoveryGroup = [];
                };

                // Text content
                if ($msg->content !== '') {
                    $this->flushPendingQuestionRecap();
                    if ($this->containsAnsiEscapes($msg->content)) {
                        $widget = new AnsiArtWidget($msg->content);
                        $widget->addStyleClass('ansi-art');
                    } else {
                        $widget = new MarkdownWidget($msg->content);
                        $widget->addStyleClass('response');
                    }
                    $this->addConversationWidget($widget);
                }

                // Tool calls — each paired with its result
                foreach ($msg->toolCalls as $toolCall) {
                    $name = $toolCall->name;
                    $args = $toolCall->arguments();
                    $toolResult = $resultsByCallId[$toolCall->id] ?? null;

                    if ($name === 'ask_user') {
                        $flushDiscoveryGroup();
                        $answer = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE))
                            : '';
                        $trimmed = trim($answer);

                        $this->queueQuestionRecap(
                            question: (string) ($args['question'] ?? ''),
                            answer: $trimmed,
                            answered: $trimmed !== '',
                        );

                        continue;
                    }

                    if ($name === 'ask_choice') {
                        $flushDiscoveryGroup();
                        $answer = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE))
                            : 'dismissed';
                        $selected = $this->findChoiceFromArgs($args, $answer);

                        $this->queueQuestionRecap(
                            question: (string) ($args['question'] ?? ''),
                            answer: $answer === 'dismissed' ? '' : $answer,
                            answered: $answer !== 'dismissed',
                            recommended: (bool) ($selected['recommended'] ?? false),
                        );

                        continue;
                    }

                    // Task tools: skip — task bar shows the tree
                    if ($this->isTaskTool($name)) {
                        $flushDiscoveryGroup();

                        continue;
                    }

                    $this->flushPendingQuestionRecap();

                    if ($this->isOmensTool($name, $args)) {
                        $output = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE))
                            : '';
                        $discoveryGroup[] = $this->buildDiscoveryItem(
                            $name,
                            $args,
                            $output,
                            $toolResult !== null,
                            $this->inferHistoricToolSuccess($name, $toolResult),
                        );

                        continue;
                    }

                    $flushDiscoveryGroup();

                    if ($name === 'bash') {
                        $bashWidget = new BashCommandWidget((string) ($args['command'] ?? ''));
                        $bashWidget->addStyleClass('tool-shell');
                        if ($toolResult !== null) {
                            $output = is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE);
                            $bashWidget->setResult($output, $this->inferHistoricToolSuccess($name, $toolResult));
                        }
                        $this->addConversationWidget($bashWidget);

                        continue;
                    }

                    // Render tool call line
                    $icon = Theme::toolIcon($name);
                    $friendly = Theme::toolLabel($name);

                    if (in_array($name, ['file_read', 'file_write', 'file_edit']) && isset($args['path'])) {
                        $path = Theme::relativePath($args['path']);
                        $label = "{$icon} {$friendly}  {$path}";
                        if (isset($args['offset'])) {
                            $label .= ":{$args['offset']}";
                        }
                    } else {
                        $skipKeys = ['content', 'old_string', 'new_string'];
                        $parts = [];
                        foreach ($args as $key => $value) {
                            if (in_array($key, $skipKeys, true)) {
                                continue;
                            }
                            $display = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                            $parts[] = "{$key}: {$display}";
                        }
                        $label = "{$icon} {$friendly}  ".implode('  ', $parts);
                    }

                    $maxWidth = 120;
                    if (mb_strlen($label) > $maxWidth) {
                        $header = "{$icon} {$friendly}";
                        $argsStr = mb_substr($label, mb_strlen($header) + 2);
                        $w = new CollapsibleWidget($header, $argsStr, 1, $maxWidth);
                        $w->addStyleClass('tool-call');
                    } else {
                        $w = new TextWidget($label);
                        $w->addStyleClass('tool-call');
                    }
                    $this->addConversationWidget($w);

                    // Render paired result immediately after the call
                    if ($toolResult !== null) {
                        $this->lastToolArgs = $toolResult->args;
                        $output = is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE);
                        $statusColor = Theme::success();
                        $resultHeader = "{$statusColor}✓{$r}";

                        if ($name === 'file_edit' && isset($toolResult->args['old_string'])) {
                            $content = $this->buildDiffView(
                                $toolResult->args['old_string'],
                                $toolResult->args['new_string'] ?? '',
                                $toolResult->args['path'] ?? '',
                            );
                            $lineCount = count(explode("\n", $content));
                        } elseif ($name === 'file_read') {
                            $content = $this->highlightFileOutput($output);
                            $lineCount = count(explode("\n", $output));
                        } else {
                            $content = implode("\n", array_map(fn (string $l) => "{$text}{$l}{$r}", explode("\n", $output)));
                            $lineCount = count(explode("\n", $output));
                        }

                        $rw = new CollapsibleWidget($resultHeader, $content, $lineCount);
                        $rw->addStyleClass('tool-result');
                        $this->addConversationWidget($rw);
                    }
                }

                $flushDiscoveryGroup();

                continue;
            }
        }

        $this->flushPendingQuestionRecap();
        $this->flushRender();
    }

    /** Displays an informational notice in the conversation. */
    public function showNotice(string $message): void
    {
        $this->flushPendingQuestionRecap();
        $widget = new TextWidget($message);
        $widget->addStyleClass('subtitle');
        $this->addConversationWidget($widget);
        $this->flushRender();
    }

    /** Updates the status bar with the current agent mode label and color. */
    public function showMode(string $label, string $color = ''): void
    {
        $this->currentModeLabel = $label;
        if ($color !== '') {
            $this->currentModeColor = $color;
        }
        $this->refreshStatusBar();
        $this->flushRender();
    }

    /** Displays an error message in the conversation. */
    public function showError(string $message): void
    {
        $this->flushPendingQuestionRecap();
        $widget = new TextWidget("✗ Error: {$message}");
        $widget->addStyleClass('tool-error');
        $this->addConversationWidget($widget);
        $this->flushRender();
    }

    /**
     * Updates the status bar with model name, token usage, cost, and context window progress.
     *
     * @param  string  $model       The model identifier (e.g. 'claude-sonnet-4')
     * @param  int     $tokensIn    Input tokens consumed
     * @param  int     $tokensOut   Output tokens generated
     * @param  float   $cost        Total cost in USD
     * @param  int     $maxContext  Maximum context window size in tokens
     */
    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->lastStatusTokensIn = $tokensIn;
        $this->lastStatusTokensOut = $tokensOut;
        $this->lastStatusCost = $cost;
        $this->lastStatusMaxContext = $maxContext;

        // Update progress bar max if model changed
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

    /**
     * Updates the status bar when the runtime model/provider changes mid-session.
     *
     * @param  string  $provider    The provider name
     * @param  string  $model       The model identifier
     * @param  int     $maxContext  New maximum context window size
     */
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

    /** Rebuilds the status bar message from current mode, permission, and status detail. */
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

    /**
     * Opens the settings modal; re-binds input handlers after close.
     *
     * @param  array  $currentSettings  Current setting key-value pairs
     * @return array The updated settings
     */
    public function showSettings(array $currentSettings): array
    {
        $result = $this->modalManager->showSettings($currentSettings);
        $this->bindInputHandlers();
        $this->tui->setFocus($this->input);
        $this->forceRender();

        return $result;
    }

    /** Delegates to TuiModalManager to show session picker and return the selected session ID. */
    public function pickSession(array $items): ?string
    {
        return $this->modalManager->pickSession($items);
    }

    public function showWelcome(): void
    {
        // Already handled in renderIntro
    }

    /** Suspends TUI, plays the KosmoKrator origin ANSI animation, then resumes TUI. */
    public function playTheogony(): void
    {
        // Suspend TUI — restores terminal to normal mode
        $this->tui->stop();
        echo "\033[2J\033[H";

        // Play raw ANSI animation
        $theogony = new AnsiTheogony;
        $theogony->animate();

        // Pause to admire, then restore TUI
        usleep(800000);
        echo "\033[2J\033[H";
        $this->tui->start();
        $this->forceRender();
    }

    /** Suspends TUI, plays the Prometheus ANSI animation, then resumes TUI. */
    public function playPrometheus(): void
    {
        $this->tui->stop();
        echo "\033[2J\033[H";

        $prometheus = new AnsiPrometheus;
        $prometheus->animate();

        echo "\033[2J\033[H";
        $this->tui->start();
        $this->forceRender();
    }

    /**
     * Returns the next queued user message (from interrupt-driven input during a request), or null.
     *
     * @return string|null The next queued message, or null if the queue is empty
     */
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

    private function queueMessage(string $message): void
    {
        $this->messageQueue[] = $message;
        $this->showUserMessage($message);
    }

    /**
     * Displays a text summary of all subagent statuses in the conversation.
     *
     * @param  array  $stats  Array of subagent status objects with status, task, agentType, toolCalls
     */
    public function showSubagentStatus(array $stats): void
    {
        if (empty($stats)) {
            return;
        }

        $running = count(array_filter($stats, fn ($s) => $s->status === 'running'));
        $done = count(array_filter($stats, fn ($s) => $s->status === 'done'));
        $total = count($stats);

        $lines = ["{$running} running, {$done}/{$total} finished"];

        foreach ($stats as $s) {
            $icon = match ($s->status) {
                'done' => '✓',
                'running' => '●',
                'failed' => '✗',
                'waiting' => '◌',
                'retrying' => '↻',
                default => '○',
            };
            $task = mb_substr($s->task, 0, 50);
            $type = ucfirst($s->agentType);
            $lines[] = "  {$icon} {$type} \"{$task}\" · {$s->toolCalls} tools";
        }

        $this->addConversationWidget(new TextWidget(implode("\n", $lines)));
    }

    public function clearSubagentStatus(): void
    {
        // TUI: status is part of conversation flow, nothing to actively clear
    }

    /** Delegates to SubagentDisplayManager to update running subagent entries. */
    public function showSubagentRunning(array $entries): void
    {
        $this->subagentDisplay->showRunning($entries);
    }

    /** Sets the closure that provides the live agent tree for display. */
    public function setAgentTreeProvider(?\Closure $provider): void
    {
        $this->subagentDisplay->setTreeProvider($provider);
    }

    /** Delegates to SubagentDisplayManager to refresh the agent tree visualization. */
    public function refreshSubagentTree(array $tree): void
    {
        $this->subagentDisplay->refreshTree($tree);
    }

    /** Delegates to SubagentDisplayManager to display newly spawned subagents. */
    public function showSubagentSpawn(array $entries): void
    {
        $this->subagentDisplay->showSpawn($entries);
    }

    /** Delegates to SubagentDisplayManager to display completed subagent batch results. */
    public function showSubagentBatch(array $entries): void
    {
        $this->subagentDisplay->showBatch($entries);
    }

    /** Delegates to TuiModalManager to show the agents swarm dashboard overlay. */
    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        $this->modalManager->showAgentsDashboard($summary, $allStats, $refresh);
    }

    /** Stops the TUI and restores the terminal to normal mode. */
    public function teardown(): void
    {
        if ($this->tui->isRunning()) {
            $this->tui->stop();
        }
    }

    /**
     * Request and immediately process a render pass.
     *
     * Widget.invalidate() increments the render revision but does NOT set
     * the renderRequested flag on the Tui.  processRender() is a no-op when
     * renderRequested is false.  Always pair the two calls via this helper
     * so renders are never silently skipped.
     */
    private function flushRender(): void
    {
        $this->tui->requestRender();
        $this->tui->processRender();
    }

    /**
     * Force a full re-render (clears all screen cache).
     */
    private function forceRender(): void
    {
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    /** Registers input, cancel, change, and submit handlers on the editor widget. */
    private function bindInputHandlers(): void
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

    /** Returns the next mode in the edit → plan → ask cycle. */
    private function cycleMode(): string
    {
        $modes = ['edit', 'plan', 'ask'];
        $current = strtolower($this->currentModeLabel);
        $index = array_search($current, $modes, true);
        $next = $modes[($index + 1) % count($modes)];

        return $next;
    }

    /** Shows or updates the slash command autocomplete dropdown filtered by the current input. */
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

    /** Removes the slash command autocomplete dropdown from the overlay. */
    private function hideSlashCompletion(): void
    {
        if ($this->slashCompletion !== null) {
            $this->overlay->remove($this->slashCompletion);
            $this->slashCompletion = null;
            $this->flushRender();
        }
    }

    /** Toggles expand/collapse on every ToggleableWidget in the conversation tree. */
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

    /** Delegates to ExplorationClassifier to check if a tool is an exploration/omens tool. */
    private function isOmensTool(string $name, array $args): bool
    {
        return ExplorationClassifier::isOmensTool($name, $args);
    }

    /** Adds a pending discovery item to the active batch widget, creating the batch if needed. */
    private function appendDiscoveryToolCall(string $name, array $args): void
    {
        if ($this->activeDiscoveryBatch === null) {
            $this->activeDiscoveryBatch = new DiscoveryBatchWidget;
            $this->activeDiscoveryBatch->addStyleClass('tool-batch');
            $this->addConversationWidget($this->activeDiscoveryBatch);
        }

        $this->activeDiscoveryItems[] = $this->buildDiscoveryItem($name, $args);
        $this->activeDiscoveryBatch->setItems($this->activeDiscoveryItems);
    }

    /** Updates the last discovery item with its result output and success status. */
    private function completeDiscoveryToolResult(string $name, string $output, bool $success): void
    {
        if ($this->activeDiscoveryItems === []) {
            $this->appendDiscoveryToolCall($name, $this->lastToolArgs);
        }

        $lastIndex = array_key_last($this->activeDiscoveryItems);
        if ($lastIndex === null) {
            return;
        }

        $this->activeDiscoveryItems[$lastIndex] = $this->buildDiscoveryItem(
            $name,
            $this->lastToolArgs,
            $output,
            true,
            $success,
        );

        $this->activeDiscoveryBatch?->setItems($this->activeDiscoveryItems);
    }

    /** Resets the active discovery batch state after a non-discovery tool breaks the group. */
    private function finalizeDiscoveryBatch(): void
    {
        $this->activeDiscoveryBatch = null;
        $this->activeDiscoveryItems = [];
    }

    /** Creates and adds a BashCommandWidget to the conversation. */
    private function beginBashCommand(string $command): void
    {
        $this->activeBashWidget = new BashCommandWidget($command);
        $this->activeBashWidget->addStyleClass('tool-shell');
        $this->addConversationWidget($this->activeBashWidget);
    }

    /** Attaches the result output to the active bash widget. */
    private function completeBashCommand(string $output, bool $success): void
    {
        if ($this->activeBashWidget === null) {
            $this->beginBashCommand((string) ($this->lastToolArgs['command'] ?? ''));
        }

        $this->activeBashWidget?->setResult($output, $success);
        $this->activeBashWidget = null;
    }

    /**
     * @return array{name: string, label: string, detail: string, summary: string, status: 'pending'|'success'|'error'}
     */
    private function buildDiscoveryItem(
        string $name,
        array $args,
        string $output = '',
        bool $hasResult = false,
        bool $success = true,
    ): array {
        $label = match ($name) {
            'file_read' => $this->formatDiscoveryReadLabel($args),
            'glob' => $this->formatDiscoveryGlobLabel($args),
            'grep' => $this->formatDiscoveryGrepLabel($args),
            'bash' => $this->formatDiscoveryBashLabel($args),
            'memory_search' => $this->formatDiscoveryMemoryLabel($args),
            default => Theme::toolLabel($name),
        };

        if (! $hasResult) {
            return [
                'name' => $name,
                'label' => $label,
                'detail' => '',
                'summary' => '',
                'status' => 'pending',
            ];
        }

        return [
            'name' => $name,
            'label' => $label,
            'detail' => $name === 'file_read'
                ? $this->highlightFileOutput($output, (string) ($args['path'] ?? ''))
                : $output,
            'summary' => $this->summarizeDiscoveryResult($name, $output, $success),
            'status' => $success ? 'success' : 'error',
        ];
    }

    /** Formats a file_read tool label showing the relative path and optional offset. */
    private function formatDiscoveryReadLabel(array $args): string
    {
        $path = Theme::relativePath((string) ($args['path'] ?? ''));

        if (isset($args['offset'])) {
            $path .= ':'.$args['offset'];
        }

        return $path;
    }

    /** Formats a glob tool label showing the pattern and optional path scope. */
    private function formatDiscoveryGlobLabel(array $args): string
    {
        $pattern = (string) ($args['pattern'] ?? '');
        $path = $this->normalizeDiscoveryPath((string) ($args['path'] ?? ''));

        if ($path === '.' || $path === '') {
            return $pattern;
        }

        return "{$pattern} in {$path}";
    }

    /** Formats a grep tool label showing the pattern, path scope, and optional glob filter. */
    private function formatDiscoveryGrepLabel(array $args): string
    {
        $pattern = '"'.(string) ($args['pattern'] ?? '').'"';
        $path = $this->normalizeDiscoveryPath((string) ($args['path'] ?? ''));
        $glob = (string) ($args['glob'] ?? '');

        $label = $path === '.' || $path === ''
            ? $pattern
            : "{$pattern} in {$path}";

        if ($glob !== '') {
            $label .= " ({$glob})";
        }

        return $label;
    }

    /** Formats a bash tool label, truncating long commands. */
    private function formatDiscoveryBashLabel(array $args): string
    {
        $command = trim((string) ($args['command'] ?? ''));
        if ($command === '') {
            return 'shell probe';
        }

        return mb_strlen($command) > 90 ? mb_substr($command, 0, 90).'…' : $command;
    }

    /** Formats a memory_search tool label showing the query or filter criteria. */
    private function formatDiscoveryMemoryLabel(array $args): string
    {
        $query = trim((string) ($args['query'] ?? ''));
        $type = trim((string) ($args['type'] ?? ''));
        $memoryClass = trim((string) ($args['class'] ?? ''));
        $scope = trim((string) ($args['scope'] ?? ''));

        if ($query !== '') {
            return '"'.$query.'"'.($scope !== '' ? " in {$scope}" : '');
        }

        $parts = array_values(array_filter([
            $type !== '' ? $type : null,
            $memoryClass !== '' ? $memoryClass : null,
            $scope !== '' ? $scope : null,
        ]));

        return $parts === [] ? 'saved memories' : implode(' · ', $parts);
    }

    /** Returns a relative display path, collapsing empty/current-directory to '.'. */
    private function normalizeDiscoveryPath(string $path): string
    {
        if ($path === '' || $path === '.') {
            return '.';
        }

        return Theme::relativePath($path);
    }

    /** Produces a one-line summary of a discovery tool result (e.g. "42 lines", "3 files"). */
    private function summarizeDiscoveryResult(string $name, string $output, bool $success): string
    {
        if (! $success) {
            return 'error';
        }

        return match ($name) {
            'file_read' => $this->countNonEmptyLines($output).' lines',
            'glob' => $this->summarizeCountedResult($output, 'file', 'files', 'No files matching'),
            'grep' => $this->summarizeCountedResult($output, 'match', 'matches', 'No matches found'),
            'bash' => $this->summarizeCountedResult($output, 'line', 'lines', 'No output'),
            'memory_search' => $this->summarizeMemorySearchResult($output),
            default => '',
        };
    }

    /** Extracts a recall count from memory_search output text. */
    private function summarizeMemorySearchResult(string $output): string
    {
        $trimmed = trim($output);
        if ($trimmed === '' || $trimmed === 'No memories found.' || $trimmed === 'No session history matches found.') {
            return '0 recalls';
        }

        if (preg_match('/^Found (\d+) memories:/', $trimmed, $matches) === 1) {
            $count = (int) $matches[1];

            return $count.' '.($count === 1 ? 'recall' : 'recalls');
        }

        return $this->countNonEmptyLines($output).' lines';
    }

    /** Counts non-empty lines in output and formats with singular/plural noun. */
    private function summarizeCountedResult(string $output, string $singular, string $plural, string $emptyPrefix): string
    {
        $trimmed = trim($output);
        if ($trimmed === '' || str_starts_with($trimmed, $emptyPrefix)) {
            return "0 {$plural}";
        }

        $count = $this->countNonEmptyLines($output);

        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function countNonEmptyLines(string $output): int
    {
        return count(array_filter(
            explode("\n", $output),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    /** Infers success/failure from a historic tool result string heuristically. */
    private function inferHistoricToolSuccess(string $name, mixed $toolResult): bool
    {
        if (! is_object($toolResult) || ! property_exists($toolResult, 'result')) {
            return true;
        }

        $result = $toolResult->result;
        if (! is_string($result)) {
            return true;
        }

        $trimmed = trim($result);
        if (str_starts_with($trimmed, 'Error:')) {
            return false;
        }

        if ($name === 'memory_search' && str_starts_with($trimmed, 'Invalid ')) {
            return false;
        }

        return true;
    }

    /** Checks whether a string contains ANSI escape sequences. */
    private function containsAnsiEscapes(string $text): bool
    {
        return str_contains($text, "\x1b[");
    }

    /** Adds a widget to the conversation and marks hidden activity if browsing history. */
    private function addConversationWidget(AbstractWidget $widget): void
    {
        $this->conversation->add($widget);
        $this->markHiddenConversationActivity();
    }

    /** Flags that new content was added below the visible area while browsing history. */
    private function markHiddenConversationActivity(): void
    {
        if (! $this->isBrowsingHistory()) {
            return;
        }

        $this->hasHiddenActivityBelow = true;
        $this->refreshHistoryStatus();
    }

    /** Scrolls the conversation view up by one page step. */
    private function scrollHistoryUp(): void
    {
        $this->scrollOffset += $this->historyScrollStep();
        $this->applyScrollOffset();
    }

    /** Scrolls the conversation view down by one page step, clamping at zero. */
    private function scrollHistoryDown(): void
    {
        $this->scrollOffset = max(0, $this->scrollOffset - $this->historyScrollStep());
        if ($this->scrollOffset === 0) {
            $this->hasHiddenActivityBelow = false;
        }

        $this->applyScrollOffset();
    }

    /** Resets scroll to the bottom (live output position). */
    private function jumpToLiveOutput(): void
    {
        $this->scrollOffset = 0;
        $this->hasHiddenActivityBelow = false;
        $this->applyScrollOffset();
    }

    /** Applies the current scroll offset to the TUI and refreshes the history indicator. */
    private function applyScrollOffset(): void
    {
        $this->tui->setScrollOffset($this->scrollOffset);
        $this->refreshHistoryStatus();
        $this->flushRender();
    }

    /** Shows or hides the history status indicator based on scroll state. */
    private function refreshHistoryStatus(): void
    {
        if (! $this->isBrowsingHistory()) {
            $this->historyStatus->hide();

            return;
        }

        $this->historyStatus->show($this->hasHiddenActivityBelow);
    }

    /** Returns true when the user has scrolled up from live output. */
    private function isBrowsingHistory(): bool
    {
        return $this->scrollOffset > 0;
    }

    /** Calculates the number of lines to scroll per page-up/page-down, based on terminal height. */
    private function historyScrollStep(): int
    {
        return max(6, $this->tui->getTerminal()->getRows() - 10);
    }

    /** Rebuilds the task tree display from TaskStore, embedding the thinking spinner when applicable. */
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

        // Embed thinking spinner in task bar only when there's no standalone loader
        // (the standalone loader in thinkingBar already shows the phrase)
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

    /** Returns true if the tool name is a task management tool. */
    private function isTaskTool(string $name): bool
    {
        return in_array($name, ['task_create', 'task_update', 'task_list', 'task_get'], true);
    }

    /** Queues a question/answer pair for deferred recap display in the conversation. */
    private function queueQuestionRecap(string $question, string $answer, bool $answered, bool $recommended = false): void
    {
        $this->pendingQuestionRecap[] = [
            'question' => $question,
            'answer' => $answer,
            'answered' => $answered,
            'recommended' => $answered && $recommended,
        ];
    }

    /** Renders all queued question/answer pairs as an AnsweredQuestionsWidget and clears the queue. */
    private function flushPendingQuestionRecap(): void
    {
        if ($this->pendingQuestionRecap === []) {
            return;
        }

        $this->addConversationWidget(new AnsweredQuestionsWidget($this->pendingQuestionRecap));
        $this->pendingQuestionRecap = [];
        $this->flushRender();
    }

    /**
     * @param  array<array{label: string, detail: string|null, recommended?: bool}>  $choices
     * @return array{label: string, detail: string|null, recommended?: bool}|null
     */
    private function findChoice(array $choices, string $label): ?array
    {
        foreach ($choices as $choice) {
            if ($choice['label'] === $label) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{label: string, detail: string|null, recommended?: bool}|null
     */
    private function findChoiceFromArgs(array $args, string $label): ?array
    {
        $raw = json_decode((string) ($args['choices'] ?? '[]'), true);
        if (! is_array($raw)) {
            return null;
        }

        $choices = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $choices[] = ['label' => $item, 'detail' => null, 'recommended' => false];

                continue;
            }

            if (! is_array($item) || ! isset($item['label'])) {
                continue;
            }

            $choices[] = [
                'label' => (string) $item['label'],
                'detail' => isset($item['detail']) ? (string) $item['detail'] : null,
                'recommended' => (bool) ($item['recommended'] ?? false),
            ];
        }

        return $this->findChoice($choices, $label);
    }
}

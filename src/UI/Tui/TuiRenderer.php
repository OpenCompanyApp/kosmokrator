<?php

namespace Kosmokrator\UI\Tui;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiIntro;
use Kosmokrator\UI\Ansi\AnsiPrometheus;
use Kosmokrator\UI\Ansi\AnsiTheogony;
use Kosmokrator\UI\Ansi\KosmokratorTerminalTheme;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Widget\AnsiArtWidget;
use Kosmokrator\UI\Tui\Widget\BorderFooterWidget;
use Kosmokrator\UI\Tui\Widget\CollapsibleWidget;
use Kosmokrator\UI\Tui\Widget\PlanApprovalWidget;
use Kosmokrator\UI\Tui\Widget\QuestionWidget;
use Tempest\Highlight\Highlighter;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class TuiRenderer implements RendererInterface
{
    private Tui $tui;

    private ContainerWidget $session;

    private ContainerWidget $conversation;

    private ProgressBarWidget $statusBar;

    private ContainerWidget $overlay;

    private TextWidget $taskBar;

    private ContainerWidget $thinkingBar;

    private EditorWidget $input;

    private ?CancellableLoaderWidget $loader = null;

    private ?string $pendingEditorRestore = null;

    private ?DeferredCancellation $requestCancellation = null;

    private float $thinkingStartTime = 0.0;
    private ?string $thinkingTimerId = null;
    private int $breathTick = 0;
    private ?string $breathColor = null;
    /** @var string[] */
    private array $activeSpinnerFrames = [];

    /** @var string[] */
    private array $messageQueue = [];

    private string $currentModeLabel = 'Edit';
    private string $currentModeColor = "\033[38;2;80;200;120m";

    private string $currentPermissionLabel = 'Guardian ◈';
    private string $currentPermissionColor = "\033[38;2;180;180;200m";

    private MarkdownWidget|AnsiArtWidget|null $activeResponse = null;

    private bool $activeResponseIsAnsi = false;

    private const THINKING_PHRASES = [
        '◈ Consulting the Oracle at Delphi...',
        '♃ Aligning the celestial spheres...',
        '⚡ Channeling Prometheus\' fire...',
        '♄ Weaving the threads of Fate...',
        '☽ Reading the astral charts...',
        '♂ Invoking the nine Muses...',
        '♆ Traversing the Aether...',
        '♅ Deciphering cosmic glyphs...',
        '⚡ Summoning Athena\'s wisdom...',
        '☉ Attuning to the Music of the Spheres...',
        '♃ Gazing into the cosmic void...',
        '◈ Unraveling the Labyrinth...',
        '♆ Communing with the Titans...',
        '♄ Forging in Hephaestus\' workshop...',
        '☽ Scrying the heavens...',
    ];

    private const SPINNERS = [
        'cosmos'    => ['✦', '✧', '⊛', '◈', '⊛', '✧'],                       // Pulsing cosmic gem
        'planets'   => ['☿', '♀', '♁', '♂', '♃', '♄', '♅', '♆'],            // Planetary orbit
        'elements'  => ['🜁', '🜂', '🜃', '🜄'],                               // Alchemical elements
        'stars'     => ['⋆', '✧', '★', '✦', '★', '✧'],                       // Twinkling stars
        'ouroboros'  => ['◴', '◷', '◶', '◵'],                                 // Serpent cycle
        'oracle'    => ['◉', '◎', '◉', '○', '◎', '○'],                       // All-seeing eye
        'runes'     => ['ᚠ', 'ᚢ', 'ᚦ', 'ᚨ', 'ᚱ', 'ᚲ', 'ᚷ', 'ᚹ'],         // Elder Futhark runes
        'fate'      => ['⚀', '⚁', '⚂', '⚃', '⚄', '⚅'],                     // Dice of fate
        'sigil'     => ['᛭', '⊹', '✳', '✴', '✳', '⊹'],                      // Arcane sigil pulse
        'serpent'   => ['∿', '≀', '∾', '≀'],                                  // Cosmic serpent wave
        'eclipse'   => ['◐', '◓', '◑', '◒'],                                  // Solar eclipse
        'hourglass' => ['⧗', '⧖', '⧗', '⧖'],                                 // Sands of Chronos
        'trident'   => ['ψ', 'Ψ', 'ψ', '⊥'],                                 // Poseidon's trident
        'aether'    => ['·', '∘', '○', '◌', '○', '∘'],                        // Aetheric ripple
    ];

    private bool $spinnersRegistered = false;
    private int $spinnerIndex = 0;

    private ?CancellableLoaderWidget $compactingLoader = null;
    private ?string $compactingTimerId = null;
    private float $compactingStartTime = 0.0;
    private int $compactingBreathTick = 0;

    private const COMPACTION_PHRASES = [
        '⧫ Condensing the cosmic record...',
        '⧫ Distilling the essence of memory...',
        '⧫ Weaving threads of context...',
        '⧫ Forging a compact chronicle...',
    ];

    private ?Suspension $promptSuspension = null;

    private ?Suspension $askSuspension = null;

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
        ['value' => '/theogony', 'label' => '/theogony', 'description' => 'Play the KosmoKrator origin spectacle'],
    ];

    private ?Highlighter $highlighter = null;

    private array $lastToolArgs = [];

    private ?TaskStore $taskStore = null;

    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    public function initialize(): void
    {
        $this->tui = new Tui(KosmokratorStyleSheet::create());

        // Root layout: conversation + status bar + input (vertical)
        $this->session = new ContainerWidget();
        $this->session->setId('session');
        $this->session->addStyleClass('session');
        $this->session->expandVertically(true);

        // Scrollable conversation
        $this->conversation = new ContainerWidget();
        $this->conversation->setId('conversation');
        $this->conversation->expandVertically(true);

        // Status bar at bottom — context progress bar
        $this->statusBar = new ProgressBarWidget(200_000, '%message%  %bar%');
        $this->statusBar->setId('status-bar');
        $this->statusBar->setBarCharacter('━');
        $this->statusBar->setEmptyBarCharacter('─');
        $this->statusBar->setProgressCharacter('━');
        $this->statusBar->setBarWidth(20);
        $red = "\033[38;2;255;60;40m";
        $r = "\033[0m";
        $sep = "\033[38;5;240m·{$r}";
        $this->statusBar->setMessage("{$this->currentModeColor}{$this->currentModeLabel}{$r}  {$sep}  {$this->currentPermissionColor}{$this->currentPermissionLabel}{$r}  {$sep}  Ready");
        $this->statusBar->start(200_000, 0);

        // Overlay container — pinned between status bar and input, zero-height when empty
        $this->overlay = new ContainerWidget();
        $this->overlay->setId('overlay');

        // Task bar — persistent task tree above the input, empty when no tasks
        $this->taskBar = new TextWidget('');
        $this->taskBar->setId('task-bar');

        // Thinking bar — loader sits between task bar and status bar
        $this->thinkingBar = new ContainerWidget();
        $this->thinkingBar->setId('thinking-bar');

        // Multi-line editor prompt (Enter = submit, Shift+Enter / Alt+Enter = newline)
        $this->input = new EditorWidget();
        $this->input->setId('prompt');
        $this->input->setMinVisibleLines(1);
        $this->input->setMaxVisibleLines(2);
        $this->input->setKeybindings(new Keybindings([
            'copy' => [],                                  // Free ctrl+c for cancel
            'new_line' => ['shift+enter', 'alt+enter'],    // Both work: shift+enter with kitty, alt+enter without
            'cycle_mode' => ['shift+tab'],                 // Cycle through edit → plan → ask
        ]));

        // Keyboard shortcuts on input
        $this->input->onInput(function (string $data): bool {
            $kb = $this->input->getKeybindings();

            // Slash completion navigation — intercept when menu is visible
            if ($this->slashCompletion !== null) {
                if ($kb->matches($data, 'cursor_up') || $kb->matches($data, 'cursor_down')) {
                    $this->slashCompletion->handleInput($data);
                    $this->tui->processRender();

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

            // Ctrl+O — toggle all tool results expanded/collapsed
            if ($kb->matches($data, 'expand_tools')) {
                $this->toggleAllToolResults();

                return true;
            }

            // Shift+Tab — cycle mode
            if ($kb->matches($data, 'cycle_mode')) {
                $nextMode = $this->cycleMode();

                if ($this->promptSuspension !== null) {
                    // At prompt: submit as slash command, preserve editor text
                    $savedText = $this->input->getText();
                    $suspension = $this->promptSuspension;
                    $this->promptSuspension = null;
                    $this->pendingEditorRestore = $savedText;
                    $suspension->resume("/{$nextMode}");
                } else {
                    // During run: switch UI immediately, queue command, cancel request
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

        // Ctrl+C / Escape on input — context-aware
        $this->input->onCancel(function (CancelEvent $event) {
            // During ask_user tool: return empty (dismiss)
            if ($this->askSuspension !== null) {
                $suspension = $this->askSuspension;
                $this->askSuspension = null;
                $suspension->resume('');

                return;
            }

            // During thinking: cancel the LLM request
            if ($this->requestCancellation !== null) {
                $this->requestCancellation->cancel();

                return;
            }

            // At prompt: quit
            if ($this->promptSuspension !== null) {
                $suspension = $this->promptSuspension;
                $this->promptSuspension = null;
                $suspension->resume('/quit');
            }
        });

        // Slash command completion on input change
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

        // Assemble layout: conversation → overlay → taskBar → thinkingBar → input → statusBar
        $this->session->add($this->conversation);
        $this->session->add($this->overlay);
        $this->session->add($this->taskBar);
        $this->session->add($this->thinkingBar);
        $this->session->add($this->input);
        $this->session->add($this->statusBar);

        // Submit handler on editor (Ctrl+Enter)
        $this->input->onSubmit(function (SubmitEvent $event) {
            $value = $event->getValue();
            $this->input->setText('');
            $this->hideSlashCompletion();

            // During ask_user tool: return answer to the tool
            if ($this->askSuspension !== null) {
                $suspension = $this->askSuspension;
                $this->askSuspension = null;
                $suspension->resume($value);

                return;
            }

            // During thinking: queue the message for after current response
            if ($this->requestCancellation !== null) {
                if (trim($value) !== '') {
                    $this->queueMessage($value);
                }

                return;
            }

            // At prompt: normal submit
            if ($this->promptSuspension !== null) {
                $suspension = $this->promptSuspension;
                $this->promptSuspension = null;
                $suspension->resume($value);

                return;
            }

            // Fallback: queue text submitted during transitional states
            if (trim($value) !== '') {
                $this->queueMessage($value);
            }
        });

        $this->tui->add($this->session);
        $this->tui->setFocus($this->input);

        // Start TUI — registers stdin/signal watchers with Revolt
        $this->tui->start();
    }

    public function renderIntro(bool $animated): void
    {
        // Run the full ANSI animated intro first (before TUI takes over the screen)
        $intro = new AnsiIntro();
        if ($animated) {
            $skipped = $intro->animate();
            if (!$skipped) {
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
{$muted}/settings{$dim}  {$muted}/memories{$dim}  {$muted}/sessions{$r}   {$dim}Configuration and persistence{$r}
{$border}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}
HELP;

        $header = new TextWidget("{$gold}⚡ KosmoKrator — Ruler of the Cosmos ⚡{$r}");
        $header->addStyleClass('subtitle');
        $this->conversation->add($header);

        $orreryWidget = new TextWidget($orrery);
        $orreryWidget->addStyleClass('welcome');
        $this->conversation->add($orreryWidget);

        $tutorialWidget = new TextWidget($tutorial);
        $tutorialWidget->addStyleClass('welcome');
        $this->conversation->add($tutorialWidget);

        $this->tui->processRender();
    }

    public function prompt(): string
    {
        // Restore editor text if mode was cycled via Shift+Tab
        if ($this->pendingEditorRestore !== null) {
            $this->input->setText($this->pendingEditorRestore);
            $this->pendingEditorRestore = null;
        } else {
            $this->input->setText('');
        }

        $this->tui->setFocus($this->input);
        $this->promptSuspension = EventLoop::getSuspension();

        return $this->promptSuspension->suspend();
    }

    public function showUserMessage(string $text): void
    {
        $r = Theme::reset();
        $bg = Theme::bgRgb(35, 35, 45);
        $white = Theme::white();
        $content = "⟡ {$text}";
        $cols = $this->tui->getTerminal()->getColumns();
        $visible = \Symfony\Component\Tui\Ansi\AnsiUtils::visibleWidth($content);
        $pad = max(0, $cols - $visible - 4);
        $widget = new TextWidget("{$bg}{$white}{$content}" . str_repeat(' ', $pad) . "{$r}");
        $widget->addStyleClass('user-message');
        $this->conversation->add($widget);
        $this->tui->processRender();
    }

    public function showThinking(): void
    {
        $phrase = self::THINKING_PHRASES[array_rand(self::THINKING_PHRASES)];
        $hasTasks = $this->taskStore !== null && !$this->taskStore->isEmpty();

        $this->requestCancellation = new DeferredCancellation();
        $this->thinkingStartTime = microtime(true);
        $this->breathTick = 0;

        // Only show the standalone loader when there are no tasks
        if (!$hasTasks) {
            // Register custom spinners on first use
            if (!$this->spinnersRegistered) {
                foreach (self::SPINNERS as $name => $frames) {
                    CancellableLoaderWidget::addSpinner($name, $frames);
                }
                $this->spinnersRegistered = true;
            }

            $spinnerNames = array_keys(self::SPINNERS);
            $spinnerName = $spinnerNames[$this->spinnerIndex % count($spinnerNames)];
            $this->activeSpinnerFrames = self::SPINNERS[$spinnerName];
            $this->spinnerIndex++;

            $this->loader = new CancellableLoaderWidget($phrase);
            $this->loader->setId('loader');
            $this->loader->setSpinner($spinnerName);
            $this->loader->setIntervalMs(120);
            $this->loader->start();

            $this->loader->onCancel(function () {
                if ($this->requestCancellation !== null) {
                    $this->requestCancellation->cancel();
                }
            });

            $this->thinkingBar->add($this->loader);
        }

        // Breathing pulse at 30fps — animates loader text OR in-progress task color
        $this->thinkingTimerId = EventLoop::repeat(0.033, function () use ($phrase) {
            $this->breathTick++;
            $r = "\033[0m";

            // Slow sin wave (~3s full cycle) modulating blue tones
            $t = sin($this->breathTick * 0.07);
            $br = (int) (112 + 40 * $t);
            $bg = (int) (160 + 40 * $t);
            $bb = (int) (208 + 47 * $t);
            $this->breathColor = "\033[38;2;{$br};{$bg};{$bb}m";

            if ($this->loader !== null) {
                // Animate the standalone loader text
                $elapsed = (int) (microtime(true) - $this->thinkingStartTime);
                $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $dim = "\033[38;5;245m";
                $this->loader->setMessage("{$this->breathColor}{$phrase}{$r} {$dim}({$formatted}){$r}");
            }

            // Refresh task bar — every tick when breathing (animates in-progress color),
            // or every ~1s just for timers
            if ($this->taskStore !== null && !$this->taskStore->isEmpty()) {
                $this->refreshTaskBar();
            }

            $this->tui->requestRender();
            $this->tui->processRender();
        });

        // Keep focus on input so user can type while thinking
        $this->tui->processRender();
    }

    public function clearThinking(): void
    {
        if ($this->thinkingTimerId !== null) {
            EventLoop::cancel($this->thinkingTimerId);
            $this->thinkingTimerId = null;
        }

        if ($this->loader !== null) {
            $this->loader->setFinishedIndicator('✓');
            $this->loader->stop();
            $this->thinkingBar->remove($this->loader);
            $this->loader = null;
        }

        // Reset breath color so task bar returns to static colors
        $this->breathColor = null;
        $this->refreshTaskBar();

        $this->requestCancellation = null;
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    public function showCompacting(): void
    {
        $phrase = self::COMPACTION_PHRASES[array_rand(self::COMPACTION_PHRASES)];

        // Register custom spinners if not done yet
        if (!$this->spinnersRegistered) {
            foreach (self::SPINNERS as $name => $frames) {
                CancellableLoaderWidget::addSpinner($name, $frames);
            }
            $this->spinnersRegistered = true;
        }

        $spinnerNames = array_keys(self::SPINNERS);
        $spinnerName = $spinnerNames[$this->spinnerIndex % count($spinnerNames)];
        $this->spinnerIndex++;

        $this->compactingLoader = new CancellableLoaderWidget($phrase);
        $this->compactingLoader->setId('compacting-loader');
        $this->compactingLoader->addStyleClass('compacting');
        $this->compactingLoader->setSpinner($spinnerName);
        $this->compactingLoader->setIntervalMs(120);
        $this->compactingLoader->start();

        $this->thinkingBar->add($this->compactingLoader);

        $this->compactingStartTime = microtime(true);
        $this->compactingBreathTick = 0;

        // Breathing pulse at 30fps — red color modulation
        $this->compactingTimerId = EventLoop::repeat(0.033, function () use ($phrase) {
            $this->compactingBreathTick++;
            $r = "\033[0m";

            // Slow sin wave (~3s full cycle) modulating red tones
            $t = sin($this->compactingBreathTick * 0.07);
            $rr = (int) (208 + 40 * $t);
            $rg = (int) (48 + 16 * $t);
            $rb = (int) (48 + 16 * $t);
            $color = "\033[38;2;{$rr};{$rg};{$rb}m";

            if ($this->compactingLoader !== null) {
                $elapsed = (int) (microtime(true) - $this->compactingStartTime);
                $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $dim = "\033[38;5;245m";
                $this->compactingLoader->setMessage("{$color}{$phrase}{$r} {$dim}({$formatted}){$r}");
            }

            $this->tui->requestRender();
            $this->tui->processRender();
        });

        $this->tui->processRender();
    }

    public function clearCompacting(): void
    {
        if ($this->compactingTimerId !== null) {
            EventLoop::cancel($this->compactingTimerId);
            $this->compactingTimerId = null;
        }

        if ($this->compactingLoader !== null) {
            $this->compactingLoader->setFinishedIndicator('✓');
            $this->compactingLoader->stop();
            $this->thinkingBar->remove($this->compactingLoader);
            $this->compactingLoader = null;
        }

        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    public function getCancellation(): ?Cancellation
    {
        return $this->requestCancellation?->getCancellation();
    }

    public function streamChunk(string $text): void
    {
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

            $this->conversation->add($this->activeResponse);
        } elseif (! $this->activeResponseIsAnsi && $this->containsAnsiEscapes($text)) {
            // Mid-stream ANSI detection: swap MarkdownWidget → AnsiArtWidget
            $accumulated = $this->activeResponse->getText();
            $this->conversation->remove($this->activeResponse);

            $this->activeResponse = new AnsiArtWidget($accumulated);
            $this->activeResponse->addStyleClass('ansi-art');
            $this->activeResponseIsAnsi = true;
            $this->conversation->add($this->activeResponse);
        }

        $current = $this->activeResponse->getText();
        $this->activeResponse->setText($current . $text);
        $this->tui->processRender();
    }

    public function streamComplete(): void
    {
        $this->activeResponse = null;
        $this->activeResponseIsAnsi = false;
        $this->tui->processRender();
    }

    public function showToolCall(string $name, array $args): void
    {
        $this->lastToolArgs = $args;
        $icon = Theme::toolIcon($name);
        $friendly = Theme::toolLabel($name);
        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();

        // Task tools: update task bar only, no conversation widget (task bar shows the tree)
        if ($this->isTaskTool($name)) {
            $this->refreshTaskBar();
            $this->tui->requestRender();
            $this->tui->processRender();

            return;
        }

        // Ask tools: silent — the question is shown by the tool's UI method
        if (in_array($name, ['ask_user', 'ask_choice'], true)) {
            return;
        }

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
                $display = is_string($value) ? $value : json_encode($value);
                $parts[] = "{$key}: {$display}";
            }
            $label = "{$icon} {$friendly}  " . implode('  ', $parts);
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

        $this->conversation->add($widget);
        $this->tui->processRender();
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $statusColor = $success ? Theme::success() : Theme::error();
        $indicator = $success ? '✓' : '✗';
        $r = Theme::reset();
        $text = Theme::text();

        $header = "{$statusColor}{$indicator}{$r}";

        // Task tools: silent result — the call line + sticky task bar are enough
        if ($this->isTaskTool($name)) {
            $this->refreshTaskBar();
            $this->tui->requestRender();
            $this->tui->processRender();

            return;
        }

        // Ask tools: silent result — the user already saw their own answer
        if (in_array($name, ['ask_user', 'ask_choice'], true)) {
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
        $this->conversation->add($widget);
        $this->tui->processRender();
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        $r = Theme::reset();
        $gold = Theme::accent();
        $dim = Theme::dim();
        $white = Theme::white();
        $icon = Theme::toolIcon($toolName);
        $friendly = Theme::toolLabel($toolName);

        // Build tool call summary for context
        $summary = "{$gold}{$icon} {$friendly}{$r}";
        if ($toolName === 'bash' && isset($args['command'])) {
            $cmd = mb_strlen($args['command']) > 80
                ? mb_substr($args['command'], 0, 77) . '...'
                : $args['command'];
            $summary .= " {$dim}{$cmd}{$r}";
        } elseif (isset($args['path'])) {
            $summary .= " {$dim}" . Theme::relativePath($args['path']) . "{$r}";
        }

        $header = new TextWidget("{$gold}Allow?{$r}  {$summary}");
        $header->addStyleClass('tool-call');

        $selectList = new SelectListWidget([
            ['value' => 'allow', 'label' => 'Allow', 'description' => 'Execute this tool call'],
            ['value' => 'always', 'label' => 'Always Allow', 'description' => 'Allow this tool for the session'],
            ['value' => 'guardian', 'label' => "\u{2192} Guardian \u{25C8}", 'description' => 'Switch to smart auto-approve'],
            ['value' => 'prometheus', 'label' => "\u{2192} Prometheus \u{26A1}", 'description' => 'Switch to auto-approve all'],
            ['value' => 'deny', 'label' => 'Deny', 'description' => 'Block and tell the LLM'],
        ]);
        $selectList->setId('permission-prompt');
        $selectList->addStyleClass('permission-prompt');

        $this->overlay->add($header);
        $this->overlay->add($selectList);
        $this->tui->setFocus($selectList);
        $this->tui->processRender();

        $suspension = EventLoop::getSuspension();

        $selectList->onSelect(function (SelectEvent $event) use ($suspension) {
            $suspension->resume($event->getValue());
        });

        $selectList->onCancel(function () use ($suspension) {
            $suspension->resume('deny');
        });

        $decision = $suspension->suspend();

        $this->overlay->remove($selectList);
        $this->overlay->remove($header);
        $this->tui->setFocus($this->input);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();

        return $decision;
    }

    public function showAutoApproveIndicator(string $toolName): void
    {
        // Intentionally silent — auto-approve is already visible in the status bar
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->currentPermissionLabel = $label;
        $this->currentPermissionColor = $color;

        // Refresh status bar to reflect the new permission mode
        $r = "\033[0m";
        $sep = "\033[38;5;240m·{$r}";
        $this->statusBar->setMessage("{$this->currentModeColor}{$this->currentModeLabel}{$r}  {$sep}  {$this->currentPermissionColor}{$this->currentPermissionLabel}{$r}  {$sep}  Ready");
        $this->tui->requestRender();
        $this->tui->processRender();
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        $widget = new PlanApprovalWidget($currentPermissionMode);
        $widget->setId('plan-approval');

        $this->overlay->add($widget);
        $this->tui->setFocus($widget);
        $this->tui->processRender();

        $suspension = EventLoop::getSuspension();

        $widget->onConfirm(function () use ($suspension, $widget) {
            $suspension->resume([
                'permission' => $widget->getPermissionId(),
                'context' => $widget->getContextId(),
            ]);
        });

        $widget->onDismiss(function () use ($suspension) {
            $suspension->resume(null);
        });

        $result = $suspension->suspend();

        $this->overlay->remove($widget);
        $this->tui->setFocus($this->input);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();

        return $result;
    }

    public function askUser(string $question): string
    {
        $r = Theme::reset();
        $accent = Theme::accent();

        $widget = new QuestionWidget($question);
        $this->overlay->add($widget);

        $this->tui->setFocus($this->input);
        $this->tui->processRender();

        $this->askSuspension = EventLoop::getSuspension();
        $answer = $this->askSuspension->suspend();

        // Clean up overlay and show Q&A inline in conversation
        $this->overlay->remove($widget);
        $dim = Theme::dim();
        $qWidget = new TextWidget("{$accent}?{$r} {$dim}{$question}{$r}");
        $this->conversation->add($qWidget);
        $this->showUserMessage($answer);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();

        return $answer;
    }

    public function askChoice(string $question, array $choices): string
    {
        $r = Theme::reset();
        $accent = Theme::accent();
        $dim = Theme::dim();

        $widgets = [];

        // Detail widget — shows the currently highlighted choice's detail/mockup
        $detailWidget = new TextWidget('');
        $this->overlay->add($detailWidget);
        $widgets[] = $detailWidget;

        // Index details by value for quick lookup
        $detailsByValue = [];
        foreach ($choices as $choice) {
            if ($choice['detail'] !== null) {
                $detailsByValue[$choice['label']] = $choice['detail'];
            }
        }

        // Show first choice's detail initially
        $firstDetail = $choices[0]['detail'] ?? null;
        if ($firstDetail !== null) {
            $detailWidget->setText($firstDetail);
        }

        // Bordered header (no bottom border — select list sits between)
        $header = new QuestionWidget($question, 'Choose', Theme::borderAccent(), Theme::accent(), showBottom: false);
        $this->overlay->add($header);
        $widgets[] = $header;

        // Build select list — user choices + always a Dismiss option
        $items = [];
        foreach ($choices as $choice) {
            $items[] = ['value' => $choice['label'], 'label' => $choice['label']];
        }
        $items[] = ['value' => 'dismissed', 'label' => 'Dismiss'];

        $selectList = new SelectListWidget($items);
        $selectList->setId('ask-choice');
        $this->overlay->add($selectList);
        $widgets[] = $selectList;

        // Bottom border
        $footer = new BorderFooterWidget(Theme::borderAccent());
        $this->overlay->add($footer);
        $widgets[] = $footer;

        // Update detail when selection changes
        $selectList->onSelectionChange(function (SelectionChangeEvent $event) use ($detailWidget, $detailsByValue) {
            $value = $event->getValue();
            $detail = $detailsByValue[$value] ?? null;
            $detailWidget->setText($detail ?? '');
        });

        $this->tui->setFocus($selectList);
        $this->tui->processRender();

        $suspension = EventLoop::getSuspension();

        $selectList->onSelect(function (SelectEvent $event) use ($suspension) {
            $suspension->resume($event->getValue());
        });

        $selectList->onCancel(function () use ($suspension) {
            $suspension->resume('dismissed');
        });

        $result = $suspension->suspend();

        // Clean up overlay
        foreach ($widgets as $w) {
            $this->overlay->remove($w);
        }

        // Show Q&A inline in conversation
        $qWidget = new TextWidget("{$accent}?{$r} {$dim}{$question}{$r}");
        $this->conversation->add($qWidget);
        $this->showUserMessage($result === 'dismissed' ? '(dismissed)' : $result);

        $this->tui->setFocus($this->input);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();

        return $result;
    }

    private function highlightFileOutput(string $output): string
    {
        $path = $this->lastToolArgs['path'] ?? '';
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

    private function buildDiffView(string $old, string $new, string $path): string
    {
        $r = Theme::reset();
        $removeFg = Theme::diffRemove();
        $addFg = Theme::diffAdd();
        $removeBg = Theme::diffRemoveBg();
        $addBg = Theme::diffAddBg();

        $language = KosmokratorTerminalTheme::detectLanguage($path);

        $oldHighlighted = $this->highlightBlock($old, $language);
        $newHighlighted = $this->highlightBlock($new, $language);

        $oldLines = explode("\n", $oldHighlighted);
        $newLines = explode("\n", $newHighlighted);

        $result = [];
        foreach ($oldLines as $line) {
            $result[] = "{$removeBg}{$removeFg} - {$r}{$removeBg} {$line}{$r}";
        }
        foreach ($newLines as $line) {
            $result[] = "{$addBg}{$addFg} + {$r}{$addBg} {$line}{$r}";
        }

        return implode("\n", $result);
    }

    private function highlightBlock(string $code, string $language): string
    {
        if ($language === '') {
            return $code;
        }

        try {
            return $this->getHighlighter()->parse($code, $language);
        } catch (\Throwable) {
            return $code;
        }
    }

    private function getHighlighter(): Highlighter
    {
        return $this->highlighter ??= new Highlighter(new KosmokratorTerminalTheme());
    }

    public function clearConversation(): void
    {
        $this->conversation->clear();
        $this->activeResponse = null;
        $this->activeResponseIsAnsi = false;
        $this->tui->processRender();
    }

    public function replayHistory(array $messages): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();
        $text = Theme::text();

        // Index tool results by toolCallId for pairing with tool calls
        $resultsByCallId = [];
        foreach ($messages as $msg) {
            if ($msg instanceof \Prism\Prism\ValueObjects\Messages\ToolResultMessage) {
                foreach ($msg->toolResults as $toolResult) {
                    $resultsByCallId[$toolResult->toolCallId] = $toolResult;
                }
            }
        }

        foreach ($messages as $msg) {
            if ($msg instanceof \Prism\Prism\ValueObjects\Messages\SystemMessage
                || $msg instanceof \Prism\Prism\ValueObjects\Messages\ToolResultMessage) {
                continue; // Results are rendered inline with their tool calls
            }

            if ($msg instanceof \Prism\Prism\ValueObjects\Messages\UserMessage) {
                $widget = new TextWidget('⟡ ' . $msg->content);
                $widget->addStyleClass('user-message');
                $this->conversation->add($widget);
                continue;
            }

            if ($msg instanceof \Prism\Prism\ValueObjects\Messages\AssistantMessage) {
                // Text content
                if ($msg->content !== '') {
                    if ($this->containsAnsiEscapes($msg->content)) {
                        $widget = new AnsiArtWidget($msg->content);
                        $widget->addStyleClass('ansi-art');
                    } else {
                        $widget = new MarkdownWidget($msg->content);
                        $widget->addStyleClass('response');
                    }
                    $this->conversation->add($widget);
                }

                // Tool calls — each paired with its result
                foreach ($msg->toolCalls as $toolCall) {
                    $name = $toolCall->name;
                    $args = $toolCall->arguments();

                    // Task tools: skip — task bar shows the tree
                    if ($this->isTaskTool($name)) {
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
                            $display = is_string($value) ? $value : json_encode($value);
                            $parts[] = "{$key}: {$display}";
                        }
                        $label = "{$icon} {$friendly}  " . implode('  ', $parts);
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
                    $this->conversation->add($w);

                    // Render paired result immediately after the call
                    $toolResult = $resultsByCallId[$toolCall->id] ?? null;
                    if ($toolResult !== null) {
                        $this->lastToolArgs = $toolResult->args;
                        $output = is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result);
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
                        $this->conversation->add($rw);
                    }
                }
                continue;
            }
        }

        $this->tui->requestRender();
        $this->tui->processRender();
    }

    public function showNotice(string $message): void
    {
        $widget = new TextWidget($message);
        $widget->addStyleClass('subtitle');
        $this->conversation->add($widget);
        $this->tui->processRender();
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->currentModeLabel = $label;
        if ($color !== '') {
            $this->currentModeColor = $color;
        }
        $r = "\033[0m";
        $red = "\033[38;2;255;60;40m";
        $sep = "\033[38;5;240m·{$r}";
        $this->statusBar->setMessage("{$this->currentModeColor}{$label}{$r}  {$sep}  {$this->currentPermissionColor}{$this->currentPermissionLabel}{$r}  {$sep}  Ready");
        $this->tui->processRender();
    }

    public function showError(string $message): void
    {
        $widget = new TextWidget("✗ Error: {$message}");
        $widget->addStyleClass('tool-error');
        $this->conversation->add($widget);
        $this->tui->processRender();
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
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
        $red = "\033[38;2;255;60;40m";
        $sep = "\033[38;5;240m·{$r}";
        $dimWhite = "\033[38;2;140;140;150m";
        $ctxColor = Theme::contextColor($ratio);
        $costColor = "\033[38;5;245m";
        $costLabel = Theme::formatCost($cost);

        $this->statusBar->setMessage(
            "{$this->currentModeColor}{$this->currentModeLabel}{$r}  {$sep}  {$this->currentPermissionColor}{$this->currentPermissionLabel}{$r}  {$sep}  {$dimWhite}{$model}{$r}  {$sep}  {$ctxColor}{$inLabel}/{$maxLabel}{$r}  {$sep}  {$costColor}{$costLabel}{$r}"
        );
        $this->tui->processRender();
    }

    public function showSettings(array $currentSettings): array
    {
        $items = [
            new SettingItem(
                id: 'provider',
                label: 'Provider',
                currentValue: $currentSettings['provider'] ?? '',
                description: 'LLM provider — press Enter to select',
                submenu: fn (string $current, callable $onDone) => $this->buildProviderSubmenu($current, $onDone),
            ),
            new SettingItem(
                id: 'model',
                label: 'Model',
                currentValue: $currentSettings['model'] ?? '',
                description: 'LLM model — press Enter to edit',
                submenu: fn (string $current, callable $onDone) => $this->buildInputSubmenu($current, $onDone, 'Model: '),
            ),
            new SettingItem(
                id: 'api_key',
                label: 'API Key',
                currentValue: $currentSettings['api_key'] ?? '(not set)',
                description: 'API key for current provider — press Enter to change',
                submenu: fn (string $current, callable $onDone) => $this->buildInputSubmenu('', $onDone, 'API Key: '),
            ),
            new SettingItem(
                id: 'mode',
                label: 'Mode',
                currentValue: $currentSettings['mode'] ?? 'edit',
                description: 'Agent mode — edit (full access), plan (read-only), ask (conversational)',
                values: ['edit', 'plan', 'ask'],
            ),
            new SettingItem(
                id: 'permission_mode',
                label: 'Permission Mode',
                currentValue: $currentSettings['permission_mode'] ?? 'guardian',
                description: 'Guardian (smart auto), Argus (ask all), Prometheus (approve all)',
                values: ['guardian', 'argus', 'prometheus'],
            ),
            new SettingItem(
                id: 'memories',
                label: 'Memories',
                currentValue: $currentSettings['memories'] ?? 'on',
                description: 'Long-term knowledge persistence across sessions',
                values: ['on', 'off'],
            ),
            new SettingItem(
                id: 'auto_compact',
                label: 'Auto-compact',
                currentValue: $currentSettings['auto_compact'] ?? 'on',
                description: 'Automatically compact context when approaching token limit',
                values: ['on', 'off'],
            ),
            new SettingItem(
                id: 'compact_threshold',
                label: 'Compact threshold',
                currentValue: $currentSettings['compact_threshold'] ?? '60',
                description: 'Context usage % at which compaction triggers (lower = more aggressive)',
                values: ['40', '50', '60', '70', '80'],
            ),
            new SettingItem(
                id: 'prune_protect',
                label: 'Prune protect',
                currentValue: $currentSettings['prune_protect'] ?? '40000',
                description: 'Tokens of recent tool output to protect from pruning',
                values: ['20000', '30000', '40000', '60000', '80000'],
            ),
            new SettingItem(
                id: 'prune_min_savings',
                label: 'Prune threshold',
                currentValue: $currentSettings['prune_min_savings'] ?? '20000',
                description: 'Minimum token savings required to trigger pruning',
                values: ['10000', '20000', '30000', '50000'],
            ),
            new SettingItem(
                id: 'temperature',
                label: 'Temperature',
                currentValue: $currentSettings['temperature'] ?? '0.0',
                description: 'LLM sampling temperature (0.0 = deterministic, 1.0 = creative)',
                values: ['0.0', '0.1', '0.2', '0.3', '0.5', '0.7', '1.0'],
            ),
            new SettingItem(
                id: 'max_tokens',
                label: 'Max Tokens',
                currentValue: $currentSettings['max_tokens'] ?? '',
                description: 'Maximum output tokens per LLM response (empty = provider default)',
                values: ['', '4096', '8192', '16384', '32768', '65536'],
            ),
        ];

        $settingsWidget = new SettingsListWidget($items);
        $settingsWidget->setId('settings-panel');

        $this->overlay->add($settingsWidget);
        $this->tui->setFocus($settingsWidget);
        $this->tui->processRender();

        $changes = [];
        $suspension = EventLoop::getSuspension();

        $settingsWidget->onChange(function (SettingChangeEvent $event) use (&$changes) {
            $changes[$event->getId()] = $event->getValue();
        });

        $settingsWidget->onCancel(function () use ($suspension) {
            $suspension->resume(null);
        });

        $suspension->suspend();

        $this->overlay->remove($settingsWidget);
        $this->tui->setFocus($this->input);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();

        return $changes;
    }

    public function pickSession(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        $selectList = new SelectListWidget($items, maxVisible: 8);
        $selectList->setId('session-picker');
        $selectList->addStyleClass('slash-completion');

        $this->overlay->add($selectList);
        $this->tui->setFocus($selectList);
        $this->tui->processRender();

        $suspension = EventLoop::getSuspension();

        $selectList->onSelect(function (SelectEvent $event) use ($suspension) {
            $suspension->resume($event->getValue());
        });

        $selectList->onCancel(function () use ($suspension) {
            $suspension->resume(null);
        });

        $result = $suspension->suspend();

        $this->overlay->remove($selectList);
        $this->tui->setFocus($this->input);
        $this->tui->requestRender(force: true);
        $this->tui->processRender();

        return $result;
    }

    public function showWelcome(): void
    {
        // Already handled in renderIntro
    }

    public function playTheogony(): void
    {
        // Suspend TUI — restores terminal to normal mode
        $this->tui->stop();
        echo "\033[2J\033[H";

        // Play raw ANSI animation
        $theogony = new AnsiTheogony();
        $theogony->animate();

        // Pause to admire, then restore TUI
        usleep(800000);
        echo "\033[2J\033[H";
        $this->tui->start();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    public function playPrometheus(): void
    {
        $this->tui->stop();
        echo "\033[2J\033[H";

        $prometheus = new AnsiPrometheus();
        $prometheus->animate();

        echo "\033[2J\033[H";
        $this->tui->start();
        $this->tui->requestRender(force: true);
        $this->tui->processRender();
    }

    public function consumeQueuedMessage(): ?string
    {
        if ($this->messageQueue === []) {
            return null;
        }

        return array_shift($this->messageQueue);
    }

    private function queueMessage(string $message): void
    {
        $this->messageQueue[] = $message;
        $this->showUserMessage($message);
    }

    public function teardown(): void
    {
        if ($this->tui->isRunning()) {
            $this->tui->stop();
        }
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

        $this->tui->processRender();
    }

    private function hideSlashCompletion(): void
    {
        if ($this->slashCompletion !== null) {
            $this->overlay->remove($this->slashCompletion);
            $this->slashCompletion = null;
            $this->tui->processRender();
        }
    }

    private function toggleAllToolResults(): void
    {
        foreach ($this->conversation->all() as $widget) {
            if ($widget instanceof CollapsibleWidget) {
                $widget->toggle();
            }
        }
        $this->tui->processRender();
    }

    private function containsAnsiEscapes(string $text): bool
    {
        return str_contains($text, "\x1b[");
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

        $tree = $this->taskStore->renderAnsiTree($this->breathColor);
        $lines = explode("\n", $tree);

        $bar = "  {$border}┌ {$accent}Tasks{$r}";
        foreach ($lines as $line) {
            $bar .= "\n  {$border}│{$r} {$line}";
        }
        $bar .= "\n  {$border}└{$r}";

        $this->taskBar->setText($bar);
    }

    private function buildInputSubmenu(string $currentValue, callable $onDone, string $prompt): InputWidget
    {
        $input = new InputWidget();
        $input->setValue($currentValue);
        $input->setPrompt($prompt);

        $input->onSubmit(function (SubmitEvent $e) use ($onDone) {
            $onDone($e->getValue());
        });
        $input->onCancel(function () use ($onDone) {
            $onDone(null);
        });

        return $input;
    }

    private function buildProviderSubmenu(string $current, callable $onDone): SelectListWidget
    {
        $providers = [
            ['value' => 'anthropic', 'label' => 'anthropic', 'description' => 'Anthropic (Claude)'],
            ['value' => 'openai', 'label' => 'openai', 'description' => 'OpenAI (GPT)'],
            ['value' => 'gemini', 'label' => 'gemini', 'description' => 'Google Gemini'],
            ['value' => 'deepseek', 'label' => 'deepseek', 'description' => 'DeepSeek'],
            ['value' => 'groq', 'label' => 'groq', 'description' => 'Groq'],
            ['value' => 'mistral', 'label' => 'mistral', 'description' => 'Mistral AI'],
            ['value' => 'xai', 'label' => 'xai', 'description' => 'xAI (Grok)'],
            ['value' => 'openrouter', 'label' => 'openrouter', 'description' => 'OpenRouter (multi-provider)'],
            ['value' => 'perplexity', 'label' => 'perplexity', 'description' => 'Perplexity'],
            ['value' => 'ollama', 'label' => 'ollama', 'description' => 'Ollama (local, no key needed)'],
            ['value' => 'z', 'label' => 'z', 'description' => 'Z.AI coding plan'],
            ['value' => 'z-api', 'label' => 'z-api', 'description' => 'Z.AI standard API'],
        ];

        return new SelectListWidget($providers);
    }

    private function isTaskTool(string $name): bool
    {
        return in_array($name, ['task_create', 'task_update', 'task_list', 'task_get'], true);
    }

}

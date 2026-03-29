<?php

namespace Kosmokrator\UI\Tui;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Kosmokrator\UI\Ansi\AnsiIntro;
use Kosmokrator\UI\Ansi\AnsiTheogony;
use Kosmokrator\UI\Ansi\KosmokratorTerminalTheme;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Widget\AnsiArtWidget;
use Kosmokrator\UI\Tui\Widget\CollapsibleWidget;
use Tempest\Highlight\Highlighter;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\EditorWidget;
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

    private EditorWidget $input;

    private ?CancellableLoaderWidget $loader = null;

    private ?DeferredCancellation $requestCancellation = null;

    private float $thinkingStartTime = 0.0;
    private ?string $thinkingTimerId = null;

    /** @var string[] */
    private array $messageQueue = [];

    private string $currentModeLabel = 'Edit';
    private string $currentModeColor = "\033[38;2;80;200;120m";

    private MarkdownWidget|AnsiArtWidget|null $activeResponse = null;

    private bool $activeResponseIsAnsi = false;

    private const THINKING_PHRASES = [
        '☿ Consulting the Oracle at Delphi...',
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
        '☿ Unraveling the Labyrinth...',
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

    private ?Suspension $promptSuspension = null;

    private ?SelectListWidget $slashCompletion = null;

    private const SLASH_COMMANDS = [
        ['value' => '/edit', 'label' => '/edit', 'description' => 'Switch to edit mode (full tool access)'],
        ['value' => '/plan', 'label' => '/plan', 'description' => 'Switch to plan mode (read-only)'],
        ['value' => '/ask', 'label' => '/ask', 'description' => 'Switch to ask mode (read-only, conversational)'],
        ['value' => '/prometheus', 'label' => '/prometheus', 'description' => 'Auto-approve all tools until next prompt'],
        ['value' => '/reset', 'label' => '/reset', 'description' => 'Clear conversation history'],
        ['value' => '/clear', 'label' => '/clear', 'description' => 'Clear the screen'],
        ['value' => '/quit', 'label' => '/quit', 'description' => 'Exit KosmoKrator'],
        ['value' => '/seed', 'label' => '/seed', 'description' => 'Show a mock demo session'],
        ['value' => '/settings', 'label' => '/settings', 'description' => 'Open the settings panel'],
        ['value' => '/theogony', 'label' => '/theogony', 'description' => 'Play the KosmoKrator origin spectacle'],
    ];

    private ?Highlighter $highlighter = null;

    private array $lastToolArgs = [];

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
        $this->statusBar->setMessage("{$this->currentModeColor}{$this->currentModeLabel}{$r}  {$sep}  {$red}KosmoKrator{$r}  {$sep}  Ready");
        $this->statusBar->start(200_000, 0);

        // Overlay container — pinned between status bar and input, zero-height when empty
        $this->overlay = new ContainerWidget();
        $this->overlay->setId('overlay');

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

            // Shift+Tab — cycle mode (submit as slash command)
            if ($kb->matches($data, 'cycle_mode') && $this->promptSuspension !== null) {
                $nextMode = $this->cycleMode();
                $this->input->setText('');

                $suspension = $this->promptSuspension;
                $this->promptSuspension = null;
                $suspension->resume("/{$nextMode}");

                return true;
            }

            return false;
        });

        // Ctrl+C / Escape on input — context-aware
        $this->input->onCancel(function (CancelEvent $event) {
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

        // Assemble layout
        $this->session->add($this->conversation);
        $this->session->add($this->statusBar);
        $this->session->add($this->overlay);
        $this->session->add($this->input);

        // Submit handler on editor (Ctrl+Enter)
        $this->input->onSubmit(function (SubmitEvent $event) {
            $value = $event->getValue();
            $this->input->setText('');
            $this->hideSlashCompletion();

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
            $intro->animate();
            // Pause to admire, then clear for TUI
            usleep(800000);
            echo "\033[2J\033[H";
        } else {
            $intro->renderStatic();
            sleep(1);
            echo "\033[2J\033[H";
        }

        // Now add a compact header inside the TUI conversation
        $header = new TextWidget('⚡ KosmoKrator — Ruler of the Cosmos ⚡');
        $header->addStyleClass('subtitle');
        $this->conversation->add($header);

        $welcome = new TextWidget('Type a message to begin. Press Ctrl+C to exit.');
        $welcome->addStyleClass('welcome');
        $this->conversation->add($welcome);

        $this->tui->processRender();
    }

    public function prompt(): string
    {
        $this->input->setText('');
        $this->tui->setFocus($this->input);
        $this->promptSuspension = EventLoop::getSuspension();

        return $this->promptSuspension->suspend();
    }

    public function showUserMessage(string $text): void
    {
        $widget = new TextWidget('⟡ ' . $text);
        $widget->addStyleClass('user-message');
        $this->conversation->add($widget);
        $this->tui->processRender();
    }

    public function showThinking(): void
    {
        $phrase = self::THINKING_PHRASES[array_rand(self::THINKING_PHRASES)];

        // Register custom spinners on first use
        if (!$this->spinnersRegistered) {
            foreach (self::SPINNERS as $name => $frames) {
                CancellableLoaderWidget::addSpinner($name, $frames);
            }
            $this->spinnersRegistered = true;
        }

        $this->requestCancellation = new DeferredCancellation();
        $this->thinkingStartTime = microtime(true);

        $this->loader = new CancellableLoaderWidget($phrase);
        $this->loader->setId('loader');
        $spinnerNames = array_keys(self::SPINNERS);
        $this->loader->setSpinner($spinnerNames[$this->spinnerIndex % count($spinnerNames)]);
        $this->spinnerIndex++;
        $this->loader->start();

        $this->loader->onCancel(function () {
            if ($this->requestCancellation !== null) {
                $this->requestCancellation->cancel();
            }
        });

        // Elapsed timer — update every second
        $this->thinkingTimerId = EventLoop::repeat(1.0, function () use ($phrase) {
            $elapsed = (int) (microtime(true) - $this->thinkingStartTime);
            $formatted = sprintf('%02d:%02d', intdiv($elapsed, 60), $elapsed % 60);
            $dim = "\033[38;5;245m";
            $r = "\033[0m";
            $this->loader?->setMessage("{$phrase} {$dim}({$formatted}){$r}");
            $this->tui->processRender();
        });

        $this->conversation->add($this->loader);
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
            $this->conversation->remove($this->loader);
            $this->loader = null;
        }

        $this->requestCancellation = null;
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

        // Compact single-line display for file tools
        if (in_array($name, ['file_read', 'file_write', 'file_edit']) && isset($args['path'])) {
            $label = "{$icon} {$friendly}  {$args['path']}";
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

        $widget = new TextWidget($label);
        $widget->addStyleClass('tool-call');
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
        $selectList = new SelectListWidget([
            ['value' => 'allow', 'label' => 'Allow', 'description' => 'Execute this tool call'],
            ['value' => 'deny', 'label' => 'Deny', 'description' => 'Block and tell the LLM'],
            ['value' => 'always', 'label' => 'Always Allow', 'description' => 'Allow this tool for the session'],
        ]);
        $selectList->setId('permission-prompt');
        $selectList->addStyleClass('permission-prompt');

        $this->conversation->add($selectList);
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

        $this->conversation->remove($selectList);
        $this->tui->setFocus($this->input);
        $this->tui->processRender();

        return $decision;
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
        $this->statusBar->setMessage("{$this->currentModeColor}{$label}{$r}  {$sep}  {$red}KosmoKrator{$r}  {$sep}  Ready");
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
        // Separator after complete turn
        $separator = new TextWidget(str_repeat('─', 80));
        $separator->addStyleClass('separator');
        $this->conversation->add($separator);

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

        $this->statusBar->setMessage(
            "{$this->currentModeColor}{$this->currentModeLabel}{$r}  {$sep}  {$red}KosmoKrator{$r}  {$sep}  {$dimWhite}{$model}{$r}  {$sep}  {$ctxColor}{$inLabel}/{$maxLabel}{$r}  {$sep}  {$costColor}\${$cost}{$r}"
        );
        $this->tui->processRender();
    }

    public function showSettings(array $currentSettings): array
    {
        $items = [
            new SettingItem(
                id: 'mode',
                label: 'Mode',
                currentValue: $currentSettings['mode'] ?? 'edit',
                description: 'Agent mode — edit (full access), plan (read-only), ask (conversational)',
                values: ['edit', 'plan', 'ask'],
            ),
            new SettingItem(
                id: 'auto_approve',
                label: 'Auto-approve',
                currentValue: $currentSettings['auto_approve'] ?? 'off',
                description: 'Automatically approve all tool executions (Prometheus mode)',
                values: ['off', 'on'],
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
                currentValue: $currentSettings['max_tokens'] ?? '8192',
                description: 'Maximum output tokens per LLM response',
                values: ['2048', '4096', '8192', '16384', '32768'],
            ),
            new SettingItem(
                id: 'provider',
                label: 'Provider',
                currentValue: $currentSettings['provider'] ?? '',
                description: 'LLM provider (change in config)',
            ),
            new SettingItem(
                id: 'model',
                label: 'Model',
                currentValue: $currentSettings['model'] ?? '',
                description: 'LLM model (change in config)',
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
        $this->tui->processRender();

        return $changes;
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
}

<?php

namespace Kosmokrator\UI\Tui;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Kosmokrator\UI\Ansi\AnsiIntro;
use Kosmokrator\UI\Ansi\KosmokratorTerminalTheme;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Widget\AnsiArtWidget;
use Tempest\Highlight\Highlighter;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class TuiRenderer implements RendererInterface
{
    private Tui $tui;

    private ContainerWidget $session;

    private ContainerWidget $conversation;

    private ProgressBarWidget $statusBar;

    private EditorWidget $input;

    private ?CancellableLoaderWidget $loader = null;

    private ?DeferredCancellation $requestCancellation = null;

    /** @var string[] */
    private array $messageQueue = [];

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

    private ?Suspension $promptSuspension = null;

    private ?SelectListWidget $slashCompletion = null;

    private const SLASH_COMMANDS = [
        ['value' => '/quit', 'label' => '/quit', 'description' => 'Exit KosmoKrator'],
        ['value' => '/reset', 'label' => '/reset', 'description' => 'Clear conversation history'],
        ['value' => '/clear', 'label' => '/clear', 'description' => 'Clear the screen'],
        ['value' => '/prometheus', 'label' => '/prometheus', 'description' => 'Auto-approve all tools until next prompt'],
        ['value' => '/seed', 'label' => '/seed', 'description' => 'Show a mock demo session'],
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
        $this->statusBar = new ProgressBarWidget(200_000, '%message%  %bar%  %percent%%');
        $this->statusBar->setId('status-bar');
        $this->statusBar->setBarCharacter('━');
        $this->statusBar->setEmptyBarCharacter('─');
        $this->statusBar->setProgressCharacter('━');
        $this->statusBar->setBarWidth(20);
        $this->statusBar->setMessage('KosmoKrator · Ready');
        $this->statusBar->start(200_000, 0);

        // Multi-line editor prompt (Enter = submit, Shift+Enter / Alt+Enter = newline)
        $this->input = new EditorWidget();
        $this->input->setId('prompt');
        $this->input->setMinVisibleLines(1);
        $this->input->setMaxVisibleLines(2);
        $this->input->setKeybindings(new Keybindings([
            'copy' => [],                                  // Free ctrl+c for cancel
            'new_line' => ['shift+enter', 'alt+enter'],    // Both work: shift+enter with kitty, alt+enter without
        ]));

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

        $this->requestCancellation = new DeferredCancellation();

        $this->loader = new CancellableLoaderWidget($phrase);
        $this->loader->setId('loader');
        $this->loader->setSpinner('dots');
        $this->loader->start();

        $this->loader->onCancel(function () {
            if ($this->requestCancellation !== null) {
                $this->requestCancellation->cancel();
            }
        });

        $this->conversation->add($this->loader);
        // Keep focus on input so user can type while thinking
        $this->tui->processRender();
    }

    public function clearThinking(): void
    {
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

        // Add separator after response
        $separator = new TextWidget('─────────────────────────────────────────');
        $separator->addStyleClass('separator');
        $this->conversation->add($separator);

        $this->tui->processRender();
    }

    public function showToolCall(string $name, array $args): void
    {
        $this->lastToolArgs = $args;
        $icon = Theme::toolIcon($name);

        // Compact single-line display for file tools
        if ($name === 'file_read' && isset($args['path'])) {
            $label = "{$icon} {$name}  {$args['path']}";
            if (isset($args['offset'])) {
                $label .= ":{$args['offset']}";
            }
        } else {
            $parts = [];
            foreach ($args as $key => $value) {
                $display = is_string($value) ? $value : json_encode($value);
                $parts[] = "{$key}: {$display}";
            }
            $label = "{$icon} {$name}  " . implode('  ', $parts);
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
        $dim = Theme::dim();

        // File read: just show status badge, no content
        if ($name === 'file_read') {
            $lineCount = count(explode("\n", $output));
            $ansi = "{$statusColor}{$indicator}{$r} {$dim}{$name}  ({$lineCount} lines){$r}";
            $widget = new AnsiArtWidget($ansi);
            $widget->addStyleClass('tool-result');
            $this->conversation->add($widget);
        } else {
            $lines = explode("\n", $output);
            $maxLines = 10;
            $preview = array_slice($lines, 0, $maxLines);
            $suffix = count($lines) > $maxLines
                ? "\n{$dim}... +" . (count($lines) - $maxLines) . " more lines{$r}"
                : '';

            $header = "{$statusColor}{$indicator}{$r} {$dim}{$name}{$r}";
            $body = implode("\n", array_map(fn (string $l) => "{$dim}{$l}{$r}", $preview));
            $widget = new AnsiArtWidget("{$header}\n{$body}{$suffix}");
            $widget->addStyleClass('tool-result');
            $this->conversation->add($widget);
        }

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
        $this->statusBar->setMessage("{$model}  ·  {$inLabel}/{$maxLabel}  ·  \${$cost}");
        $this->tui->processRender();
    }

    public function showWelcome(): void
    {
        // Already handled in renderIntro
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
            $this->conversation->add($this->slashCompletion);
        } else {
            $this->slashCompletion->setItems($filtered);
        }

        $this->tui->processRender();
    }

    private function hideSlashCompletion(): void
    {
        if ($this->slashCompletion !== null) {
            $this->conversation->remove($this->slashCompletion);
            $this->slashCompletion = null;
            $this->tui->processRender();
        }
    }

    private function containsAnsiEscapes(string $text): bool
    {
        return str_contains($text, "\x1b[");
    }
}

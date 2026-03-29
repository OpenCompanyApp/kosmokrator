<?php

namespace Kosmokrator\UI\Tui;

use Kosmokrator\UI\RendererInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class TuiRenderer implements RendererInterface
{
    private Tui $tui;

    private ContainerWidget $session;

    private ContainerWidget $conversation;

    private TextWidget $statusBar;

    private InputWidget $input;

    private CancellableLoaderWidget $loader;

    private ?MarkdownWidget $activeResponse = null;

    private ?Suspension $promptSuspension = null;

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

        // Status bar at bottom
        $this->statusBar = new TextWidget('KosmoKrator · Ready');
        $this->statusBar->setId('status-bar');
        $this->statusBar->addStyleClass('status-bar');

        // Cancellable thinking loader
        $this->loader = new CancellableLoaderWidget('⚡ Thinking...');
        $this->loader->setId('loader');
        $this->loader->setSpinner('dots');
        $this->loader->stop(); // Don't animate until needed

        // Input prompt
        $this->input = new InputWidget();
        $this->input->setId('prompt');
        $this->input->setPrompt('⟡ ');

        // Ctrl+C / Escape on input → quit
        $this->input->onCancel(function (CancelEvent $event) {
            if ($this->promptSuspension !== null) {
                $suspension = $this->promptSuspension;
                $this->promptSuspension = null;
                $suspension->resume('/quit');
            }
        });

        // Assemble layout
        $this->session->add($this->conversation);
        $this->session->add($this->statusBar);
        $this->session->add($this->input);

        $this->tui->add($this->session);
        $this->tui->setFocus($this->input);

        // Submit handler — resume the suspended prompt()
        $this->tui->on(SubmitEvent::class, function (SubmitEvent $event) {
            if ($event->getTarget() === $this->input && $this->promptSuspension !== null) {
                $value = $event->getValue();
                $this->input->setValue('');

                $suspension = $this->promptSuspension;
                $this->promptSuspension = null;
                $suspension->resume($value);
            }
        });

        // Start TUI — registers stdin/signal watchers with Revolt
        $this->tui->start();
    }

    public function renderIntro(bool $animated): void
    {
        // FIGlet banner
        $header = new TextWidget('KOSMOKRATOR');
        $header->setId('header');
        $header->addStyleClass('figlet-header');
        $this->conversation->add($header);

        // Mythology subtitle
        $subtitle = new TextWidget('⚡ Κοσμοκράτωρ — Ruler of the Cosmos ⚡');
        $subtitle->addStyleClass('subtitle');
        $this->conversation->add($subtitle);

        // Planetary symbols
        $planets = new TextWidget('☿  ♀  ♁  ♂  ♃  ♄  ♅  ♆  ✦  ☽  ☉  ★  ✧  ⊛  ◈');
        $planets->addStyleClass('tagline');
        $this->conversation->add($planets);

        // Tagline
        $tagline = new TextWidget('Your AI coding agent by OpenCompany');
        $tagline->addStyleClass('tagline');
        $this->conversation->add($tagline);

        // Welcome message
        $welcome = new TextWidget('Type a message to begin. Press Ctrl+C to exit.');
        $welcome->addStyleClass('welcome');
        $this->conversation->add($welcome);

        $this->tui->processRender();
    }

    public function prompt(): string
    {
        $this->tui->setFocus($this->input);
        $this->promptSuspension = EventLoop::getSuspension();

        return $this->promptSuspension->suspend();
    }

    public function showThinking(): void
    {
        $this->loader->reset();
        $this->loader->setMessage('⚡ Thinking...');
        $this->loader->start();
        $this->conversation->add($this->loader);
        $this->tui->setFocus($this->loader); // Focus loader so Ctrl+C cancels
        $this->tui->processRender();
    }

    public function streamChunk(string $text): void
    {
        if ($this->activeResponse === null) {
            // Remove loader
            $this->loader->setFinishedIndicator('✓');
            $this->loader->stop();
            $this->conversation->remove($this->loader);

            // Start markdown response widget
            $this->activeResponse = new MarkdownWidget('');
            $this->activeResponse->addStyleClass('response');
            $this->conversation->add($this->activeResponse);
        }

        $current = $this->activeResponse->getText();
        $this->activeResponse->setText($current . $text);
        $this->tui->processRender();
    }

    public function streamComplete(): void
    {
        $this->activeResponse = null;

        // Add separator after response
        $separator = new TextWidget('─────────────────────────────────────────');
        $separator->addStyleClass('separator');
        $this->conversation->add($separator);

        $this->tui->processRender();
    }

    public function showToolCall(string $name, array $args): void
    {
        $argsLines = '';
        foreach ($args as $key => $value) {
            $display = is_string($value) ? $value : json_encode($value);
            $argsLines .= "\n  {$key}: {$display}";
        }

        $widget = new TextWidget("◈ {$name}{$argsLines}");
        $widget->addStyleClass('tool-call');
        $this->conversation->add($widget);
        $this->tui->processRender();
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $indicator = $success ? '✓' : '✗';
        $lines = explode("\n", $output);
        $preview = implode("\n", array_slice($lines, 0, 10));
        if (count($lines) > 10) {
            $preview .= "\n... +" . (count($lines) - 10) . ' more lines';
        }

        $widget = new TextWidget("{$indicator} {$name}\n{$preview}");
        $widget->addStyleClass($success ? 'tool-success' : 'tool-error');
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

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost): void
    {
        $this->statusBar->setText(
            "{$model}  ·  {$tokensIn} in / {$tokensOut} out  ·  \${$cost}"
        );
        $this->tui->processRender();
    }

    public function showWelcome(): void
    {
        // Already handled in renderIntro
    }

    public function teardown(): void
    {
        if ($this->tui->isRunning()) {
            $this->tui->stop();
        }
    }
}

<?php

namespace Kosmokrator\UI\Tui;

use Kosmokrator\UI\RendererInterface;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class TuiRenderer implements RendererInterface
{
    private Tui $tui;

    private ContainerWidget $session;

    private ContainerWidget $conversation;

    private TextWidget $statusBar;

    private InputWidget $input;

    private LoaderWidget $loader;

    private ?MarkdownWidget $activeResponse = null;

    private ?string $pendingInput = null;

    private bool $waitingForInput = false;

    public function initialize(): void
    {
        $this->tui = new Tui(KosmokratorStyleSheet::create());

        // Session root container
        $this->session = new ContainerWidget();
        $this->session->setId('session');
        $this->session->addStyleClass('session');
        $this->session->expandVertically(true);

        // Scrollable conversation area
        $this->conversation = new ContainerWidget();
        $this->conversation->setId('conversation');
        $this->conversation->expandVertically(true);

        // Status bar
        $this->statusBar = new TextWidget('');
        $this->statusBar->setId('status-bar');
        $this->statusBar->addStyleClass('status-bar');

        // Loader for thinking state
        $this->loader = new LoaderWidget('Thinking...');
        $this->loader->setId('loader');
        $this->loader->setSpinner('dots');

        // Input prompt
        $this->input = new InputWidget();
        $this->input->setId('prompt');
        $this->input->setPrompt('⟡ ');

        // Handle submit
        $this->input->onSubmit(function (SubmitEvent $event) {
            $this->pendingInput = $event->getValue();
            $this->input->setValue('');
        });

        // Assemble layout
        $this->session->add($this->conversation);
        $this->session->add($this->statusBar);
        $this->session->add($this->input);

        $this->tui->add($this->session);
        $this->tui->setFocus($this->input);
    }

    public function renderIntro(bool $animated): void
    {
        $header = new TextWidget('K O S M O K R A T O R');
        $header->setId('header');
        $header->addStyleClass('header');
        $this->conversation->add($header);

        $subtitle = new TextWidget('⚡ Κοσμοκράτωρ — Ruler of the Cosmos ⚡');
        $subtitle->addStyleClass('subtitle');
        $this->conversation->add($subtitle);

        $tagline = new TextWidget('Your AI coding agent by OpenCompany');
        $tagline->addStyleClass('status-bar');
        $this->conversation->add($tagline);

        $this->tui->requestRender(true);
    }

    public function prompt(): string
    {
        $this->waitingForInput = true;
        $this->pendingInput = null;

        $this->tui->start();

        // Poll the event loop until we get input
        while ($this->pendingInput === null) {
            $this->tui->tick();
            usleep(10000); // 100Hz
        }

        $this->waitingForInput = false;
        $result = $this->pendingInput;
        $this->pendingInput = null;

        return $result;
    }

    public function showThinking(): void
    {
        $this->loader->setMessage('Thinking...');
        $this->loader->start();
        $this->conversation->add($this->loader);
        $this->tui->requestRender();
    }

    public function streamChunk(string $text): void
    {
        if ($this->activeResponse === null) {
            // Remove loader, start response widget
            $this->conversation->remove($this->loader);
            $this->loader->stop();

            $this->activeResponse = new MarkdownWidget('');
            $this->activeResponse->addStyleClass('response');
            $this->conversation->add($this->activeResponse);
        }

        $current = $this->activeResponse->getText();
        $this->activeResponse->setText($current . $text);
        $this->tui->requestRender();
        $this->tui->processRender();
    }

    public function streamComplete(): void
    {
        $this->activeResponse = null;
        $this->tui->requestRender();
    }

    public function showToolCall(string $name, array $args): void
    {
        $argsDisplay = '';
        foreach ($args as $key => $value) {
            $display = is_string($value) ? $value : json_encode($value);
            $argsDisplay .= "  {$key}: {$display}\n";
        }

        $widget = new TextWidget("◈ {$name}\n{$argsDisplay}");
        $widget->addStyleClass('tool-call');
        $this->conversation->add($widget);
        $this->tui->requestRender();
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
        $this->tui->requestRender();
        $this->tui->processRender();
    }

    public function showError(string $message): void
    {
        $widget = new TextWidget("✗ Error: {$message}");
        $widget->addStyleClass('tool-error');
        $this->conversation->add($widget);
        $this->tui->requestRender();
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost): void
    {
        $this->statusBar->setText(
            "{$model}  ·  {$tokensIn} in / {$tokensOut} out  ·  \${$cost}"
        );
        $this->tui->requestRender();
    }

    public function teardown(): void
    {
        if ($this->tui->isRunning()) {
            $this->tui->stop();
        }
    }
}

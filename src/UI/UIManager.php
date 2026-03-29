<?php

namespace Kosmokrator\UI;

use Kosmokrator\UI\Ansi\AnsiRenderer;

class UIManager implements RendererInterface
{
    private RendererInterface $renderer;

    public function __construct(string $preference = 'auto')
    {
        $this->renderer = $this->resolveRenderer($preference);
    }

    public function getActiveRenderer(): string
    {
        return match (true) {
            $this->renderer instanceof AnsiRenderer => 'ansi',
            default => get_class($this->renderer),
        };
    }

    public function initialize(): void { $this->renderer->initialize(); }
    public function renderIntro(bool $animated): void { $this->renderer->renderIntro($animated); }
    public function prompt(): string { return $this->renderer->prompt(); }
    public function showThinking(): void { $this->renderer->showThinking(); }
    public function streamChunk(string $text): void { $this->renderer->streamChunk($text); }
    public function streamComplete(): void { $this->renderer->streamComplete(); }
    public function showToolCall(string $name, array $args): void { $this->renderer->showToolCall($name, $args); }
    public function showToolResult(string $name, string $output, bool $success): void { $this->renderer->showToolResult($name, $output, $success); }
    public function showError(string $message): void { $this->renderer->showError($message); }
    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost): void { $this->renderer->showStatus($model, $tokensIn, $tokensOut, $cost); }
    public function teardown(): void { $this->renderer->teardown(); }

    public function showWelcome(): void
    {
        if ($this->renderer instanceof AnsiRenderer) {
            $this->renderer->showWelcome();
        }
    }

    public function seedMockSession(): void
    {
        if ($this->renderer instanceof AnsiRenderer) {
            $this->renderer->seedMockSession();
        }
    }

    private function resolveRenderer(string $preference): RendererInterface
    {
        // TUI renderer will be added here when implemented
        // For now, always use ANSI
        if ($preference === 'tui' && class_exists(\Symfony\Component\Tui\Tui::class)) {
            // TODO: return new TuiRenderer() when implemented
        }

        return new AnsiRenderer();
    }
}

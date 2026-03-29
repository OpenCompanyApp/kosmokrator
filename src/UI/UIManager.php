<?php

namespace Kosmokrator\UI;

use Amp\Cancellation;
use Kosmokrator\UI\Ansi\AnsiRenderer;
use Kosmokrator\UI\Tui\TuiRenderer;

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
            $this->renderer instanceof TuiRenderer => 'tui',
            $this->renderer instanceof AnsiRenderer => 'ansi',
            default => get_class($this->renderer),
        };
    }

    public function initialize(): void { $this->renderer->initialize(); }
    public function renderIntro(bool $animated): void { $this->renderer->renderIntro($animated); }
    public function prompt(): string { return $this->renderer->prompt(); }
    public function showUserMessage(string $text): void { $this->renderer->showUserMessage($text); }
    public function showThinking(): void { $this->renderer->showThinking(); }
    public function clearThinking(): void { $this->renderer->clearThinking(); }
    public function getCancellation(): ?Cancellation { return $this->renderer->getCancellation(); }
    public function streamChunk(string $text): void { $this->renderer->streamChunk($text); }
    public function streamComplete(): void { $this->renderer->streamComplete(); }
    public function showToolCall(string $name, array $args): void { $this->renderer->showToolCall($name, $args); }
    public function showToolResult(string $name, string $output, bool $success): void { $this->renderer->showToolResult($name, $output, $success); }
    public function askToolPermission(string $toolName, array $args): string { return $this->renderer->askToolPermission($toolName, $args); }
    public function showNotice(string $message): void { $this->renderer->showNotice($message); }
    public function consumeQueuedMessage(): ?string { return $this->renderer->consumeQueuedMessage(); }
    public function showError(string $message): void { $this->renderer->showError($message); }
    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost): void { $this->renderer->showStatus($model, $tokensIn, $tokensOut, $cost); }
    public function teardown(): void { $this->renderer->teardown(); }

    public function showWelcome(): void
    {
        if ($this->renderer instanceof AnsiRenderer) {
            $this->renderer->showWelcome();
        } elseif ($this->renderer instanceof TuiRenderer) {
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
        if ($preference === 'ansi') {
            return new AnsiRenderer();
        }

        // Default: use TUI if available
        if (class_exists(\Symfony\Component\Tui\Tui::class)) {
            return new TuiRenderer();
        }

        return new AnsiRenderer();
    }
}

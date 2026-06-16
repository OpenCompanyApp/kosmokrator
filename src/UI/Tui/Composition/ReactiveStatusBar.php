<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Composition;

use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\ProgressBarWidget;

/**
 * Reactive wrapper around Symfony's ProgressBarWidget.
 *
 * Syncs the progress bar message and position from TuiStateStore signals
 * on every frame via beforeRender() → syncFromSignals(). Replaces the
 * status bar Effect that previously called StatusBar::sync().
 */
final class ReactiveStatusBar extends ReactiveWidget
{
    private string $lastMessage = '';

    private int $lastTokensIn = -1;

    private ?int $lastMaxContext = null;

    public function __construct(
        private readonly ProgressBarWidget $bar,
        private readonly TuiStateStore $state,
    ) {
        $this->bar->setId('status-bar');
    }

    public static function create(TuiStateStore $state): self
    {
        $bar = StatusBar::createProgressBar($state);

        return new self($bar, $state);
    }

    public function getBar(): ProgressBarWidget
    {
        return $this->bar;
    }

    public function syncFromSignals(): bool
    {
        $newMessage = $this->state->getStatusBarMessage();
        $tokensIn = $this->state->getTokensIn() ?? 0;
        $maxContext = $this->state->getMaxContext();

        $changed = false;

        if ($newMessage !== $this->lastMessage) {
            $this->bar->setMessage($newMessage);
            $this->lastMessage = $newMessage;
            $changed = true;
        }

        if ($tokensIn !== $this->lastTokensIn || $maxContext !== $this->lastMaxContext) {
            if ($maxContext !== null && $maxContext > 0) {
                if ($this->bar->getMaxSteps() !== $maxContext) {
                    $this->bar->start($maxContext, $tokensIn);
                } else {
                    $this->bar->setProgress($tokensIn);
                }
            }
            $this->lastTokensIn = $tokensIn;
            $this->lastMaxContext = $maxContext;
            $changed = true;
        }

        return $changed;
    }

    public function render(RenderContext $context): array
    {
        $message = $this->messageForColumns($this->lastMessage, $context->getColumns());
        if ($this->bar->getMessage() !== $message) {
            $this->bar->setMessage($message);
        }

        return $this->bar->render($context);
    }

    private function messageForColumns(string $message, int $columns): string
    {
        $message = AnsiUtils::stripAnsiCodes($message);
        $maxMessageWidth = max(0, $columns - 3);

        if (AnsiUtils::visibleWidth($message) <= $maxMessageWidth) {
            return $message;
        }

        return $this->truncatePlainMessage($message, $maxMessageWidth);
    }

    private function truncatePlainMessage(string $message, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        return mb_strimwidth($message, 0, $maxWidth, '...', 'UTF-8');
    }
}

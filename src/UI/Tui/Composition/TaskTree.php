<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Composition;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Primitive\ReactiveWidget;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Reactive task tree widget.
 *
 * Replaces TaskBarBuilder. Self-contained ReactiveWidget that reads
 * breathColor and thinkingPhrase signals every frame and renders the
 * task tree with animation colors.
 */
final class TaskTree extends ReactiveWidget
{
    private ?TaskStore $taskStore;

    private readonly TuiStateStore $state;

    private string $lastText = '';

    public function __construct(?TaskStore $taskStore, TuiStateStore $state)
    {
        $this->taskStore = $taskStore;
        $this->state = $state;
        $this->setId('task-tree');
    }

    public static function of(?TaskStore $taskStore, TuiStateStore $state): self
    {
        return new self($taskStore, $state);
    }

    public function setTaskStore(?TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    protected function syncFromSignals(): bool
    {
        if ($this->taskStore === null || $this->taskStore->isEmpty()) {
            $this->state->setHasTasks(false);
            if ($this->lastText === '') {
                return false;
            }
            $this->lastText = '';

            return true;
        }

        $this->state->setHasTasks(true);

        $r = Theme::reset();
        $dim = Theme::dim();
        $border = Theme::borderTask();
        $accent = Theme::accent();

        $breathColor = $this->state->getBreathColor();
        $tree = $this->taskStore->renderAnsiTree($breathColor);
        $lines = explode("\n", $tree);

        $bar = "  {$border}┌ {$accent}Tasks{$r}";
        foreach ($lines as $line) {
            $bar .= "\n  {$border}│{$r} {$line}";
        }

        $thinkingPhrase = $this->state->getThinkingPhrase();
        if ($thinkingPhrase !== null && ! $this->taskStore->hasInProgress() && ! $this->state->getHasThinkingLoader()) {
            $color = $breathColor ?? Theme::rgb(112, 160, 208);
            $bar .= "\n  {$border}│{$r}";
            $bar .= "\n  {$border}│{$r} {$color}{$thinkingPhrase}{$r}";

            if (! $this->state->getHasRunningAgents()) {
                $elapsed = (int) (microtime(true) - $this->state->getThinkingStartTime());
                $formatted = sprintf('%d:%02d', intdiv($elapsed, 60), $elapsed % 60);
                $bar .= "{$dim} · {$formatted}{$r}";
            }
        }

        $bar .= "\n  {$border}└{$r}";

        if ($bar === $this->lastText) {
            return false;
        }

        $this->lastText = $bar;

        return true;
    }

    public function render(RenderContext $context): array
    {
        if ($this->lastText === '') {
            return [];
        }

        return explode("\n", $this->lastText);
    }
}

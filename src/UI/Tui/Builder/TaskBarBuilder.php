<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Builder;

use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Builds the task bar text from TaskStore + reactive animation signals.
 *
 * Called by the breathTick-driven Effect at ~30fps during thinking/tools
 * phases. Reads breathColor, thinkingPhrase, hasThinkingLoader, and
 * hasRunningAgents signals to produce animated task bar output.
 */
final class TaskBarBuilder
{
    private ?TaskStore $taskStore = null;

    public function __construct(
        private readonly TuiStateStore $state,
        private readonly TextWidget $widget,
    ) {}

    public static function create(TuiStateStore $state): self
    {
        $widget = new TextWidget('');
        $widget->setId('task-bar');

        return new self($state, $widget);
    }

    public function getWidget(): TextWidget
    {
        return $this->widget;
    }

    public function setTaskStore(?TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    /**
     * Rebuild the task bar text from current state.
     *
     * Called by the breathTick-driven Effect — no manual render needed.
     */
    public function update(): void
    {
        if ($this->taskStore === null || $this->taskStore->isEmpty()) {
            $this->widget->setText('');
            $this->state->setHasTasks(false);

            return;
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

        $this->widget->setText($bar);
    }
}

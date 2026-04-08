<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Kosmokrator\UI\AgentDisplayFormatter;
use Kosmokrator\UI\AgentTreeBuilder;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\Widget\CollapsibleWidget;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Manages the full subagent display lifecycle in TUI mode.
 *
 * Owns all widget and timer state for subagent display. The breathing
 * animation timer in TuiRenderer is NOT cancelled when subagents start —
 * it delegates tree refresh here via tickTreeRefresh(). This manager owns
 * its own 1-second elapsed timer that ONLY updates the loader label.
 *
 * Uses a nested ContainerWidget to keep all subagent widgets at a fixed
 * position in the conversation — preventing them from being pushed to the
 * bottom when other widgets are appended.
 *
 * Lifecycle: showSpawn() → showRunning() → showBatch()
 * Each method is idempotent and cleans up prior state.
 */
final class SubagentDisplayManager
{
    /** Wrapper container added once to conversation; all subagent widgets live inside it. */
    private ?ContainerWidget $container = null;

    private ?CancellableLoaderWidget $loader = null;

    private ?TextWidget $treeWidget = null;

    private ?string $elapsedTimerId = null;

    private ?\Closure $treeProvider = null;

    /**
     * @param  TuiStateStore  $state  Centralized reactive state store
     * @param  ContainerWidget  $conversation  The conversation container to add/remove widgets
     * @param  \Closure(): ?string  $breathColorProvider  Returns current breath animation color
     * @param  \Closure(): void  $renderCallback  Triggers a TUI render pass (flushRender)
     * @param  \Closure(): void  $ensureSpinners  Ensures custom spinners are registered
     * @param  ?LoggerInterface  $log  Logger for recording display failures
     */
    public function __construct(
        private readonly TuiStateStore $state,
        private readonly ContainerWidget $conversation,
        private readonly \Closure $breathColorProvider,
        private readonly \Closure $renderCallback,
        private readonly \Closure $ensureSpinners,
        private readonly ?LoggerInterface $log = null,
        private readonly AgentDisplayFormatter $formatter = new AgentDisplayFormatter,
        private readonly AgentTreeBuilder $treeBuilder = new AgentTreeBuilder,
    ) {}

    /**
     * Set the callback that returns the live agent tree array.
     *
     * @param  \Closure(): array  $provider  Returns tree data from AgentLoop::buildLiveAgentTree()
     */
    public function setTreeProvider(?\Closure $provider): void
    {
        $this->treeProvider = $provider;
    }

    public function hasRunningAgents(): bool
    {
        return $this->state->getHasRunningAgents();
    }

    /**
     * Ensure the wrapper container exists in the conversation.
     *
     * Creates a ContainerWidget and adds it to the conversation once.
     * All subagent widgets are added inside this container so they stay
     * at the position where they were first inserted.
     */
    private function ensureContainer(): ?ContainerWidget
    {
        if ($this->container === null) {
            $this->container = new ContainerWidget;
            $this->container->setId('subagent-container');
            try {
                $this->conversation->add($this->container);
            } catch (\Throwable $e) {
                $this->log?->warning('Failed to add subagent container', ['error' => $e->getMessage()]);
                $this->container = null;

                return null;
            }
        }

        return $this->container;
    }

    /**
     * Show spawn indicators before agents start executing.
     *
     * Creates a tree widget showing which agents were spawned. This widget
     * is updated in-place by refreshTree() as agents progress.
     *
     * @param  array<int, array{args: array, id: string}>  $entries
     */
    public function showSpawn(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        // Reuse existing container if agents are already running — avoids duplicate trees
        if ($this->container === null || $this->state->getBatchDisplayed()) {
            $this->container = new ContainerWidget;
            $this->container->setId('subagent-container');
            try {
                $this->conversation->add($this->container);
            } catch (\Throwable $e) {
                $this->log?->warning('Failed to add subagent container', ['error' => $e->getMessage()]);
                $this->container = null;

                return;
            }
            $this->treeWidget = null;
        }
        $this->state->setBatchDisplayed(false);

        $container = $this->container;

        $text = $this->renderLiveTree($this->treeBuilder->buildSpawnTree($entries));

        if ($this->treeWidget !== null) {
            $this->treeWidget->setText($text);
        } else {
            $this->treeWidget = new TextWidget($text);
            $this->treeWidget->setId('subagent-tree');
            $container->add($this->treeWidget);
        }
        ($this->renderCallback)();
    }

    /**
     * Show running indicator with elapsed timer.
     *
     * Starts a 1-second timer that updates the loader label with elapsed time,
     * agent done count, and color escalation. Does NOT cancel the breathing
     * animation — tree refresh is handled by tickTreeRefresh() called from there.
     *
     * @param  array<int, array{args: array, id: string}>  $entries
     */
    public function showRunning(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $this->stopLoader();
        $this->state->setBatchDisplayed(false);

        ($this->ensureSpinners)();

        $r = Theme::reset();
        $dim = Theme::dim();
        $blue = Theme::rgb(112, 160, 208);

        $count = count($entries);
        $label = $this->formatRunningSummary($count, 0);

        $container = $this->ensureContainer();
        if ($container === null) {
            return;
        }

        $this->loader = new CancellableLoaderWidget("{$blue}{$label}{$r}");
        $this->loader->addStyleClass('subagent-loader');
        $this->loader->setSpinner('cosmos');
        $this->loader->setIntervalMs(50);
        $this->state->setStartTime(microtime(true));
        $this->state->setLoaderBreathTick(0);
        $this->state->setCachedLoaderLabel($label);
        $this->state->setHasRunningAgents(true);

        $container->add($this->loader);

        if ($this->treeProvider !== null) {
            try {
                $tree = ($this->treeProvider)();
                if ($tree !== []) {
                    $this->refreshTree($tree);
                }
            } catch (\Throwable $e) {
                $this->log?->warning('Tree provider error during subagent start', ['error' => $e->getMessage()]);
            }
        }

        // Breathing timer — blue color modulation at ~30fps, label update every ~1s
        $this->elapsedTimerId = EventLoop::repeat(0.033, function () use ($dim, $r): void {
            if ($this->loader === null) {
                return;
            }
            $this->state->tickLoaderBreath();
            $loaderBreathTick = $this->state->getLoaderBreathTick();

            // Blue breathing color (same sine wave as thinking indicator)
            $t = sin($loaderBreathTick * 0.07);
            $cr = (int) (112 + 40 * $t);
            $cg = (int) (160 + 40 * $t);
            $cb = (int) (208 + 47 * $t);
            $color = Theme::rgb($cr, $cg, $cb);

            // Escalate color for long-running agents
            $elapsed = (int) (microtime(true) - $this->state->getStartTime());
            if ($elapsed >= 120) {
                $color = Theme::error();
            } elseif ($elapsed >= 60) {
                $color = Theme::warning();
            }

            // Update label from tree data every ~1s (every 30th tick at 33ms)
            if ($loaderBreathTick % 30 === 0 && $this->treeProvider !== null) {
                try {
                    $tree = ($this->treeProvider)();
                    if ($tree !== []) {
                        $total = $this->formatter->countNodes($tree);
                        $done = $this->formatter->countByStatus($tree, 'done');
                        if ($done > 0) {
                            $this->state->setCachedLoaderLabel($this->formatRunningSummary($total, $done));
                        } else {
                            $this->state->setCachedLoaderLabel($this->formatRunningSummary($total, 0));
                        }
                    }
                } catch (\Throwable $e) {
                    $this->log?->warning('Tree provider error in loader timer', ['error' => $e->getMessage()]);
                }
            }

            $time = sprintf('%d:%02d', (int) ($elapsed / 60), $elapsed % 60);
            $hint = "{$dim}ctrl+a for dashboard{$r}";
            $meta = "{$dim} · {$time} · {$r}{$hint}";
            $this->loader->setMessage("{$color}{$this->state->getCachedLoaderLabel()}{$r}{$meta}");
            ($this->renderCallback)();
        });

        ($this->renderCallback)();
    }

    private function formatRunningSummary(int $total, int $done): string
    {
        $noun = $total === 1 ? 'agent' : 'agents';
        $label = "{$total} {$noun} active";

        if ($done > 0) {
            $label .= " · {$done} done";
        }

        return $label;
    }

    /**
     * Show completed batch results. Stops the elapsed timer and cleans up loader/tree.
     *
     * @param  array<int, array{args: array, result: string, success: bool, children?: array, stats?: mixed}>  $entries
     */
    public function showBatch(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        // Filter out background acks — show remaining (failures, awaited results)
        $entries = array_values(array_filter($entries, fn ($e) => ($e['args']['mode'] ?? 'await') !== 'background' && ! str_contains($e['result'] ?? '', 'spawned in background')));
        if (empty($entries)) {
            // All background — keep loader and tree running
            return;
        }

        // Actual results to display — clean up running indicators
        $this->stopLoader();
        $this->removeTree();
        $this->state->setBatchDisplayed(true);

        $r = Theme::reset();
        $dim = Theme::dim();
        $green = Theme::success();
        $red = Theme::error();

        $container = $this->ensureContainer();
        if ($container === null) {
            return;
        }
        $count = count($entries);

        // Single agent: compact result with optional child tree
        if ($count === 1) {
            $e = $entries[0];
            $icon = $e['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            $label = $e['success'] ? 'Done' : 'Failed';
            $stats = $this->formatter->formatAgentStats($e);
            $children = $e['children'] ?? [];

            if ($children !== []) {
                $tree = $this->formatter->renderChildTree($children, '   ');
                $treeWidget = new TextWidget(rtrim($tree));
                $treeWidget->addStyleClass('tool-result');
                $container->add($treeWidget);
            }

            $widget = new CollapsibleWidget("{$icon} {$label}{$stats}", $e['result'], 1, 120);
            $widget->addStyleClass('tool-result');
            $container->add($widget);
            ($this->renderCallback)();

            return;
        }

        // Multiple: summary as TextWidget with child trees, full details as CollapsibleWidget
        $succeeded = count(array_filter($entries, fn ($e) => $e['success']));
        $types = $this->formatter->summarizeAgentTypes($entries);

        $lines = ["{$green}✓{$r} {$succeeded}/{$count} {$types} finished"];
        foreach ($entries as $entry) {
            $args = $entry['args'];
            $id = isset($args['id']) && $args['id'] !== '' ? (string) $args['id'] : null;
            $type = ucfirst((string) ($args['type'] ?? 'explore'));
            $primary = $id !== null ? $id : $type;
            $icon = $entry['success'] ? "{$green}✓{$r}" : "{$red}✗{$r}";
            $stats = $this->formatter->formatAgentStats($entry);

            $preview = $this->formatter->extractResultPreview($entry['result']);
            $previewSuffix = $preview !== '' ? " {$dim}· {$preview}{$r}" : '';
            $lines[] = "  {$icon} {$primary}{$stats}{$previewSuffix}";

            $children = $entry['children'] ?? [];
            if ($children !== []) {
                $lines[] = rtrim($this->formatter->renderChildTree($children, '     '));
            }
        }

        $summary = new TextWidget(implode("\n", $lines));
        $summary->addStyleClass('tool-result');
        $container->add($summary);

        $details = implode("\n---\n", array_map(fn ($e) => $e['result'], $entries));
        $expand = new CollapsibleWidget("{$dim}Full output{$r}", $details, 1, 120);
        $expand->addStyleClass('tool-result');
        $container->add($expand);
        ($this->renderCallback)();
    }

    /**
     * Update the live tree widget from current orchestrator state.
     *
     * @param  array<int, array{id: string, type: string, task: string, status: string, elapsed: float, children?: array}>  $tree
     */
    public function refreshTree(array $tree): void
    {
        if ($tree === []) {
            if ($this->treeWidget !== null && $this->container !== null) {
                $this->container->remove($this->treeWidget);
                $this->treeWidget = null;
            }

            return;
        }

        $text = $this->renderLiveTree($tree);

        if ($this->treeWidget === null) {
            $container = $this->ensureContainer();
            if ($container === null) {
                return;
            }
            $this->treeWidget = new TextWidget($text);
            $this->treeWidget->setId('subagent-tree');
            $container->add($this->treeWidget);
        } else {
            $this->treeWidget->setText($text);
        }
    }

    /**
     * Called by the breathing animation timer (~every 0.5s) to refresh the tree.
     *
     * Keeps tree refresh as a breathing timer responsibility, so it can never
     * be "lost" when timer ownership changes.
     */
    public function tickTreeRefresh(): void
    {
        if ($this->treeProvider === null || $this->state->getBatchDisplayed()) {
            return;
        }

        try {
            $tree = ($this->treeProvider)();
            if ($tree !== []) {
                $this->refreshTree($tree);
            }
        } catch (\Throwable $e) {
            $this->log?->warning('Tree provider error in refresh tick', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clean up all subagent display state.
     *
     * Called on phase transition to Idle. Cancels timers and removes widgets.
     * Does NOT remove the container — batch results should persist in conversation.
     */
    public function cleanup(): void
    {
        $this->stopLoader();
        $this->removeTree();
        // Don't null container — batch results persist in conversation.
        // showSpawn() creates a fresh container for the next batch.
    }

    /**
     * Render the live agent tree with status icons, elapsed times, and a summary header.
     */
    private function renderLiveTree(array $nodes): string
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $green = Theme::success();
        $red = Theme::error();
        $amber = ($this->breathColorProvider)() ?? Theme::rgb(200, 150, 60);
        $gray = Theme::dim();
        $cyan = Theme::agentDefault();

        $total = $this->formatter->countNodes($nodes);
        $running = $this->formatter->countByStatus($nodes, 'running');
        $waiting = $this->formatter->countByStatus($nodes, 'waiting');
        $done = $this->formatter->countByStatus($nodes, 'done');
        $parts = [];
        if ($running > 0) {
            $parts[] = "{$running} running";
        }
        if ($waiting > 0) {
            $parts[] = "{$waiting} waiting";
        }
        if ($done > 0) {
            $parts[] = "{$done} done";
        }
        $summary = implode(', ', $parts);
        $header = "{$cyan}⏺{$r} {$total} agents ({$summary})";

        return $header."\n".rtrim($this->renderTreeNodes($nodes, '  ', $r, $dim, $green, $red, $amber, $gray));
    }

    /**
     * Render tree nodes recursively with box-drawing connectors.
     */
    private function renderTreeNodes(array $nodes, string $indent, string $r, string $dim, string $green, string $red, string $amber, string $gray): string
    {
        $output = '';
        $last = count($nodes) - 1;

        foreach ($nodes as $i => $node) {
            $connector = $i === $last ? '└─' : '├─';
            $continuation = $i === $last ? '   ' : '│  ';

            $icon = match ($node['status']) {
                'done' => "{$green}✓{$r}",
                'failed', 'cancelled' => "{$red}✗{$r}",
                'running' => "{$amber}●{$r}",
                'waiting', 'queued' => "{$gray}◌{$r}",
                'retrying' => "{$amber}⟳{$r}",
                default => "{$gray}·{$r}",
            };

            $type = ucfirst($node['type']);
            $id = $node['id'];
            $task = mb_strlen($node['task']) > 50 ? mb_substr($node['task'], 0, 50).'…' : $node['task'];
            $elapsed = $node['elapsed'] > 0 ? $this->formatter->formatElapsed($node['elapsed']) : '';
            $tools = $node['toolCalls'] ?? 0;

            if ($node['status'] === 'done') {
                // Completed: green stats, dimmed task
                $stats = $elapsed !== '' ? " {$green}· {$elapsed} · {$tools} tools{$r}" : '';
                $taskSnippet = $task !== '' ? " {$gray}· {$task}{$r}" : '';
                $output .= "{$indent}{$connector} {$icon} {$dim}{$type}{$r} {$id}{$stats}{$taskSnippet}\n";
            } elseif ($node['status'] === 'failed' || $node['status'] === 'cancelled') {
                // Failed: red stats, error hint
                $stats = $elapsed !== '' ? " {$red}· {$elapsed}{$r}" : '';
                $taskSnippet = $task !== '' ? " {$gray}· {$task}{$r}" : '';
                $output .= "{$indent}{$connector} {$icon} {$dim}{$type}{$r} {$id}{$stats}{$taskSnippet}\n";
            } else {
                // Running/waiting: amber with task visible
                $elapsedStr = $elapsed !== '' ? " {$dim}({$elapsed}){$r}" : '';
                $taskSnippet = $task !== '' ? " {$dim}· {$task}{$r}" : '';
                $output .= "{$indent}{$connector} {$icon} {$dim}{$type}{$r} {$id}{$taskSnippet}{$elapsedStr}\n";
            }

            $children = $node['children'] ?? [];
            if ($children !== []) {
                $output .= $this->renderTreeNodes($children, "{$indent}{$continuation}", $r, $dim, $green, $red, $amber, $gray);
            }
        }

        return $output;
    }

    /**
     * Stop the elapsed timer and remove the loader widget.
     */
    private function stopLoader(): void
    {
        if ($this->elapsedTimerId !== null) {
            EventLoop::cancel($this->elapsedTimerId);
            $this->elapsedTimerId = null;
        }
        if ($this->loader !== null && $this->container !== null) {
            $this->loader->setFinishedIndicator('✓');
            $this->loader->stop();
            $this->container->remove($this->loader);
            $this->loader = null;
            $this->state->setHasRunningAgents(false);
        }
    }

    /**
     * Remove the tree widget from the container.
     */
    private function removeTree(): void
    {
        if ($this->treeWidget !== null && $this->container !== null) {
            $this->container->remove($this->treeWidget);
            $this->treeWidget = null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\Widget\BorderFooterWidget;
use Kosmokrator\UI\Tui\Widget\PermissionPromptWidget;
use Kosmokrator\UI\Tui\Widget\PlanApprovalWidget;
use Kosmokrator\UI\Tui\Widget\QuestionWidget;
use Kosmokrator\UI\Tui\Widget\SettingsWorkspaceWidget;
use Kosmokrator\UI\Tui\Widget\SwarmDashboardWidget;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Manages all modal/dialog overlays that block via Revolt Suspension.
 *
 * Extracted from TuiRenderer to isolate overlay + suspension patterns
 * (permission prompts, settings panels, session pickers, dashboards).
 */
final class TuiModalManager
{
    private ?Suspension $askSuspension = null;

    public function __construct(
        private readonly TuiStateStore $state,
        private readonly ContainerWidget $overlay,
        private readonly AbstractWidget $sessionRoot,
        private readonly Tui $tui,
        private readonly EditorWidget $input,
        private readonly \Closure $renderCallback,
        private readonly \Closure $forceRenderCallback,
        private readonly LoggerInterface $log = new NullLogger,
    ) {}

    /**
     * Show a tool permission prompt and block until the user decides.
     *
     * @param  string  $toolName  Tool identifier (bash, file_write, etc.)
     * @param  array<string, mixed>  $args  Tool call arguments for context
     * @return string 'allow', 'deny', 'always', 'guardian', or 'prometheus'
     */
    public function askToolPermission(string $toolName, array $args): string
    {
        if ($this->state->getActiveModal()) {
            return 'deny';
        }

        $this->state->setActiveModal(true);
        $preview = (new PermissionPreviewBuilder)->build($toolName, $args);
        $widget = new PermissionPromptWidget($toolName, $preview);
        $widget->setId('permission-prompt');

        $this->overlay->add($widget);
        $this->tui->setFocus($widget);
        $this->flushRender();

        $suspension = EventLoop::getSuspension();

        $widget->onConfirm(function (string $decision) use ($suspension) {
            $suspension->resume($decision);
        });

        $widget->onDismiss(function () use ($suspension) {
            $suspension->resume('deny');
        });

        try {
            $decision = $suspension->suspend();
        } finally {
            $this->state->setActiveModal(false);
        }

        $this->overlay->remove($widget);
        $this->tui->setFocus($this->input);
        $this->forceRender();

        return $decision;
    }

    /**
     * Show the plan approval dialog after a plan-mode run completes.
     *
     * @return array{permission: string, context: string}|null Settings on accept, null on dismiss
     */
    public function approvePlan(string $currentPermissionMode): ?array
    {
        if ($this->state->getActiveModal()) {
            return null;
        }

        $this->state->setActiveModal(true);
        $widget = new PlanApprovalWidget($currentPermissionMode);
        $widget->setId('plan-approval');

        $this->overlay->add($widget);
        $this->tui->setFocus($widget);
        $this->flushRender();

        $suspension = EventLoop::getSuspension();

        $widget->onConfirm(function () use ($suspension, $widget) {
            $suspension->resume([
                'permission' => $widget->getPermissionId(),
                'context' => $widget->getContextId(),
            ]);
        });

        $widget->onDismiss(function () use ($suspension) {
            $suspension->resume(null);
        });

        try {
            $result = $suspension->suspend();
        } finally {
            $this->state->setActiveModal(false);
        }

        $this->overlay->remove($widget);
        $this->tui->setFocus($this->input);
        $this->forceRender();

        return $result;
    }

    /**
     * Ask the user a free-text question mid-run. Blocks until they respond.
     *
     * The answer is provided by the editor widget's onSubmit/onCancel handlers
     * in TuiRenderer, which check getAskSuspension() and resume it.
     *
     * @param  string  $question  The question to display
     * @return string The user's answer (empty string if cancelled)
     */
    public function askUser(string $question): string
    {
        if ($this->state->getActiveModal()) {
            return '';
        }

        $this->state->setActiveModal(true);
        $r = Theme::reset();
        $accent = Theme::accent();

        $widget = new QuestionWidget($question);
        $this->overlay->add($widget);

        $this->tui->setFocus($this->input);
        $this->flushRender();

        try {
            $this->askSuspension = EventLoop::getSuspension();
            $answer = $this->askSuspension->suspend();

            // Clean up overlay and show Q&A inline in conversation
            $this->overlay->remove($widget);
            $this->forceRender();

            return $answer;
        } finally {
            $this->askSuspension = null;
            $this->state->setActiveModal(false);
        }
    }

    /**
     * Present multiple-choice options to the user.
     *
     * Each choice can have a detail block (ASCII art / mockup) shown when
     * that option is highlighted. A "Dismiss" option is always appended.
     *
     * @param  string  $question  The question to display
     * @param  array<array{label: string, detail: string|null, recommended?: bool}>  $choices
     * @return string Selected label or 'dismissed'
     */
    public function askChoice(string $question, array $choices): string
    {
        if ($this->state->getActiveModal()) {
            return 'dismissed';
        }

        $this->state->setActiveModal(true);
        $r = Theme::reset();

        $widgets = [];

        // Detail widget -- shows the currently highlighted choice's detail/mockup
        $detailWidget = new TextWidget('');
        $this->overlay->add($detailWidget);
        $widgets[] = $detailWidget;

        // Index details by value for quick lookup
        $detailsByValue = [];
        foreach ($choices as $choice) {
            if ($choice['detail'] !== null) {
                $detailsByValue[$choice['label']] = $choice['detail'];
            }
        }

        // Show first choice's detail initially
        $firstDetail = $choices[0]['detail'] ?? null;
        if ($firstDetail !== null) {
            $detailWidget->setText($firstDetail);
        }

        // Bordered header (no bottom border -- select list sits between)
        $header = new QuestionWidget($question, 'Choose', Theme::borderAccent(), Theme::accent(), showBottom: false);
        $this->overlay->add($header);
        $widgets[] = $header;

        // Build select list -- user choices + always a Dismiss option
        $items = [];
        foreach ($choices as $choice) {
            $items[] = ['value' => $choice['label'], 'label' => $choice['label']];
        }
        $items[] = ['value' => 'dismissed', 'label' => 'Dismiss'];

        $selectList = new SelectListWidget($items);
        $selectList->setId('ask-choice');
        $this->overlay->add($selectList);
        $widgets[] = $selectList;

        // Bottom border
        $footer = new BorderFooterWidget(Theme::borderAccent());
        $this->overlay->add($footer);
        $widgets[] = $footer;

        // Update detail when selection changes
        $selectList->onSelectionChange(function (SelectionChangeEvent $event) use ($detailWidget, $detailsByValue) {
            $value = $event->getValue();
            $detail = $detailsByValue[$value] ?? null;
            $detailWidget->setText($detail ?? '');
        });

        $this->tui->setFocus($selectList);
        $this->flushRender();

        $suspension = EventLoop::getSuspension();

        $selectList->onSelect(function (SelectEvent $event) use ($suspension) {
            $suspension->resume($event->getValue());
        });

        $selectList->onCancel(function () use ($suspension) {
            $suspension->resume('dismissed');
        });

        try {
            $result = $suspension->suspend();
        } finally {
            $this->state->setActiveModal(false);
        }

        // Clean up overlay
        foreach ($widgets as $w) {
            $this->overlay->remove($w);
        }

        $this->tui->setFocus($this->input);
        $this->forceRender();

        return $result;
    }

    /**
     * Show the settings panel and block until the user closes it.
     *
     * @param  array<string, mixed>  $currentSettings
     * @return array<string, string> Changed settings (id => new value)
     */
    public function showSettings(array $currentSettings): array
    {
        if ($this->state->getActiveModal()) {
            return [];
        }

        $this->state->setActiveModal(true);
        $widget = new SettingsWorkspaceWidget($currentSettings);
        $widget->setId('settings-workspace');
        $this->tui->remove($this->sessionRoot);
        $this->tui->add($widget);
        $this->tui->setFocus($widget);
        $this->forceRender();

        $result = [];
        $suspension = EventLoop::getSuspension();
        $widget->onSave(function (array $payload) use (&$result, $suspension) {
            $result = $payload;
            $suspension->resume(true);
        });
        $widget->onCancel(function () use ($suspension) {
            $suspension->resume(false);
        });

        try {
            $suspension->suspend();
        } finally {
            $this->state->setActiveModal(false);
        }

        $this->tui->remove($widget);
        $this->tui->add($this->sessionRoot);
        $this->forceRender();
        $this->tui->setFocus($this->input);
        $this->flushRender();

        return $result;
    }

    /**
     * Show an interactive session picker. Returns selected session ID or null.
     *
     * @param  array<array{value: string, label: string, description?: string}>  $items
     */
    public function pickSession(array $items): ?string
    {
        if ($this->state->getActiveModal()) {
            return null;
        }

        $this->state->setActiveModal(true);
        if ($items === []) {
            $this->state->setActiveModal(false);

            return null;
        }

        $selectList = new SelectListWidget($items, maxVisible: 12);
        $selectList->setId('session-picker');
        $selectList->addStyleClass('slash-completion');

        $this->overlay->add($selectList);
        $this->tui->setFocus($selectList);
        $this->flushRender();

        $suspension = EventLoop::getSuspension();

        $selectList->onSelect(function (SelectEvent $event) use ($suspension) {
            $suspension->resume($event->getValue());
        });

        $selectList->onCancel(function () use ($suspension) {
            $suspension->resume(null);
        });

        try {
            $result = $suspension->suspend();
        } finally {
            $this->state->setActiveModal(false);
        }

        $this->overlay->remove($selectList);
        $this->tui->setFocus($this->input);
        $this->forceRender();

        return $result;
    }

    /**
     * Show the swarm progress dashboard with optional auto-refresh.
     *
     * @param  array  $summary  Aggregated stats (counts, tokens, cost, ETA, etc.)
     * @param  array<string, SubagentStats>  $allStats  All agent stats
     * @param  \Closure|null  $refresh  Callback returning ['summary' => ..., 'stats' => ...] for auto-refresh
     */
    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        if ($this->state->getActiveModal()) {
            return;
        }

        $this->state->setActiveModal(true);
        $widget = new SwarmDashboardWidget($summary, $allStats);
        $widget->setId('agents-dashboard');

        $this->overlay->add($widget);
        $this->tui->setFocus($widget);
        $this->flushRender();

        $suspension = EventLoop::getSuspension();

        $widget->onDismiss(fn () => $suspension->resume(null));

        // Auto-refresh every 2s if refresh callback provided
        $timerId = null;
        if ($refresh !== null) {
            $timerId = EventLoop::repeat(2.0, function () use ($widget, $refresh) {
                try {
                    $data = $refresh();
                    $widget->setData($data['summary'], $data['stats']);
                    $this->forceRender();
                } catch (\Throwable $e) {
                    $this->log->warning('Dashboard refresh error', ['error' => $e->getMessage()]);
                }
            });
        }

        try {
            $suspension->suspend();
        } finally {
            $this->state->setActiveModal(false);
        }

        if ($timerId !== null) {
            EventLoop::cancel($timerId);
        }

        $this->overlay->remove($widget);
        $this->tui->setFocus($this->input);
        $this->forceRender();
    }

    /**
     * Get the active ask suspension (for editor input handler integration).
     *
     * When askUser() is blocking, the editor's onSubmit/onCancel handlers
     * must resume this suspension with the user's answer.
     */
    public function getAskSuspension(): ?Suspension
    {
        return $this->askSuspension;
    }

    /**
     * Clear the ask suspension reference after it has been resumed.
     */
    public function clearAskSuspension(): void
    {
        $this->askSuspension = null;
    }

    /**
     * Build an inline text input submenu for the settings panel.
     */
    private function buildInputSubmenu(string $currentValue, callable $onDone, string $prompt): InputWidget
    {
        $input = new InputWidget;
        $input->setValue($currentValue);
        $input->setPrompt($prompt);

        $input->onSubmit(function (SubmitEvent $e) use ($onDone) {
            $onDone($e->getValue());
        });
        $input->onCancel(function () use ($onDone) {
            $onDone(null);
        });

        return $input;
    }

    /**
     * Build a provider selection submenu for the settings panel.
     */
    private function buildProviderSubmenu(array $providers): SelectListWidget
    {
        return new SelectListWidget($providers);
    }

    /**
     * @param  array<string, list<array{value: string, label: string, description: string}>>  $modelsByProvider
     */
    private function buildModelSubmenu(string $provider, string $current, callable $onDone, array $modelsByProvider): SelectListWidget|InputWidget
    {
        $models = $modelsByProvider[$provider] ?? [];

        if ($models === []) {
            return $this->buildInputSubmenu($current, $onDone, 'Model: ');
        }

        return new SelectListWidget($models);
    }

    /**
     * @param  array<string, string>  $providerStatuses
     * @param  array<string, string>  $providerAuthModes
     */
    private function buildApiKeySubmenu(string $provider, callable $onDone, array $providerAuthModes): InputWidget|SelectListWidget
    {
        $mode = $providerAuthModes[$provider] ?? 'api_key';

        if ($mode !== 'api_key') {
            return new SelectListWidget([
                ['value' => '', 'label' => 'not_used', 'description' => 'This provider does not use an API key'],
            ]);
        }

        return $this->buildInputSubmenu('', $onDone, 'API Key: ');
    }

    /**
     * @param  array<string, string>  $providerStatuses
     * @param  array<string, string>  $providerAuthModes
     */
    private function buildAuthSubmenu(string $provider, array $providerStatuses, array $providerAuthModes): SelectListWidget
    {
        $status = $providerStatuses[$provider] ?? 'Unknown';
        $mode = $providerAuthModes[$provider] ?? 'api_key';

        $items = match ($mode) {
            'oauth' => [
                ['value' => 'login_browser', 'label' => 'login_browser', 'description' => "Open browser login · {$status}"],
                ['value' => 'login_device', 'label' => 'login_device', 'description' => 'Use device-code login'],
                ['value' => 'status', 'label' => 'status', 'description' => 'Show current authentication status'],
                ['value' => 'logout', 'label' => 'logout', 'description' => 'Remove stored Codex authentication'],
            ],
            'none' => [
                ['value' => 'status', 'label' => 'status', 'description' => $status],
            ],
            default => [
                ['value' => 'status', 'label' => 'status', 'description' => 'Show current key status'],
            ],
        };

        return new SelectListWidget($items);
    }

    private function settingsMaxVisible(): int
    {
        // Leave a little room for the selected item's description, hint line,
        // and surrounding chrome while still using nearly the full viewport.
        return max(8, $this->tui->getTerminal()->getRows() - 6);
    }

    /**
     * Flush a render pass to the terminal.
     */
    private function flushRender(): void
    {
        ($this->renderCallback)();
    }

    /**
     * Force a full re-render (clears all screen cache).
     */
    private function forceRender(): void
    {
        ($this->forceRenderCallback)();
    }

    private function focusSettingsItem(SettingsListWidget $widget, string $id): void
    {
        $select = \Closure::bind(function (string $id): void {
            foreach ($this->items as $index => $item) {
                if ($item->getId() === $id) {
                    if ($this->selectedIndex !== $index) {
                        $this->selectedIndex = $index;
                        $this->invalidate();
                    }

                    return;
                }
            }
        }, $widget, $widget::class);

        $select($id);
    }

    private function activateSettingsItem(SettingsListWidget $widget): void
    {
        $activate = \Closure::bind(function (): void {
            $this->activateCurrentItem();
        }, $widget, $widget::class);

        $activate();
    }
}

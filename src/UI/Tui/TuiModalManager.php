<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Widget\BorderFooterWidget;
use Kosmokrator\UI\Tui\Widget\PermissionPromptWidget;
use Kosmokrator\UI\Tui\Widget\PlanApprovalWidget;
use Kosmokrator\UI\Tui\Widget\QuestionWidget;
use Kosmokrator\UI\Tui\Widget\SwarmDashboardWidget;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\SettingChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\BorderPattern;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\VerticalAlign;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\SettingItem;
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
        private readonly ContainerWidget $overlay,
        private readonly Tui $tui,
        private readonly EditorWidget $input,
        private readonly \Closure $renderCallback,
        private readonly \Closure $forceRenderCallback,
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

        $decision = $suspension->suspend();

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

        $result = $suspension->suspend();

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
        $r = Theme::reset();
        $accent = Theme::accent();

        $widget = new QuestionWidget($question);
        $this->overlay->add($widget);

        $this->tui->setFocus($this->input);
        $this->flushRender();

        $this->askSuspension = EventLoop::getSuspension();
        $answer = $this->askSuspension->suspend();

        // Clean up overlay and show Q&A inline in conversation
        $this->overlay->remove($widget);
        $this->forceRender();

        return $answer;
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

        $result = $suspension->suspend();

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
        $selectedProvider = (string) ($currentSettings['provider'] ?? '');
        $modelsByProvider = is_array($currentSettings['model_options_by_provider'] ?? null)
            ? $currentSettings['model_options_by_provider']
            : [];
        $providerOptions = is_array($currentSettings['provider_options'] ?? null)
            ? $currentSettings['provider_options']
            : [];
        $providerStatuses = is_array($currentSettings['provider_statuses'] ?? null)
            ? $currentSettings['provider_statuses']
            : [];
        $providerAuthModes = is_array($currentSettings['provider_auth_modes'] ?? null)
            ? $currentSettings['provider_auth_modes']
            : [];

        $items = [
            new SettingItem(
                id: 'provider',
                label: 'Provider',
                currentValue: $currentSettings['provider'] ?? '',
                description: 'LLM provider -- press Enter to select from the shared provider catalog',
                submenu: fn (string $current, callable $onDone) => $this->buildProviderSubmenu($providerOptions),
            ),
            new SettingItem(
                id: 'model',
                label: 'Model',
                currentValue: $currentSettings['model'] ?? '',
                description: 'LLM model -- provider-aware list sourced from PrismRelay metadata',
                submenu: function (string $current, callable $onDone) use (&$selectedProvider, $modelsByProvider) {
                    return $this->buildModelSubmenu($selectedProvider, $current, $onDone, $modelsByProvider);
                },
            ),
            new SettingItem(
                id: 'auth_action',
                label: 'Auth',
                currentValue: $currentSettings['auth_status'] ?? '',
                description: 'Authentication status and actions for the selected provider',
                submenu: function (string $current, callable $onDone) use (&$selectedProvider, $providerStatuses, $providerAuthModes) {
                    return $this->buildAuthSubmenu($selectedProvider, $providerStatuses, $providerAuthModes);
                },
            ),
            new SettingItem(
                id: 'mode',
                label: 'Mode',
                currentValue: $currentSettings['mode'] ?? 'edit',
                description: 'Agent mode -- edit (full access), plan (read-only), ask (conversational)',
                values: ['edit', 'plan', 'ask'],
            ),
            new SettingItem(
                id: 'permission_mode',
                label: 'Permission Mode',
                currentValue: $currentSettings['permission_mode'] ?? 'guardian',
                description: 'Guardian (smart auto), Argus (ask all), Prometheus (approve all)',
                values: ['guardian', 'argus', 'prometheus'],
            ),
            new SettingItem(
                id: 'memories',
                label: 'Memories',
                currentValue: $currentSettings['memories'] ?? 'on',
                description: 'Long-term knowledge persistence across sessions',
                values: ['on', 'off'],
            ),
            new SettingItem(
                id: 'auto_compact',
                label: 'Auto-compact',
                currentValue: $currentSettings['auto_compact'] ?? 'on',
                description: 'Automatically compact context when approaching token limit',
                values: ['on', 'off'],
            ),
            new SettingItem(
                id: 'compact_threshold',
                label: 'Compact threshold',
                currentValue: $currentSettings['compact_threshold'] ?? '60',
                description: 'Context usage % at which compaction triggers (lower = more aggressive)',
                values: ['40', '50', '60', '70', '80'],
            ),
            new SettingItem(
                id: 'prune_protect',
                label: 'Prune protect',
                currentValue: $currentSettings['prune_protect'] ?? '40000',
                description: 'Tokens of recent tool output to protect from pruning',
                values: ['20000', '30000', '40000', '60000', '80000'],
            ),
            new SettingItem(
                id: 'prune_min_savings',
                label: 'Prune threshold',
                currentValue: $currentSettings['prune_min_savings'] ?? '20000',
                description: 'Minimum token savings required to trigger pruning',
                values: ['10000', '20000', '30000', '50000'],
            ),
            new SettingItem(
                id: 'temperature',
                label: 'Temperature',
                currentValue: $currentSettings['temperature'] ?? '0.0',
                description: 'LLM sampling temperature (0.0 = deterministic, 1.0 = creative)',
                values: ['0.0', '0.1', '0.2', '0.3', '0.5', '0.7', '1.0'],
            ),
            new SettingItem(
                id: 'max_tokens',
                label: 'Max Tokens',
                currentValue: $currentSettings['max_tokens'] ?? '',
                description: 'Maximum output tokens per LLM response (empty = provider default)',
                values: ['', '4096', '8192', '16384', '32768', '65536'],
            ),
            new SettingItem(
                id: 'subagent_concurrency',
                label: 'Subagent concurrency',
                currentValue: $currentSettings['subagent_concurrency'] ?? '10',
                description: 'Max concurrent subagents (0 = unlimited). Takes effect next session.',
                values: ['0', '1', '3', '5', '10', '20', '50', '100', '250', '500', '1000'],
            ),
            new SettingItem(
                id: 'subagent_max_retries',
                label: 'Subagent max retries',
                currentValue: $currentSettings['subagent_max_retries'] ?? '2',
                description: 'Max agent-level retries on transient failure (0 = no retry). Takes effect next session.',
                values: ['0', '1', '2', '3', '5'],
            ),
        ];

        $settingsWidget = new SettingsListWidget($items, maxVisible: $this->settingsMaxVisible());
        $settingsWidget->setId('settings-panel');
        $settingsWidget->setStyle(new Style(
            padding: new Padding(0, 0, 0, 0),
            border: Border::all(0),
        ));

        $panel = new ContainerWidget;
        $panel->setId('settings-panel-wrapper');
        $panel->expandVertically(true);
        $panel->setStyle(new Style(
            border: Border::all(1, BorderPattern::rounded(), Color::hex('#ffc850')),
            padding: new Padding(0, 1, 0, 1),
            verticalAlign: VerticalAlign::Top,
        ));
        $panel->add($settingsWidget);

        $this->overlay->expandVertically(true);
        $this->overlay->add($panel);
        $this->tui->setFocus($settingsWidget);
        $this->flushRender();

        $changes = [];
        $suspension = EventLoop::getSuspension();

        $settingsWidget->onChange(function (SettingChangeEvent $event) use (&$changes, &$selectedProvider) {
            $changes[$event->getId()] = $event->getValue();
            if ($event->getId() === 'provider') {
                $selectedProvider = $event->getValue();
            }
        });

        $settingsWidget->onCancel(function () use ($suspension) {
            $suspension->resume(null);
        });

        $suspension->suspend();

        $this->overlay->remove($panel);
        $this->overlay->expandVertically(false);
        $this->tui->setFocus($this->input);
        $this->forceRender();

        return $changes;
    }

    /**
     * Show an interactive session picker. Returns selected session ID or null.
     *
     * @param  array<array{value: string, label: string, description?: string}>  $items
     */
    public function pickSession(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        $selectList = new SelectListWidget($items, maxVisible: 8);
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

        $result = $suspension->suspend();

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
                } catch (\Throwable) {
                    // Ignore refresh errors
                }
            });
        }

        $suspension->suspend();

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
                ['value' => 'edit_key', 'label' => 'edit_key', 'description' => "Set or replace API key · {$status}"],
                ['value' => 'status', 'label' => 'status', 'description' => 'Show current key status'],
                ['value' => 'clear_key', 'label' => 'clear_key', 'description' => 'Remove the stored API key'],
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
}

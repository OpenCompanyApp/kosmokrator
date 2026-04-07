<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Widget;

use Kosmokrator\UI\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Full-screen settings editor for provider, model, and agent configuration.
 * Shown when the user opens the settings workspace (separate from the main conversation).
 * Renders a two-column layout (categories + fields), an inline value picker, a models browser,
 * and a details panel. Saves changes to YAML-backed config files.
 */
final class SettingsWorkspaceWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private int $categoryIndex = 0;

    /** Index of the currently selected field within the visible fields list. */
    private int $fieldIndex = 0;

    /** Whether the user is actively typing into a text field. */
    private bool $editing = false;

    /** Temporary buffer holding the in-progress text while editing a field. */
    private string $editBuffer = '';

    /** Whether the inline option picker overlay is open. */
    private bool $pickerOpen = false;

    /** Field ID that the picker is currently operating on. */
    private string $pickerFieldId = '';

    /** @var list<array{value: string, label: string, description: string}> Options available in the picker. */
    private array $pickerOptions = [];

    /** Index of the currently highlighted picker option. */
    private int $pickerIndex = 0;

    /** Search/filter string typed by the user to narrow picker options. */
    private string $pickerQuery = '';

    /** Config scope: 'project' or 'global'. */
    private string $scope;

    /** Whether provider setup is showing the editable form instead of the provider list. */
    private bool $providerSetupEditing = false;

    /** Index of the highlighted provider in the provider-setup browser. */
    private int $providerSetupListIndex = 0;

    /** @var array<string, string> Current field values keyed by field ID. */
    private array $values = [];

    /** @var array<string, string> Original values at construction time, used to detect changes. */
    private array $originalValues = [];

    /** @var callable(array<string, mixed>): void|null Callback invoked with the save result when the user saves. */
    private $onSaveCallback = null;

    /** @var callable(): void|null Callback invoked when the user cancels/closes settings. */
    private $onCancelCallback = null;

    /** ID of a custom provider the user has marked for deletion, or null. */
    private ?string $deleteCustomProviderId = null;

    /**
     * @param  array<string, mixed>  $view  Settings view data from the handler (categories, options, provider info)
     */
    public function __construct(
        private readonly array $view,
    ) {
        $this->scope = (string) ($view['scope'] ?? 'project');

        foreach ($this->categories() as $category) {
            foreach ($category['fields'] ?? [] as $field) {
                $id = (string) ($field['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $value = (string) ($field['value'] ?? '');
                $this->values[$id] = $value;
                $this->originalValues[$id] = $value;
            }
        }

        $this->syncProviderSetupListIndex();
    }

    /** Register the callback invoked when the user saves changes. */
    public function onSave(callable $callback): static
    {
        $this->onSaveCallback = $callback;

        return $this;
    }

    /** Register the callback invoked when the user cancels/closes settings. */
    public function onCancel(callable $callback): static
    {
        $this->onCancelCallback = $callback;

        return $this;
    }

    /**
     * Route keyboard input depending on current mode (editing, picker, models browser, or field navigation).
     */
    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        if ($this->editing) {
            if ($kb->matches($data, 'confirm')) {
                $field = $this->selectedField();
                if ($field !== null) {
                    $this->values[$field['id']] = $this->editBuffer;
                    $this->handleFieldSideEffects($field['id']);
                }

                $this->editing = false;
                $this->editBuffer = '';
                $this->invalidate();

                return;
            }

            if ($kb->matches($data, 'cancel')) {
                $this->editing = false;
                $this->editBuffer = '';
                $this->invalidate();

                return;
            }

            if ($kb->matches($data, 'backspace')) {
                $this->editBuffer = mb_substr($this->editBuffer, 0, max(0, mb_strlen($this->editBuffer) - 1));
                $this->invalidate();

                return;
            }

            $text = $this->normalizeEditInput($data);
            if ($text !== '') {
                $this->editBuffer .= $text;
                $this->invalidate();
            }

            return;
        }

        if ($this->pickerOpen) {
            if ($data === "\t" || $data === "\x1b[Z") {
                $this->closePicker();
                $this->cycleCategory($data === "\x1b[Z" ? -1 : 1);

                return;
            }

            if ($kb->matches($data, 'cancel')) {
                if ($this->pickerQuery !== '') {
                    $this->pickerQuery = '';
                    $this->resetPickerIndex();
                    $this->invalidate();
                } else {
                    $this->closePicker();
                }

                return;
            }

            if ($kb->matches($data, 'up')) {
                $count = count($this->visiblePickerOptions());
                if ($count > 0) {
                    $this->pickerIndex = ($this->pickerIndex - 1 + $count) % $count;
                    $this->invalidate();
                }

                return;
            }

            if ($kb->matches($data, 'down')) {
                $count = count($this->visiblePickerOptions());
                if ($count > 0) {
                    $this->pickerIndex = ($this->pickerIndex + 1) % $count;
                    $this->invalidate();
                }

                return;
            }

            if ($kb->matches($data, 'backspace')) {
                if ($this->pickerQuery !== '') {
                    $this->pickerQuery = mb_substr($this->pickerQuery, 0, max(0, mb_strlen($this->pickerQuery) - 1));
                    $this->resetPickerIndex();
                    $this->invalidate();
                }

                return;
            }

            if ($data !== '' && ! str_starts_with($data, "\033") && ! ctype_cntrl($data)) {
                $this->pickerQuery .= $data;
                $this->resetPickerIndex();
                $this->invalidate();

                return;
            }

            if ($kb->matches($data, 'confirm')) {
                $option = $this->selectedPickerOption();
                if ($option !== null && $this->pickerFieldId !== '') {
                    $this->values[$this->pickerFieldId] = $option['value'];
                    $this->handleFieldSideEffects($this->pickerFieldId);
                }

                $this->closePicker();

                return;
            }
        }

        if ($data === "\t" || $data === "\x1b[Z") {
            $this->cycleCategory($data === "\x1b[Z" ? -1 : 1);

            return;
        }

        if ($kb->matches($data, 'save') || $data === 's') {
            if ($this->onSaveCallback !== null) {
                ($this->onSaveCallback)($this->buildResult());
            }

            return;
        }

        if ($data === 'q') {
            // q = save-and-close (most natural "I'm done" gesture)
            if ($this->values !== $this->originalValues && $this->onSaveCallback !== null) {
                ($this->onSaveCallback)($this->buildResult());
            } elseif ($this->onCancelCallback !== null) {
                ($this->onCancelCallback)();
            }

            return;
        }

        if ($kb->matches($data, 'cancel')) {
            if ($this->onCancelCallback !== null) {
                ($this->onCancelCallback)();
            }

            return;
        }

        if ($data === 'g') {
            $this->scope = 'global';
            $this->invalidate();

            return;
        }

        if ($data === 'p') {
            $this->scope = 'project';
            $this->invalidate();

            return;
        }

        if ($data === 'r') {
            $field = $this->selectedField();
            if ($field !== null) {
                $this->values[$field['id']] = $this->originalValues[$field['id']] ?? '';
                $this->invalidate();
            }

            return;
        }

        if ($data === 'a') {
            $this->jumpToProviderDraft();

            return;
        }

        if ($data === 'x') {
            $providerId = trim((string) ($this->values['provider.setup_provider'] ?? $this->values['agent.default_provider'] ?? ''));
            if ($providerId === '__custom__') {
                $providerId = trim((string) ($this->values['custom_provider.id'] ?? ''));
            }
            if ($providerId !== '' && (($this->view['providers_by_id'][$providerId]['source'] ?? '') === 'custom')) {
                $this->deleteCustomProviderId = $providerId;
                $this->invalidate();
            }

            return;
        }

        if ($this->isModelsCategory()) {
            $this->handleModelsBrowserInput($data, $kb);

            return;
        }

        if ($this->isProviderSetupCategory() && ! $this->providerSetupEditing) {
            $this->handleProviderSetupBrowserInput($data, $kb);

            return;
        }

        $fields = $this->visibleFields();

        if ($fields === []) {
            return;
        }

        if ($kb->matches($data, 'up')) {
            $this->fieldIndex = ($this->fieldIndex - 1 + count($fields)) % count($fields);
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'down')) {
            $this->fieldIndex = ($this->fieldIndex + 1) % count($fields);
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'left') && $this->isProviderSetupCategory() && $this->providerSetupEditing) {
            $this->providerSetupEditing = false;
            $this->fieldIndex = $this->providerSetupListIndex;
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'right')) {
            $field = $this->selectedField();
            if ($field !== null && $this->fieldSupportsPicker($field)) {
                $this->openPickerForField($field);
            }

            return;
        }

        if ($kb->matches($data, 'confirm')) {
            $field = $this->selectedField();
            if ($field === null) {
                return;
            }

            if ($this->fieldSupportsPicker($field)) {
                $this->openPickerForField($field);

                return;
            }

            if (($field['type'] ?? 'text') !== 'readonly') {
                $this->editing = true;
                $this->editBuffer = $this->values[$field['id']] ?? '';
                $this->invalidate();
            }
        }
    }

    /**
     * Render the full settings workspace: header, category nav + fields, and details panel.
     *
     * @param  RenderContext  $context  Terminal dimensions
     * @return list<string> ANSI-formatted lines
     */
    public function render(RenderContext $context): array
    {
        $columns = max(60, $context->getColumns());
        $rows = max(24, $context->getRows());

        $headerLines = $this->renderHeader($columns);
        $availableHeight = $rows - count($headerLines) - 4;

        $navWidth = min(24, max(20, (int) floor($columns * 0.22)));
        $fieldsWidth = max(30, $columns - $navWidth - 2);

        $detailsHeight = min(12, max(8, (int) floor($availableHeight * 0.34)));
        $topHeight = max(8, $availableHeight - $detailsHeight - 1);

        $left = $this->renderCategories($navWidth, $topHeight);
        $right = $this->renderFields($fieldsWidth, $topHeight);
        $details = $this->renderDetails($columns, $detailsHeight);

        $lines = $headerLines;

        for ($i = 0; $i < $topHeight; $i++) {
            $leftLine = $left[$i] ?? str_repeat(' ', $navWidth);
            $rightLine = $right[$i] ?? str_repeat(' ', $fieldsWidth);
            $lines[] = $this->padVisible($leftLine.'  '.$rightLine, $columns);
        }

        $lines[] = '';

        foreach ($details as $detailLine) {
            $lines[] = $this->padVisible($detailLine, $columns);
        }

        $lines[] = '';
        $lines[] = $this->padVisible($this->footer($columns), $columns);

        return array_map(
            fn (string $line): string => $this->padVisible($this->truncateVisible($line, $context->getColumns()), $context->getColumns()),
            $lines,
        );
    }

    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP],
            'down' => [Key::DOWN],
            'left' => [Key::LEFT],
            'right' => [Key::RIGHT],
            'confirm' => [Key::ENTER],
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
            'save' => ['ctrl+s'],
            'backspace' => [Key::BACKSPACE],
        ];
    }

    /**
     * @return list<array{id: string, label: string, fields: array<int, array<string, mixed>>}>
     */
    private function categories(): array
    {
        return $this->view['categories'] ?? [];
    }

    /**
     * @return array{id: string, label: string, fields: array<int, array<string, mixed>>}
     */
    private function selectedCategory(): array
    {
        return $this->categories()[$this->categoryIndex] ?? ['id' => 'general', 'label' => 'General', 'fields' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedField(): ?array
    {
        $fields = $this->visibleFields();

        return $fields[$this->fieldIndex] ?? null;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array{value: string, label: string, description: string}>
     */
    private function optionsForField(array $field): array
    {
        $id = (string) ($field['id'] ?? '');
        if ($id === 'provider.setup_provider') {
            return array_values(array_map(
                static fn (array $item): array => [
                    'value' => (string) ($item['value'] ?? ''),
                    'label' => (string) ($item['label'] ?? $item['value'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                ],
                $this->view['setup_provider_options'] ?? [],
            ));
        }

        if ($id === 'agent.default_model') {
            $provider = $this->values['agent.default_provider'] ?? '';

            return array_values(array_map(
                static fn (array $item): array => [
                    'value' => (string) ($item['value'] ?? ''),
                    'label' => (string) ($item['label'] ?? $item['value'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                ],
                $this->view['model_options_by_provider'][$provider] ?? [],
            ));
        }

        if ($id === 'provider.auth_action') {
            $provider = $this->values['provider.setup_provider'] ?? $this->values['agent.default_provider'] ?? '';
            if ($provider === '__custom__') {
                $provider = '';
            }

            return array_values(array_map(
                static fn (mixed $item): array => is_array($item)
                    ? [
                        'value' => (string) ($item['value'] ?? ''),
                        'label' => (string) ($item['label'] ?? $item['value'] ?? ''),
                        'description' => (string) ($item['description'] ?? ''),
                    ]
                    : [
                        'value' => (string) $item,
                        'label' => (string) $item,
                        'description' => '',
                    ],
                $this->view['auth_action_options_by_provider'][$provider] ?? [''],
            ));
        }

        if ($id === 'agent.default_provider') {
            return array_values(array_map(
                static fn (array $item): array => [
                    'value' => (string) ($item['value'] ?? ''),
                    'label' => (string) ($item['label'] ?? $item['value'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                ],
                $this->view['provider_options'] ?? [],
            ));
        }

        if (in_array($id, ['agent.subagent_provider', 'agent.subagent_depth2_provider', 'agent.audio_provider'], true)) {
            $options = array_values(array_map(
                static fn (array $item): array => [
                    'value' => (string) ($item['value'] ?? ''),
                    'label' => (string) ($item['label'] ?? $item['value'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                ],
                $this->view['provider_options'] ?? [],
            ));
            array_unshift($options, ['value' => '', 'label' => '(inherit from default)', 'description' => 'Use the main agent provider.']);

            return $options;
        }

        if ($id === 'agent.subagent_model') {
            $provider = $this->values['agent.subagent_provider'] ?? '';
            if ($provider === '') {
                $provider = $this->values['agent.default_provider'] ?? '';
            }

            return $this->buildInheritableModelOptions($provider, 'Use the main agent model.');
        }

        if ($id === 'agent.subagent_depth2_model') {
            $provider = $this->values['agent.subagent_depth2_provider'] ?? '';
            if ($provider === '') {
                $provider = $this->values['agent.subagent_provider'] ?? '';
            }
            if ($provider === '') {
                $provider = $this->values['agent.default_provider'] ?? '';
            }

            return $this->buildInheritableModelOptions($provider, 'Use the subagent model, or the main agent model.');
        }

        if ($id === 'agent.audio_model') {
            $provider = $this->values['agent.audio_provider'] ?? '';
            if ($provider === '') {
                $provider = $this->values['agent.default_provider'] ?? '';
            }

            return $this->buildInheritableModelOptions($provider, 'Use the main agent model.');
        }

        return array_values(array_map(
            static fn (mixed $item): array => [
                'value' => (string) $item,
                'label' => (string) $item,
                'description' => '',
            ],
            $field['options'] ?? [],
        ));
    }

    /** Cascade side-effects when a provider-related field changes (e.g. reset model, update auth status). */
    private function handleFieldSideEffects(string $fieldId): void
    {
        if ($fieldId === 'agent.default_provider') {
            $provider = $this->values[$fieldId] ?? '';
            $this->values['provider.auth_status'] = (string) ($this->view['provider_statuses'][$provider] ?? 'Unknown');
            $this->values['provider.secret.api_key'] = (string) (($this->view['providers_by_id'][$provider]['auth_status'] ?? '') !== '' ? ($this->view['provider_api_key_display'][$provider] ?? '') : '');
            $this->values['provider.auth_action'] = '';
            $custom = $this->view['custom_provider_definitions'][$provider] ?? null;
            if (is_array($custom)) {
                $this->values['custom_provider.id'] = $provider;
                $this->values['custom_provider.label'] = (string) ($custom['label'] ?? '');
                $this->values['custom_provider.driver'] = (string) ($custom['driver'] ?? 'openai-compatible');
                $this->values['custom_provider.url'] = (string) ($custom['url'] ?? '');
                $this->values['custom_provider.auth'] = (string) ($custom['auth'] ?? 'api_key');
                $this->values['custom_provider.default_model'] = (string) ($custom['default_model'] ?? '');

                $models = is_array($custom['models'] ?? null) ? $custom['models'] : [];
                $firstId = (string) array_key_first($models);
                $firstModel = is_array($models[$firstId] ?? null) ? $models[$firstId] : [];
                $this->values['custom_provider.model_id'] = $firstId;
                $this->values['custom_provider.context'] = (string) ($firstModel['context'] ?? '');
                $this->values['custom_provider.max_output'] = (string) ($firstModel['max_output'] ?? '');
                $this->values['custom_provider.input_modalities'] = implode(', ', $firstModel['modalities']['input'] ?? $custom['modalities']['input'] ?? ['text']);
                $this->values['custom_provider.output_modalities'] = implode(', ', $firstModel['modalities']['output'] ?? $custom['modalities']['output'] ?? ['text']);
            } else {
                $models = $this->optionsForField(['id' => 'agent.default_model']);
                $modelValues = array_map(static fn (array $item): string => $item['value'], $models);
                if ($models !== [] && ! in_array($this->values['agent.default_model'] ?? '', $modelValues, true)) {
                    $this->values['agent.default_model'] = $models[0]['value'];
                }
            }
        }

        if ($fieldId === 'provider.setup_provider') {
            $provider = trim((string) ($this->values[$fieldId] ?? ''));
            if ($provider === '__custom__') {
                $this->values['provider.setup_status'] = 'Not configured';
                $this->values['provider.setup_auth_mode'] = 'api_key';
                $this->values['provider.setup_driver'] = 'openai-compatible';
                $this->values['provider.setup_url'] = '';
                $this->values['provider.secret.api_key'] = '';
                $this->values['provider.auth_action'] = '';
                foreach ([
                    'custom_provider.id',
                    'custom_provider.label',
                    'custom_provider.url',
                    'custom_provider.default_model',
                    'custom_provider.model_id',
                    'custom_provider.context',
                    'custom_provider.max_output',
                ] as $customField) {
                    $this->values[$customField] = '';
                }
                $this->values['custom_provider.driver'] = 'openai-compatible';
                $this->values['custom_provider.auth'] = 'api_key';
                $this->values['custom_provider.input_modalities'] = 'text';
                $this->values['custom_provider.output_modalities'] = 'text';
                $this->fieldIndex = 0;
                $this->invalidate();

                return;
            }

            $providerInfo = $this->view['providers_by_id'][$provider] ?? [];
            $this->values['provider.setup_status'] = (string) ($providerInfo['auth_status'] ?? 'Unknown');
            $this->values['provider.setup_auth_mode'] = (string) ($providerInfo['auth_mode'] ?? 'api_key');
            $this->values['provider.setup_driver'] = (string) ($providerInfo['driver'] ?? '');
            $this->values['provider.setup_url'] = (string) ($providerInfo['url'] ?? '');
            $this->values['provider.secret.api_key'] = (string) ($this->view['provider_api_key_display'][$provider] ?? '');
            $this->values['provider.auth_action'] = '';

            $custom = $this->view['custom_provider_definitions'][$provider] ?? null;
            if (is_array($custom)) {
                $models = is_array($custom['models'] ?? null) ? $custom['models'] : [];
                $firstId = (string) array_key_first($models);
                $firstModel = is_array($models[$firstId] ?? null) ? $models[$firstId] : [];
                $this->values['custom_provider.id'] = $provider;
                $this->values['custom_provider.label'] = (string) ($custom['label'] ?? '');
                $this->values['custom_provider.driver'] = (string) ($custom['driver'] ?? 'openai-compatible');
                $this->values['custom_provider.url'] = (string) ($custom['url'] ?? '');
                $this->values['custom_provider.auth'] = (string) ($custom['auth'] ?? 'api_key');
                $this->values['custom_provider.default_model'] = (string) ($custom['default_model'] ?? $firstId);
                $this->values['custom_provider.model_id'] = $firstId;
                $this->values['custom_provider.context'] = (string) ($firstModel['context'] ?? '');
                $this->values['custom_provider.max_output'] = (string) ($firstModel['max_output'] ?? '');
                $this->values['custom_provider.input_modalities'] = implode(', ', $firstModel['modalities']['input'] ?? $custom['modalities']['input'] ?? ['text']);
                $this->values['custom_provider.output_modalities'] = implode(', ', $firstModel['modalities']['output'] ?? $custom['modalities']['output'] ?? ['text']);
            } else {
                foreach ([
                    'custom_provider.id',
                    'custom_provider.label',
                    'custom_provider.url',
                    'custom_provider.default_model',
                    'custom_provider.model_id',
                    'custom_provider.context',
                    'custom_provider.max_output',
                ] as $customField) {
                    $this->values[$customField] = '';
                }
                $this->values['custom_provider.driver'] = 'openai-compatible';
                $this->values['custom_provider.auth'] = 'api_key';
                $this->values['custom_provider.input_modalities'] = 'text';
                $this->values['custom_provider.output_modalities'] = 'text';
            }

            $this->fieldIndex = 0;
            $this->invalidate();
        }

        if ($fieldId === 'agent.subagent_provider') {
            $this->resetModelIfInvalid('agent.subagent_model');
        }

        if ($fieldId === 'agent.subagent_depth2_provider') {
            $this->resetModelIfInvalid('agent.subagent_depth2_model');
        }

        if ($fieldId === 'agent.audio_provider') {
            $this->resetModelIfInvalid('agent.audio_model');
        }
    }

    /** Jump to the provider_setup category and pre-fill values for a new custom provider. */
    private function jumpToProviderDraft(): void
    {
        foreach ($this->categories() as $index => $category) {
            if (($category['id'] ?? '') !== 'provider_setup') {
                continue;
            }

            $this->categoryIndex = $index;
            $this->selectProviderSetupItem('__custom__');

            return;
        }
    }

    private function selectProviderSetupItem(string $provider): void
    {
        $this->values['provider.setup_provider'] = $provider;
        $this->deleteCustomProviderId = null;
        $this->providerSetupEditing = true;

        if ($provider === '__custom__') {
            $this->values['provider.setup_status'] = 'Not configured';
            $this->values['provider.setup_auth_mode'] = 'api_key';
            $this->values['provider.setup_driver'] = 'openai-compatible';
            $this->values['provider.setup_url'] = '';
            $this->values['provider.secret.api_key'] = '';
            $this->values['provider.auth_action'] = '';
            $this->values['custom_provider.id'] = $this->nextCustomProviderId();
            $this->values['custom_provider.label'] = '';
            $this->values['custom_provider.driver'] = 'openai-compatible';
            $this->values['custom_provider.url'] = '';
            $this->values['custom_provider.auth'] = 'api_key';
            $this->values['custom_provider.default_model'] = '';
            $this->values['custom_provider.model_id'] = '';
            $this->values['custom_provider.context'] = '';
            $this->values['custom_provider.max_output'] = '';
            $this->values['custom_provider.input_modalities'] = 'text';
            $this->values['custom_provider.output_modalities'] = 'text';
        } else {
            $this->handleFieldSideEffects('provider.setup_provider');
        }

        $this->syncProviderSetupListIndex();
        $this->fieldIndex = 0;
        $this->invalidate();
    }

    /** Build model options for an inheritable field, prepended with an "(inherit)" entry. */
    private function buildInheritableModelOptions(string $provider, string $inheritDescription): array
    {
        $options = array_values(array_map(
            static fn (array $item): array => [
                'value' => (string) ($item['value'] ?? ''),
                'label' => (string) ($item['label'] ?? $item['value'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
            ],
            $this->view['model_options_by_provider'][$provider] ?? [],
        ));
        array_unshift($options, ['value' => '', 'label' => '(inherit from default)', 'description' => $inheritDescription]);

        return $options;
    }

    /** Reset a model field to empty if its current value is not in the available options. */
    private function resetModelIfInvalid(string $modelFieldId): void
    {
        $current = $this->values[$modelFieldId] ?? '';
        if ($current === '') {
            return;
        }

        $models = $this->optionsForField(['id' => $modelFieldId]);
        $modelValues = array_map(static fn (array $item): string => $item['value'], $models);

        // Free-text providers have no fixed options — any model value is valid
        if ($models === [] && $this->providerIsFreeTextForField($modelFieldId)) {
            return;
        }

        if (! in_array($current, $modelValues, true)) {
            $this->values[$modelFieldId] = '';
        }
    }

    private function providerIsFreeTextForField(string $modelFieldId): bool
    {
        $freeTextProviders = $this->view['free_text_model_providers'] ?? [];

        return match ($modelFieldId) {
            'agent.default_model' => in_array((string) ($this->values['agent.default_provider'] ?? ''), $freeTextProviders, true),
            'agent.subagent_model' => in_array(
                (string) ($this->values['agent.subagent_provider'] ?? '') !== ''
                    ? $this->values['agent.subagent_provider']
                    : ($this->values['agent.default_provider'] ?? ''),
                $freeTextProviders,
                true,
            ),
            'agent.subagent_depth2_model' => in_array(
                (string) ($this->values['agent.subagent_depth2_provider'] ?? '') !== ''
                    ? $this->values['agent.subagent_depth2_provider']
                    : (($this->values['agent.subagent_provider'] ?? '') !== ''
                        ? $this->values['agent.subagent_provider']
                        : ($this->values['agent.default_provider'] ?? '')),
                $freeTextProviders,
                true,
            ),
            'agent.audio_model' => in_array(
                (string) ($this->values['agent.audio_provider'] ?? '') !== ''
                    ? $this->values['agent.audio_provider']
                    : ($this->values['agent.default_provider'] ?? ''),
                $freeTextProviders,
                true,
            ),
            default => false,
        };
    }

    /**
     * Build the result payload with scope, changed values, custom provider definition, and delete marker.
     *
     * @return array<string, mixed>
     */
    private function buildResult(): array
    {
        $changes = [];
        foreach ($this->values as $id => $value) {
            if (($this->originalValues[$id] ?? '') !== $value) {
                $changes[$id] = $value;
            }
        }

        return [
            'scope' => $this->scope,
            'changes' => $changes,
            'custom_provider' => $this->buildCustomProvider(),
            'delete_custom_provider' => $this->deleteCustomProviderId,
        ];
    }

    /**
     * Assemble a custom provider definition from current field values, or null if incomplete.
     *
     * @return array<string, mixed>|null
     */
    private function buildCustomProvider(): ?array
    {
        $providerId = trim($this->values['custom_provider.id'] ?? '');
        if ($providerId === '') {
            return null;
        }

        $modelId = trim($this->values['custom_provider.model_id'] ?? '');
        if ($modelId === '') {
            return null;
        }

        $input = $this->csvValues($this->values['custom_provider.input_modalities'] ?? 'text');
        $output = $this->csvValues($this->values['custom_provider.output_modalities'] ?? 'text');

        return [
            'id' => $providerId,
            'definition' => [
                'label' => trim($this->values['custom_provider.label'] ?? ''),
                'driver' => trim($this->values['custom_provider.driver'] ?? 'openai-compatible'),
                'auth' => trim($this->values['custom_provider.auth'] ?? 'api_key'),
                'url' => trim($this->values['custom_provider.url'] ?? ''),
                'default_model' => trim($this->values['custom_provider.default_model'] ?? $modelId),
                'modalities' => [
                    'input' => $input,
                    'output' => $output,
                ],
                'models' => [
                    $modelId => [
                        'display_name' => trim($this->values['custom_provider.label'] ?? $modelId),
                        'context' => (int) ($this->values['custom_provider.context'] ?? 0),
                        'max_output' => (int) ($this->values['custom_provider.max_output'] ?? 0),
                        'modalities' => [
                            'input' => $input,
                            'output' => $output,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    /** Split a comma-separated string into a trimmed, filtered list of values. */
    private function csvValues(string $csv): array
    {
        $parts = array_map('trim', explode(',', $csv));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
    /** Render the top header showing scope, provider, model, and save status. */
    private function renderHeader(int $width): array
    {
        $r = Theme::reset();
        $accent = Theme::accent();
        $white = Theme::white();
        $dim = Theme::dim();

        $provider = $this->values['agent.default_provider'] ?? '';
        $model = $this->values['agent.default_model'] ?? '';
        $unsaved = $this->values === $this->originalValues ? 'Saved' : 'Unsaved changes';
        $status = $unsaved === 'Saved' ? Theme::info() : Theme::warning();
        $providerLabel = $this->displayLabelForFieldValue('agent.default_provider', $provider);
        $modelLabel = $this->displayLabelForFieldValue('agent.default_model', $model);

        return [
            "{$accent}⚙ Settings{$r}  {$dim}scope{$r}: {$white}{$this->scope}{$r}  {$dim}provider{$r}: {$white}{$providerLabel}{$r}  {$dim}model{$r}: {$white}{$modelLabel}{$r}  {$status}{$unsaved}{$r}",
            "{$dim}Separate settings workspace. Save writes YAML-backed config; auth secrets remain managed separately.{$r}",
            str_repeat('─', max(10, $width - 2)),
        ];
    }

    /**
     * @return list<string>
     */
    /** Render the left-side category navigation panel. */
    private function renderCategories(int $width, int $height): array
    {
        $lines = [$this->boxHeader('Categories', $width)];
        foreach ($this->categories() as $index => $category) {
            $selected = $index === $this->categoryIndex;
            $prefix = $selected ? '• ' : '  ';
            $color = $selected ? Theme::accent() : Theme::text();
            $lines[] = $this->boxLine("{$prefix}{$color}{$category['label']}".Theme::reset(), $width);
        }

        while (count($lines) < $height - 1) {
            $lines[] = $this->boxLine('', $width);
        }
        $lines[] = $this->boxFooter($width);

        return array_slice($lines, 0, $height);
    }

    /**
     * @return list<string>
     */
    /** Render the right-side field list, picker overlay, or models browser. */
    private function renderFields(int $width, int $height): array
    {
        $category = $this->selectedCategory();
        if ($this->pickerOpen) {
            return $this->renderPicker($width, $height);
        }

        if ($this->isModelsCategory()) {
            return $this->renderModelsBrowser($width, $height);
        }

        if ($this->isProviderSetupCategory() && ! $this->providerSetupEditing) {
            return $this->renderProviderSetupBrowser($width, $height);
        }

        $title = $category['label'];
        if ($this->isProviderSetupCategory()) {
            $selectedProvider = trim((string) ($this->values['provider.setup_provider'] ?? ''));
            $title = 'Provider Setup';
            if ($selectedProvider !== '') {
                $title .= ' · '.$this->displayLabelForFieldValue('provider.setup_provider', $selectedProvider);
            }
        }

        $lines = [$this->boxHeader($title, $width)];
        $fields = $this->visibleFields();

        $visibleCount = max(6, $height - 2);
        $offset = max(0, $this->fieldIndex - (int) floor($visibleCount / 2));
        $window = array_slice($fields, $offset, $visibleCount);

        foreach ($window as $relative => $field) {
            $absoluteIndex = $offset + $relative;
            $selected = $absoluteIndex === $this->fieldIndex;
            $cursor = $selected ? '› ' : '  ';
            $label = (string) ($field['label'] ?? $field['id']);
            $value = $selected && $this->editing
                ? ($this->usesWideEditor($field) ? 'editing below' : $this->editBuffer.'▏')
                : $this->displayValueForField($field);
            if ($this->fieldSupportsPicker($field)) {
                $value .= '  ▾';
            }
            $color = $selected ? Theme::white() : Theme::text();
            $line = $this->formatEntryLine($cursor, $label, $value, max(8, $width - 2));
            $lines[] = $this->boxLine("{$color}{$line}".Theme::reset(), $width);
        }

        while (count($lines) < $height - 1) {
            $lines[] = $this->boxLine('', $width);
        }
        $lines[] = $this->boxFooter($width);

        return array_slice($lines, 0, $height);
    }

    /**
     * @return list<string>
     */
    /** Render the bottom details panel showing field info, provider details, or YAML preview. */
    private function renderDetails(int $width, int $height): array
    {
        $lines = [$this->boxHeader('Details', $width)];
        if ($this->pickerOpen) {
            $option = $this->selectedPickerOption();
            $field = $this->selectedField();
            $title = (string) ($field['label'] ?? 'Selection');
            $matches = count($this->visiblePickerOptions());
            $lines[] = $this->boxLine($title, $width, Theme::accent());
            $lines[] = $this->boxLine('Enter selects · Esc clears/closes · type to filter', $width);
            $lines[] = $this->boxLine('Matches: '.$matches, $width);
            $lines[] = $this->boxLine('Filter: '.($this->pickerQuery !== '' ? $this->pickerQuery : '(none)'), $width);
            $lines[] = $this->boxLine('', $width);
            if ($option !== null) {
                $lines[] = $this->boxLine('Value: '.$option['value'], $width);
                foreach ($this->wrap($option['description'], $width - 2) as $line) {
                    $lines[] = $this->boxLine($line, $width);
                }
            }

            while (count($lines) < $height - 1) {
                $lines[] = $this->boxLine('', $width);
            }
            $lines[] = $this->boxFooter($width);

            return array_slice($lines, 0, $height);
        }

        if ($this->isModelsCategory()) {
            return $this->renderModelsDetails($width, $height);
        }

        if ($this->isProviderSetupCategory() && ! $this->providerSetupEditing) {
            return $this->renderProviderSetupBrowserDetails($width, $height);
        }

        $field = $this->selectedField();
        $categoryId = (string) ($this->selectedCategory()['id'] ?? '');
        $provider = $categoryId === 'provider_setup'
            ? (string) ($this->values['provider.setup_provider'] ?? '')
            : (string) ($this->values['agent.default_provider'] ?? '');
        if ($provider === '__custom__') {
            $provider = (string) ($this->values['custom_provider.id'] ?? '');
        }
        $providerInfo = $this->view['providers_by_id'][$provider] ?? [];

        if ($field !== null) {
            if ($this->editing) {
                $label = (string) ($field['label'] ?? $field['id']);
                $lines[] = $this->boxLine('Editing: '.$label, $width, Theme::accent());
                $lines[] = $this->boxLine('Enter saves · Esc cancels · paste supported', $width);
                $lines[] = $this->boxLine('', $width);

                foreach ($this->wrapForBox($this->editBuffer === '' ? ' ' : $this->editBuffer, max(8, $width - 2)) as $line) {
                    $lines[] = $this->boxLine($line, $width);
                }

                $lines[] = $this->boxLine('', $width);
            }

            foreach ($this->wrap((string) ($field['description'] ?? ''), $width - 2) as $line) {
                $lines[] = $this->boxLine($line, $width);
            }
            $lines[] = $this->boxLine('', $width);
            $lines[] = $this->boxLine('Source: '.($field['source'] ?? 'default'), $width);
            $lines[] = $this->boxLine('Effect: '.($field['effect'] ?? 'applies_now'), $width);
        }

        if ($provider !== '') {
            $lines[] = $this->boxLine('', $width);
            $lines[] = $this->boxLine('Provider', $width, Theme::accent());
            $lines[] = $this->boxLine('ID: '.$provider, $width);
            $lines[] = $this->boxLine('Auth: '.($providerInfo['auth_status'] ?? 'Unknown'), $width);
            $lines[] = $this->boxLine('Driver: '.($providerInfo['driver'] ?? 'unknown'), $width);
            $lines[] = $this->boxLine('Source: '.(($providerInfo['source'] ?? 'built_in') === 'custom' ? 'Custom YAML' : 'Built-in Relay'), $width);
            $lines[] = $this->boxLine('Input: '.implode(', ', $providerInfo['input_modalities'] ?? ['text']), $width);
            $lines[] = $this->boxLine('Output: '.implode(', ', $providerInfo['output_modalities'] ?? ['text']), $width);
        }

        if ($provider !== '' && in_array((string) ($field['id'] ?? ''), ['agent.default_provider', 'agent.default_model', 'provider.model_inventory'], true)) {
            $lines[] = $this->boxLine('', $width);
            $freeTextProviders = $this->view['free_text_model_providers'] ?? [];
            if (in_array($provider, $freeTextProviders, true)) {
                $lines[] = $this->boxLine('Model Entry', $width, Theme::accent());
                $lines[] = $this->boxLine('Type any model code (e.g. google/gemini-2.5-pro)', $width);
                $lines[] = $this->boxLine('This provider supports hundreds of models.', $width);
                $lines[] = $this->boxLine('Enter the model code directly in the model field.', $width);
            } else {
                $lines[] = $this->boxLine('Available Models', $width, Theme::accent());
                $modelOptions = $this->view['model_options_by_provider'][$provider] ?? [];
                $labels = array_map(
                    static fn (array $item): string => (string) (($item['label'] ?? '') !== '' ? $item['label'] : ($item['value'] ?? '')),
                    array_slice($modelOptions, 0, 24),
                );

                foreach ($this->wrap(implode(', ', $labels), $width - 2) as $line) {
                    $lines[] = $this->boxLine($line, $width);
                }

                if (count($modelOptions) > 24) {
                    $lines[] = $this->boxLine('...and '.(count($modelOptions) - 24).' more', $width);
                }
            }
        }

        $yaml = $categoryId === 'provider_setup' ? $this->buildYamlPreview() : [];
        if ($yaml !== []) {
            $lines[] = $this->boxLine('', $width);
            $lines[] = $this->boxLine('Custom Provider YAML', $width, Theme::accent());
            foreach ($yaml as $yamlLine) {
                $lines[] = $this->boxLine($yamlLine, $width);
            }
        }

        while (count($lines) < $height - 1) {
            $lines[] = $this->boxLine('', $width);
        }
        $lines[] = $this->boxFooter($width);

        return array_slice($lines, 0, $height);
    }

    /**
     * @return list<string>
     */
    /** Generate a YAML preview of the current custom provider definition for display in details. */
    private function buildYamlPreview(): array
    {
        $provider = $this->buildCustomProvider();
        if ($provider === null) {
            return [];
        }

        $id = $provider['id'];
        $definition = $provider['definition'];

        return [
            'relay:',
            '  providers:',
            "    {$id}:",
            '      label: '.($definition['label'] !== '' ? $definition['label'] : $id),
            "      driver: {$definition['driver']}",
            "      auth: {$definition['auth']}",
            "      url: {$definition['url']}",
            "      default_model: {$definition['default_model']}",
            '      modalities:',
            '        input: ['.implode(', ', $definition['modalities']['input']).']',
            '        output: ['.implode(', ', $definition['modalities']['output']).']',
        ];
    }

    /** Build the keybinding footer line appropriate for the current mode. */
    private function footer(int $width): string
    {
        $dim = Theme::text();
        $r = Theme::reset();

        if ($this->isModelsCategory()) {
            return AnsiUtils::truncateToWidth(
                "{$dim}Tab/Shift+Tab category  ↑↓ browse providers/models  Enter select default  s/q save+close  Esc discard  g/p scope{$r}",
                $width,
                '',
            );
        }

        if ($this->isProviderSetupCategory()) {
            if (! $this->providerSetupEditing) {
                return AnsiUtils::truncateToWidth(
                    "{$dim}Tab/Shift+Tab category  ↑↓ browse providers  Enter configure  s/q save+close  Esc discard  g/p scope  a new custom{$r}",
                    $width,
                    '',
                );
            }

            return AnsiUtils::truncateToWidth(
                "{$dim}Tab/Shift+Tab category  ↑↓ fields  ← back to providers  → open list  Enter select/edit  Esc clear/back  s/q save+close  Esc discard  g/p scope  r reset{$r}",
                $width,
                '',
            );
        }

        return AnsiUtils::truncateToWidth(
            "{$dim}Tab/Shift+Tab category  ↑↓ fields/list  → open list  type to filter  Enter select/edit  Esc clear/back  s/q save+close  Esc discard  g/p scope  r reset{$r}",
            $width,
            '',
        );
    }

    /** Pad a line to a visible width, accounting for ANSI escape sequences. */
    private function padVisible(string $line, int $width): string
    {
        $visible = AnsiUtils::visibleWidth($line);
        if ($visible >= $width) {
            return $line;
        }

        return $line.str_repeat(' ', $width - $visible);
    }

    /** Truncate a line to a visible width, stripping ANSI sequences correctly. */
    private function truncateVisible(string $line, int $width): string
    {
        return AnsiUtils::truncateToWidth($line, $width, '', false);
    }

    /** Format a two-column entry line with cursor prefix, label, and right-aligned value. */
    private function formatEntryLine(string $prefix, string $left, string $right, int $innerWidth): string
    {
        $prefixWidth = AnsiUtils::visibleWidth($prefix);
        $rightWidth = AnsiUtils::visibleWidth($right);
        $gap = $right !== '' ? 1 : 0;
        $minLeft = min(24, max(12, (int) floor($innerWidth * 0.4)));

        if ($prefixWidth >= $innerWidth) {
            return $this->truncateVisible($prefix, $innerWidth);
        }

        if ($prefixWidth + $rightWidth + $gap >= $innerWidth) {
            $availableRight = max(0, $innerWidth - $prefixWidth - $minLeft - $gap);
            if ($availableRight <= 0) {
                return $prefix.$this->truncateVisible($left, $innerWidth - $prefixWidth);
            }

            $left = $this->truncateVisible($left, max(0, $innerWidth - $prefixWidth - $availableRight - $gap));
            $leftWidth = AnsiUtils::visibleWidth($left);
            $right = $this->truncateVisible($right, $availableRight);
            $rightWidth = AnsiUtils::visibleWidth($right);
            $padding = max(0, $innerWidth - $prefixWidth - $leftWidth - $rightWidth);

            return $prefix.$left.str_repeat(' ', $padding).$right;
        }

        $availableLeft = max(0, $innerWidth - $prefixWidth - $rightWidth - $gap);
        $left = $this->truncateVisible($left, $availableLeft);
        $leftWidth = AnsiUtils::visibleWidth($left);
        $padding = max(0, $innerWidth - $prefixWidth - $leftWidth - $rightWidth);

        return $prefix.$left.str_repeat(' ', $padding).$right;
    }

    /** Cycle to the next/previous category and reset field selection. */
    private function cycleCategory(int $direction): void
    {
        $categories = $this->categories();
        if ($categories === []) {
            return;
        }

        $this->categoryIndex = ($this->categoryIndex + $direction + count($categories)) % count($categories);
        $this->fieldIndex = 0;
        $this->providerSetupEditing = false;
        $this->syncProviderSetupListIndex();
        $this->invalidate();
    }

    /**
     * @return list<array<string, mixed>>
     */
    /** Return the list of fields for the current category, filtering provider_setup fields by visibility rules. */
    private function visibleFields(): array
    {
        $fields = $this->selectedCategory()['fields'] ?? [];
        $categoryId = (string) ($this->selectedCategory()['id'] ?? '');

        if ($categoryId !== 'provider_setup') {
            $this->fieldIndex = min($this->fieldIndex, max(0, count($fields) - 1));

            return $fields;
        }

        $selectedProvider = trim((string) ($this->values['provider.setup_provider'] ?? ($this->values['agent.default_provider'] ?? '')));
        $providerInfo = $selectedProvider !== '' && $selectedProvider !== '__custom__'
            ? ($this->view['providers_by_id'][$selectedProvider] ?? [])
            : [];
        $showCustomFields = $selectedProvider === '__custom__' || (($providerInfo['source'] ?? '') === 'custom');
        $authMode = (string) ($providerInfo['auth_mode'] ?? ($selectedProvider === '__custom__' ? 'api_key' : 'api_key'));

        $visible = array_values(array_filter($fields, static function (array $field) use ($showCustomFields, $authMode): bool {
            $id = (string) ($field['id'] ?? '');

            return match ($id) {
                'provider.setup_status',
                'provider.setup_auth_mode',
                'provider.setup_driver',
                'provider.setup_url' => true,
                'provider.secret.api_key' => $authMode === 'api_key',
                'provider.auth_action' => $authMode !== 'none',
                default => str_starts_with($id, 'custom_provider.') ? $showCustomFields : true,
            };
        }));

        $this->fieldIndex = min($this->fieldIndex, max(0, count($visible) - 1));

        return $visible;
    }

    /** Check whether the currently selected category is the models browser. */
    private function isModelsCategory(): bool
    {
        return (string) ($this->selectedCategory()['id'] ?? '') === 'models';
    }

    /** Check whether the currently selected category is the provider setup browser/form. */
    private function isProviderSetupCategory(): bool
    {
        return (string) ($this->selectedCategory()['id'] ?? '') === 'provider_setup';
    }

    /** Handle Up/Down/Enter/Right input when the models browser is active. */
    private function handleModelsBrowserInput(string $data, object $kb): void
    {
        $items = $this->modelBrowserItems();
        if ($items === []) {
            return;
        }

        if ($kb->matches($data, 'up')) {
            $this->fieldIndex = ($this->fieldIndex - 1 + count($items)) % count($items);
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'down')) {
            $this->fieldIndex = ($this->fieldIndex + 1) % count($items);
            $this->invalidate();

            return;
        }

        if (! $kb->matches($data, 'confirm') && ! $kb->matches($data, 'right')) {
            return;
        }

        $selected = $items[$this->fieldIndex] ?? null;
        if ($selected === null) {
            return;
        }

        $provider = (string) ($selected['provider'] ?? '');
        if ($provider === '') {
            return;
        }

        $this->values['agent.default_provider'] = $provider;
        $this->handleFieldSideEffects('agent.default_provider');

        if (($selected['type'] ?? '') === 'model') {
            $this->values['agent.default_model'] = (string) ($selected['model'] ?? '');
        }

        $this->invalidate();
    }

    /** Handle Up/Down/Enter/Right input when the provider setup browser is active. */
    private function handleProviderSetupBrowserInput(string $data, object $kb): void
    {
        $items = $this->providerSetupItems();
        if ($items === []) {
            return;
        }

        if ($kb->matches($data, 'up')) {
            $this->fieldIndex = ($this->fieldIndex - 1 + count($items)) % count($items);
            $this->providerSetupListIndex = $this->fieldIndex;
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'down')) {
            $this->fieldIndex = ($this->fieldIndex + 1) % count($items);
            $this->providerSetupListIndex = $this->fieldIndex;
            $this->invalidate();

            return;
        }

        if (! $kb->matches($data, 'confirm') && ! $kb->matches($data, 'right')) {
            return;
        }

        $selected = $items[$this->fieldIndex] ?? null;
        if ($selected === null) {
            return;
        }

        $this->selectProviderSetupItem((string) ($selected['value'] ?? ''));
    }

    /** Check whether a field offers selectable options (choice, toggle, or dynamic_choice). */
    private function fieldSupportsPicker(array $field): bool
    {
        return in_array((string) ($field['type'] ?? 'text'), ['choice', 'toggle', 'dynamic_choice'], true)
            || $this->optionsForField($field) !== [];
    }

    /**
     * @return list<array{type:string,provider:string,model:string,label:string,description:string}>
     */
    /** Build the flat list of provider and model items shown in the models browser. */
    private function modelBrowserItems(): array
    {
        $items = [];
        $freeTextProviders = $this->view['free_text_model_providers'] ?? [];

        foreach ($this->view['models_provider_options'] ?? $this->view['provider_options'] ?? [] as $providerOption) {
            $provider = (string) ($providerOption['value'] ?? '');
            if ($provider === '') {
                continue;
            }

            if (in_array($provider, $freeTextProviders, true)) {
                $items[] = [
                    'type' => 'provider',
                    'provider' => $provider,
                    'model' => '',
                    'label' => (string) ($providerOption['label'] ?? $provider),
                    'description' => 'any model (free-text entry)',
                ];

                continue;
            }

            $models = $this->view['models_model_options_by_provider'][$provider]
                ?? $this->view['model_options_by_provider'][$provider]
                ?? [];
            $items[] = [
                'type' => 'provider',
                'provider' => $provider,
                'model' => '',
                'label' => (string) ($providerOption['label'] ?? $provider),
                'description' => count($models).' models',
            ];

            foreach ($models as $modelOption) {
                $items[] = [
                    'type' => 'model',
                    'provider' => $provider,
                    'model' => (string) ($modelOption['value'] ?? ''),
                    'label' => (string) ($modelOption['label'] ?? $modelOption['value'] ?? ''),
                    'description' => (string) ($modelOption['description'] ?? ''),
                ];
            }
        }

        return $items;
    }

    /**
     * @return list<array{value:string,label:string,description:string,source:string,auth_status:string}>
     */
    private function providerSetupItems(): array
    {
        $items = [];

        foreach ($this->view['setup_provider_options'] ?? [] as $option) {
            $value = (string) ($option['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $providerInfo = $this->view['providers_by_id'][$value] ?? [];
            $items[] = [
                'value' => $value,
                'label' => (string) ($option['label'] ?? $value),
                'description' => (string) ($option['description'] ?? ''),
                'source' => (string) ($providerInfo['source'] ?? ($value === '__custom__' ? 'custom' : 'built_in')),
                'auth_status' => (string) ($providerInfo['auth_status'] ?? ($value === '__custom__' ? 'Not configured' : 'Unknown')),
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    /** Render the models browser panel (provider + model tree). */
    private function renderModelsBrowser(int $width, int $height): array
    {
        $lines = [$this->boxHeader('Models', $width)];
        $items = $this->modelBrowserItems();
        $visibleCount = max(6, $height - 2);
        $maxIndex = max(0, count($items) - 1);
        $selectedIndex = min($this->fieldIndex, $maxIndex);
        $offset = max(0, $selectedIndex - (int) floor($visibleCount / 2));
        $window = array_slice($items, $offset, $visibleCount);
        $currentProvider = (string) ($this->values['agent.default_provider'] ?? '');
        $currentModel = (string) ($this->values['agent.default_model'] ?? '');

        foreach ($window as $relative => $item) {
            $absoluteIndex = $offset + $relative;
            $selected = $absoluteIndex === $selectedIndex;
            $cursor = $selected ? '› ' : '  ';

            if ($item['type'] === 'provider') {
                $label = $item['label'];
                $right = $item['provider'] === $currentProvider ? 'selected' : $item['description'];
                $color = $item['provider'] === $currentProvider ? Theme::accent() : Theme::text();
            } else {
                $label = '  '.$item['label'];
                $right = $item['provider'] === $currentProvider && $item['model'] === $currentModel ? 'default' : '';
                $color = $item['provider'] === $currentProvider && $item['model'] === $currentModel ? Theme::white() : Theme::dim();
            }

            $line = $this->formatEntryLine($cursor, $label, $right, max(8, $width - 2));
            $lines[] = $this->boxLine("{$color}{$line}".Theme::reset(), $width);
        }

        while (count($lines) < $height - 1) {
            $lines[] = $this->boxLine('', $width);
        }
        $lines[] = $this->boxFooter($width);

        return array_slice($lines, 0, $height);
    }

    /**
     * @return list<string>
     */
    private function renderProviderSetupBrowser(int $width, int $height): array
    {
        $lines = [$this->boxHeader('Provider Setup', $width)];
        $items = $this->providerSetupItems();
        $visibleCount = max(6, $height - 2);
        $maxIndex = max(0, count($items) - 1);
        $selectedIndex = min($this->fieldIndex, $maxIndex);
        $offset = max(0, $selectedIndex - (int) floor($visibleCount / 2));
        $window = array_slice($items, $offset, $visibleCount);
        $currentProvider = (string) ($this->values['provider.setup_provider'] ?? '');

        foreach ($window as $relative => $item) {
            $absoluteIndex = $offset + $relative;
            $selected = $absoluteIndex === $selectedIndex;
            $cursor = $selected ? '› ' : '  ';
            $right = $item['value'] === $currentProvider ? 'selected' : $item['auth_status'];
            $color = $item['value'] === $currentProvider ? Theme::accent() : Theme::text();
            $line = $this->formatEntryLine($cursor, $item['label'], $right, max(8, $width - 2));
            $lines[] = $this->boxLine("{$color}{$line}".Theme::reset(), $width);
        }

        while (count($lines) < $height - 1) {
            $lines[] = $this->boxLine('', $width);
        }
        $lines[] = $this->boxFooter($width);

        return array_slice($lines, 0, $height);
    }

    /**
     * @return list<string>
     */
    /** Render the details panel content for the models browser (provider/model info). */
    private function renderModelsDetails(int $width, int $height): array
    {
        $lines = [$this->boxHeader('Details', $width)];
        $items = $this->modelBrowserItems();
        $selected = $items[min($this->fieldIndex, max(0, count($items) - 1))] ?? null;
        $currentProvider = (string) ($this->values['agent.default_provider'] ?? '');
        $provider = (string) ($selected['provider'] ?? $currentProvider);
        $providerInfo = $this->view['providers_by_id'][$provider] ?? [];

        if ($selected !== null) {
            $lines[] = $this->boxLine(($selected['type'] === 'provider' ? 'Provider' : 'Model').': '.$selected['label'], $width, Theme::accent());
            $lines[] = $this->boxLine('', $width);

            foreach ($this->wrap($selected['description'], $width - 2) as $line) {
                $lines[] = $this->boxLine($line, $width);
            }
        }

        if ($provider !== '') {
            $lines[] = $this->boxLine('', $width);
            $lines[] = $this->boxLine('Provider', $width, Theme::accent());
            $lines[] = $this->boxLine('ID: '.$provider, $width);
            $lines[] = $this->boxLine('Auth: '.($providerInfo['auth_status'] ?? 'Unknown'), $width);
            $lines[] = $this->boxLine('Driver: '.($providerInfo['driver'] ?? 'unknown'), $width);
            $lines[] = $this->boxLine('Input: '.implode(', ', $providerInfo['input_modalities'] ?? ['text']), $width);
            $lines[] = $this->boxLine('Output: '.implode(', ', $providerInfo['output_modalities'] ?? ['text']), $width);
        }

        while (count($lines) < $height - 1) {
            $lines[] = $this->boxLine('', $width);
        }
        $lines[] = $this->boxFooter($width);

        return array_slice($lines, 0, $height);
    }

    /**
     * @return list<string>
     */
    private function renderProviderSetupBrowserDetails(int $width, int $height): array
    {
        $lines = [$this->boxHeader('Details', $width)];
        $items = $this->providerSetupItems();
        $selected = $items[min($this->fieldIndex, max(0, count($items) - 1))] ?? null;

        if ($selected !== null) {
            $lines[] = $this->boxLine($selected['label'], $width, Theme::accent());
            $lines[] = $this->boxLine('', $width);

            foreach ($this->wrap($selected['description'], $width - 2) as $line) {
                $lines[] = $this->boxLine($line, $width);
            }

            $lines[] = $this->boxLine('', $width);
            if ($selected['value'] === '__custom__') {
                $lines[] = $this->boxLine('Enter creates a new custom provider draft.', $width);
                $lines[] = $this->boxLine('New custom providers are prefilled as custom_1, custom_2, and so on.', $width);
            } else {
                $providerInfo = $this->view['providers_by_id'][$selected['value']] ?? [];
                $lines[] = $this->boxLine('Provider', $width, Theme::accent());
                $lines[] = $this->boxLine('ID: '.$selected['value'], $width);
                $lines[] = $this->boxLine('Auth: '.($providerInfo['auth_status'] ?? $selected['auth_status']), $width);
                $lines[] = $this->boxLine('Driver: '.($providerInfo['driver'] ?? 'unknown'), $width);
                $lines[] = $this->boxLine('Source: '.(($providerInfo['source'] ?? 'built_in') === 'custom' ? 'Custom YAML' : 'Built-in Relay'), $width);
                $lines[] = $this->boxLine('Enter opens setup for this provider.', $width);
            }
        }

        while (count($lines) < $height - 1) {
            $lines[] = $this->boxLine('', $width);
        }
        $lines[] = $this->boxFooter($width);

        return array_slice($lines, 0, $height);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    /** Open the inline picker overlay for a field, pre-selecting the current value. */
    private function openPickerForField(array $field): void
    {
        $options = $this->optionsForField($field);
        if ($options === []) {
            return;
        }

        $this->pickerOpen = true;
        $this->pickerFieldId = (string) ($field['id'] ?? '');
        $this->pickerOptions = $options;
        $this->pickerQuery = '';
        if ($this->pickerFieldId === 'agent.default_provider') {
            $this->pickerIndex = 0;
        } else {
            $current = $this->values[$this->pickerFieldId] ?? '';
            $index = array_search($current, array_map(static fn (array $item): string => $item['value'], $options), true);
            $this->pickerIndex = $index === false ? 0 : $index;
        }
        $this->invalidate();
    }

    /** Close the inline picker overlay and reset its state. */
    private function closePicker(): void
    {
        $this->pickerOpen = false;
        $this->pickerFieldId = '';
        $this->pickerOptions = [];
        $this->pickerIndex = 0;
        $this->pickerQuery = '';
        $this->invalidate();
    }

    /**
     * @return array{value: string, label: string, description: string}|null
     */
    private function selectedPickerOption(): ?array
    {
        return $this->visiblePickerOptions()[$this->pickerIndex] ?? null;
    }

    /**
     * @return list<string>
     */
    /** Render the inline picker overlay with filtered, scrollable options. */
    private function renderPicker(int $width, int $height): array
    {
        $field = $this->selectedField();
        $title = 'Select '.(string) ($field['label'] ?? 'Value');
        $lines = [$this->boxHeader($title, $width)];
        $options = $this->visiblePickerOptions();
        $visibleCount = max(6, $height - 2);
        $offset = max(0, $this->pickerIndex - (int) floor($visibleCount / 2));
        $window = array_slice($options, $offset, $visibleCount);

        if ($window === []) {
            $lines[] = $this->boxLine('No matches. Type to filter differently.', $width, Theme::warning());
        }

        foreach ($window as $relative => $option) {
            $absoluteIndex = $offset + $relative;
            $selected = $absoluteIndex === $this->pickerIndex;
            $cursor = $selected ? '› ' : '  ';
            $label = $option['label'] !== '' ? $option['label'] : $option['value'];
            $value = $option['value'];
            $right = $value !== $label ? $value : '';
            $color = $selected ? Theme::white() : Theme::text();
            $line = $this->formatEntryLine($cursor, $label, $right, max(8, $width - 2));
            $lines[] = $this->boxLine("{$color}{$line}".Theme::reset(), $width);
        }

        while (count($lines) < $height - 1) {
            $lines[] = $this->boxLine('', $width);
        }
        $lines[] = $this->boxFooter($width);

        return array_slice($lines, 0, $height);
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    /** Return picker options filtered by the current query string. */
    private function visiblePickerOptions(): array
    {
        if ($this->pickerQuery === '') {
            return $this->pickerOptions;
        }

        $needle = mb_strtolower($this->pickerQuery);

        return array_values(array_filter(
            $this->pickerOptions,
            static function (array $option) use ($needle): bool {
                $haystack = mb_strtolower($option['label'].' '.$option['value'].' '.$option['description']);

                return str_contains($haystack, $needle);
            },
        ));
    }

    /** Re-align picker index after query change, keeping current value selected if present. */
    private function resetPickerIndex(): void
    {
        $options = $this->visiblePickerOptions();
        if ($options === []) {
            $this->pickerIndex = 0;

            return;
        }

        $current = $this->pickerFieldId !== '' ? ($this->values[$this->pickerFieldId] ?? '') : '';
        $index = array_search($current, array_map(static fn (array $item): string => $item['value'], $options), true);
        $this->pickerIndex = $index === false ? 0 : $index;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    /** Render a human-readable display value for a field, resolving option labels. */
    private function displayValueForField(array $field): string
    {
        $id = (string) ($field['id'] ?? '');
        if ($id === 'provider.model_inventory') {
            $provider = (string) ($this->values['agent.default_provider'] ?? '');
            $count = count($this->view['model_options_by_provider'][$provider] ?? []);

            $freeTextProviders = $this->view['free_text_model_providers'] ?? [];
            if (in_array($provider, $freeTextProviders, true)) {
                return 'Any model (free-text entry)';
            }

            return $count > 0 ? $count.' models' : 'No models';
        }

        $value = (string) ($this->values[$id] ?? '');

        return $this->displayLabelForFieldValue($id, $value);
    }

    /** Resolve a raw field value to its display label using available options. */
    private function displayLabelForFieldValue(string $fieldId, string $value): string
    {
        if ($value === '') {
            return '';
        }

        $field = ['id' => $fieldId];
        foreach ($this->optionsForField($field) as $option) {
            if ($option['value'] !== $value) {
                continue;
            }

            if ($fieldId === 'agent.default_provider') {
                return $option['label'].' ('.$value.')';
            }

            if ($fieldId === 'provider.setup_provider') {
                return $option['label'];
            }

            if ($fieldId === 'agent.default_model' && $option['label'] !== $value) {
                return $option['label'];
            }

            return $option['label'] !== '' ? $option['label'] : $value;
        }

        return $value;
    }

    /** Keep the provider-setup browser highlight aligned with the selected provider. */
    private function syncProviderSetupListIndex(): void
    {
        $current = (string) ($this->values['provider.setup_provider'] ?? ($this->values['agent.default_provider'] ?? ''));
        $items = $this->providerSetupItems();
        $index = array_search($current, array_map(static fn (array $item): string => $item['value'], $items), true);
        $this->providerSetupListIndex = $index === false ? 0 : $index;

        if ($this->isProviderSetupCategory() && ! $this->providerSetupEditing) {
            $this->fieldIndex = $this->providerSetupListIndex;
        }
    }

    private function usesWideEditor(array $field): bool
    {
        $type = (string) ($field['type'] ?? 'text');
        if (! in_array($type, ['text', 'number'], true)) {
            return false;
        }

        $id = (string) ($field['id'] ?? '');

        return str_contains($id, 'api_key')
            || str_contains($id, '.url')
            || str_contains($id, '.modalities')
            || str_contains($id, '.label')
            || str_contains($id, '.model_id')
            || str_contains($id, '.default_model');
    }

    private function normalizeEditInput(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $text = str_replace(["\033[200~", "\033[201~"], '', $data);
        $text = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/u', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r", "\n"], '', $text);
        $text = str_replace("\t", ' ', $text);

        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text) ?? '';
    }

    /** Generate the next available default ID for a new custom provider draft. */
    private function nextCustomProviderId(): string
    {
        $existing = array_keys($this->view['custom_provider_definitions'] ?? []);
        $next = 1;

        while (in_array('custom_'.$next, $existing, true)) {
            $next++;
        }

        return 'custom_'.$next;
    }

    /** Render a box header line with a titled top border. */
    private function boxHeader(string $title, int $width): string
    {
        $border = Theme::borderAccent();
        $accent = Theme::accent();
        $r = Theme::reset();
        $inner = max(8, $width - 2);
        $label = " {$title} ";
        $label = $this->truncateVisible($label, max(1, $inner));
        $fill = max(0, $inner - mb_strwidth($label));

        return "{$border}┌{$accent}{$label}{$border}".str_repeat('─', $fill)."┐{$r}";
    }

    /** Render a single boxed content line with vertical borders and padding. */
    private function boxLine(string $content, int $width, string $color = ''): string
    {
        $border = Theme::borderTask();
        $r = Theme::reset();
        $inner = max(8, $width - 2);
        $text = $color !== '' ? $color.$content.$r : $content;
        $text = $this->truncateVisible($text, $inner);
        $visible = AnsiUtils::visibleWidth($text);
        $padding = max(0, $inner - $visible);

        return "{$border}│{$r}{$text}".str_repeat(' ', $padding)."{$border}│{$r}";
    }

    /** Render a box footer line (bottom border). */
    private function boxFooter(int $width): string
    {
        $border = Theme::borderTask();
        $r = Theme::reset();

        return "{$border}└".str_repeat('─', max(8, $width - 2))."┘{$r}";
    }

    /**
     * @return list<string>
     */
    /** Word-wrap plain text to the given visible width. */
    private function wrap(string $text, int $width): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/', $text) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (mb_strwidth($candidate) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function wrapForBox(string $text, int $width): array
    {
        $lines = [];

        foreach (preg_split("/\r\n|\r|\n/", $text) ?: [''] as $segment) {
            $segment = $segment === '' ? ' ' : $segment;

            while (mb_strwidth($segment) > $width) {
                $slice = mb_strimwidth($segment, 0, $width, '', 'UTF-8');
                $lines[] = $slice;
                $segment = mb_substr($segment, mb_strlen($slice));
            }

            $lines[] = $segment;
        }

        return $lines === [] ? [' '] : $lines;
    }
}

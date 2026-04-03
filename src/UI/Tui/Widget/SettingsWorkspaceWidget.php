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

final class SettingsWorkspaceWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private int $categoryIndex = 0;

    private int $fieldIndex = 0;

    private string $pane = 'fields';

    private bool $editing = false;

    private string $editBuffer = '';

    private string $scope;

    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, string> */
    private array $originalValues = [];

    /** @var callable(array<string, mixed>): void|null */
    private $onSaveCallback = null;

    /** @var callable(): void|null */
    private $onCancelCallback = null;

    private ?string $deleteCustomProviderId = null;

    /**
     * @param  array<string, mixed>  $view
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
    }

    public function onSave(callable $callback): static
    {
        $this->onSaveCallback = $callback;

        return $this;
    }

    public function onCancel(callable $callback): static
    {
        $this->onCancelCallback = $callback;

        return $this;
    }

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

            if ($data !== '' && ! str_starts_with($data, "\033")) {
                $this->editBuffer .= $data;
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'save') || $data === 's') {
            if ($this->onSaveCallback !== null) {
                ($this->onSaveCallback)($this->buildResult());
            }

            return;
        }

        if ($kb->matches($data, 'cancel') || $data === 'q') {
            if ($this->onCancelCallback !== null) {
                ($this->onCancelCallback)();
            }

            return;
        }

        if ($data === "\t") {
            $this->pane = $this->pane === 'categories' ? 'fields' : 'categories';
            $this->invalidate();

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
            $providerId = trim((string) ($this->values['agent.default_provider'] ?? ''));
            if ($providerId !== '' && (($this->view['providers_by_id'][$providerId]['source'] ?? '') === 'custom')) {
                $this->deleteCustomProviderId = $providerId;
                $this->invalidate();
            }

            return;
        }

        if ($this->pane === 'categories') {
            if ($kb->matches($data, 'up')) {
                $this->categoryIndex = ($this->categoryIndex - 1 + count($this->categories())) % count($this->categories());
                $this->fieldIndex = 0;
                $this->invalidate();

                return;
            }

            if ($kb->matches($data, 'down')) {
                $this->categoryIndex = ($this->categoryIndex + 1) % count($this->categories());
                $this->fieldIndex = 0;
                $this->invalidate();
            }

            if ($kb->matches($data, 'confirm')) {
                $this->pane = 'fields';
                $this->invalidate();
            }

            return;
        }

        $fields = $this->selectedCategory()['fields'] ?? [];
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

        if ($kb->matches($data, 'left')) {
            $this->cycleSelectedField(-1);

            return;
        }

        if ($kb->matches($data, 'right')) {
            $this->cycleSelectedField(1);

            return;
        }

        if ($kb->matches($data, 'confirm')) {
            $field = $this->selectedField();
            if ($field === null) {
                return;
            }

            if (in_array($field['type'] ?? 'text', ['choice', 'toggle', 'dynamic_choice'], true)) {
                $this->cycleSelectedField(1);

                return;
            }

            if (($field['type'] ?? 'text') !== 'readonly') {
                $this->editing = true;
                $this->editBuffer = $this->values[$field['id']] ?? '';
                $this->invalidate();
            }
        }
    }

    public function render(RenderContext $context): array
    {
        $columns = max(90, $context->getColumns());
        $rows = max(24, $context->getRows());

        $navWidth = 24;
        $detailsWidth = max(30, (int) floor($columns * 0.28));
        $fieldsWidth = $columns - $navWidth - $detailsWidth - 8;

        $headerLines = $this->renderHeader($columns);
        $bodyHeight = $rows - count($headerLines) - 4;

        $left = $this->renderCategories($navWidth, $bodyHeight);
        $middle = $this->renderFields($fieldsWidth, $bodyHeight);
        $right = $this->renderDetails($detailsWidth, $bodyHeight);

        $lines = $headerLines;

        for ($i = 0; $i < $bodyHeight; $i++) {
            $leftLine = $left[$i] ?? str_repeat(' ', $navWidth + 2);
            $middleLine = $middle[$i] ?? str_repeat(' ', $fieldsWidth + 2);
            $rightLine = $right[$i] ?? str_repeat(' ', $detailsWidth + 2);
            $lines[] = $leftLine.'  '.$middleLine.'  '.$rightLine;
        }

        $lines[] = '';
        $lines[] = $this->footer($columns);

        return array_map(
            fn (string $line): string => AnsiUtils::truncateToWidth($line, $context->getColumns()),
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
        $fields = $this->selectedCategory()['fields'] ?? [];

        return $fields[$this->fieldIndex] ?? null;
    }

    private function cycleSelectedField(int $direction): void
    {
        $field = $this->selectedField();
        if ($field === null) {
            return;
        }

        $options = $this->optionsForField($field);
        if ($options === []) {
            return;
        }

        $current = $this->values[$field['id']] ?? '';
        $index = array_search($current, $options, true);
        $index = $index === false ? 0 : $index;
        $index = ($index + $direction + count($options)) % count($options);
        $this->values[$field['id']] = $options[$index];
        $this->handleFieldSideEffects($field['id']);
        $this->invalidate();
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function optionsForField(array $field): array
    {
        $id = (string) ($field['id'] ?? '');
        if ($id === 'agent.default_model') {
            $provider = $this->values['agent.default_provider'] ?? '';

            return array_values(array_map(
                static fn (array $item): string => (string) $item['value'],
                $this->view['model_options_by_provider'][$provider] ?? [],
            ));
        }

        if ($id === 'provider.auth_action') {
            $provider = $this->values['agent.default_provider'] ?? '';

            return array_values(array_map(
                'strval',
                $this->view['auth_action_options_by_provider'][$provider] ?? [''],
            ));
        }

        $options = $field['options'] ?? [];
        if ($options === [] && $id === 'agent.default_provider') {
            $options = array_values(array_map(
                static fn (array $item): string => (string) $item['value'],
                $this->view['provider_options'] ?? [],
            ));
        }

        return array_values(array_map('strval', $options));
    }

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
                if ($models !== [] && ! in_array($this->values['agent.default_model'] ?? '', $models, true)) {
                    $this->values['agent.default_model'] = $models[0];
                }
            }
        }
    }

    private function jumpToProviderDraft(): void
    {
        foreach ($this->categories() as $index => $category) {
            if (($category['id'] ?? '') !== 'provider_model') {
                continue;
            }

            $this->categoryIndex = $index;
            $this->pane = 'fields';
            foreach (['custom_provider.id', 'custom_provider.label', 'custom_provider.driver', 'custom_provider.url', 'custom_provider.auth', 'custom_provider.default_model', 'custom_provider.model_id', 'custom_provider.context', 'custom_provider.max_output', 'custom_provider.input_modalities', 'custom_provider.output_modalities'] as $fieldId) {
                $this->values[$fieldId] = $fieldId === 'custom_provider.driver' ? 'openai-compatible' : ($fieldId === 'custom_provider.auth' ? 'api_key' : ($fieldId === 'custom_provider.input_modalities' || $fieldId === 'custom_provider.output_modalities' ? 'text' : ''));
            }
            $this->fieldIndex = 2;
            $this->deleteCustomProviderId = null;
            $this->invalidate();

            return;
        }
    }

    /**
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
     * @param  string  $csv
     * @return list<string>
     */
    private function csvValues(string $csv): array
    {
        $parts = array_map('trim', explode(',', $csv));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
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

        return [
            "{$accent}⚙ Settings{$r}  {$dim}scope{$r}: {$white}{$this->scope}{$r}  {$dim}provider{$r}: {$white}{$provider}{$r}  {$dim}model{$r}: {$white}{$model}{$r}  {$status}{$unsaved}{$r}",
            "{$dim}Separate settings workspace. Save writes YAML-backed config; auth secrets remain managed separately.{$r}",
            str_repeat('─', max(10, $width - 2)),
        ];
    }

    /**
     * @return list<string>
     */
    private function renderCategories(int $width, int $height): array
    {
        $lines = [$this->boxHeader('Categories', $width)];
        foreach ($this->categories() as $index => $category) {
            $selected = $index === $this->categoryIndex;
            $focused = $selected && $this->pane === 'categories';
            $prefix = $focused ? '› ' : ($selected ? '• ' : '  ');
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
    private function renderFields(int $width, int $height): array
    {
        $category = $this->selectedCategory();
        $lines = [$this->boxHeader($category['label'], $width)];
        $fields = $category['fields'] ?? [];

        $visibleCount = max(6, $height - 2);
        $offset = max(0, $this->fieldIndex - (int) floor($visibleCount / 2));
        $window = array_slice($fields, $offset, $visibleCount);

        foreach ($window as $relative => $field) {
            $absoluteIndex = $offset + $relative;
            $selected = $absoluteIndex === $this->fieldIndex;
            $focused = $selected && $this->pane === 'fields';
            $cursor = $focused ? '› ' : ($selected ? '• ' : '  ');
            $label = (string) ($field['label'] ?? $field['id']);
            $value = $selected && $this->editing
                ? $this->editBuffer.'▏'
                : ($this->values[$field['id']] ?? '');
            $color = $selected ? Theme::white() : Theme::text();
            $line = sprintf('%s%s%s%s', $cursor, $label, str_repeat(' ', max(1, $width - mb_strwidth($label) - mb_strwidth($value) - 6)), $value);
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
    private function renderDetails(int $width, int $height): array
    {
        $lines = [$this->boxHeader('Details', $width)];
        $field = $this->selectedField();
        $provider = $this->values['agent.default_provider'] ?? '';
        $providerInfo = $this->view['providers_by_id'][$provider] ?? [];

        if ($field !== null) {
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

        $yaml = $this->buildYamlPreview();
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
            "      label: ".($definition['label'] !== '' ? $definition['label'] : $id),
            "      driver: {$definition['driver']}",
            "      auth: {$definition['auth']}",
            "      url: {$definition['url']}",
            "      default_model: {$definition['default_model']}",
            '      modalities:',
            '        input: ['.implode(', ', $definition['modalities']['input']).']',
            '        output: ['.implode(', ', $definition['modalities']['output']).']',
        ];
    }

    private function footer(int $width): string
    {
        $dim = Theme::dim();
        $r = Theme::reset();

        return AnsiUtils::truncateToWidth(
            "{$dim}Tab pane  ↑↓ navigate  ←→ cycle  Enter edit  s save  q cancel  g/p scope  r reset  a new provider  x remove custom{$r}",
            $width,
        );
    }

    private function boxHeader(string $title, int $width): string
    {
        $border = Theme::borderAccent();
        $accent = Theme::accent();
        $r = Theme::reset();
        $inner = max(8, $width - 2);
        $label = " {$title} ";
        $fill = max(0, $inner - mb_strwidth($label));

        return "{$border}┌{$accent}{$label}{$border}".str_repeat('─', $fill)."┐{$r}";
    }

    private function boxLine(string $content, int $width, string $color = ''): string
    {
        $border = Theme::borderTask();
        $r = Theme::reset();
        $inner = max(8, $width - 2);
        $text = $color !== '' ? $color.$content.$r : $content;
        $visible = AnsiUtils::visibleWidth($content);
        $padding = max(0, $inner - $visible);

        return "{$border}│{$r}{$text}".str_repeat(' ', $padding)."{$border}│{$r}";
    }

    private function boxFooter(int $width): string
    {
        $border = Theme::borderTask();
        $r = Theme::reset();

        return "{$border}└".str_repeat('─', max(8, $width - 2))."┘{$r}";
    }

    /**
     * @return list<string>
     */
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
}

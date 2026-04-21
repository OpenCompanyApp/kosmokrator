<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\UI\DialogRendererInterface;
use Kosmokrator\UI\Theme;

/**
 * ANSI fallback implementation of interactive dialog methods.
 *
 * Handles settings, session picker, plan approval, user questions,
 * and multiple-choice prompts via readline.
 */
final class AnsiDialogRenderer implements DialogRendererInterface
{
    /** @var \Closure(string, string, bool, bool): void */
    private \Closure $queueQuestionRecapCallback;

    public function __construct(\Closure $queueQuestionRecapCallback)
    {
        $this->queueQuestionRecapCallback = $queueQuestionRecapCallback;
    }

    public function showSettings(array $currentSettings): array
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $accent = Theme::warning();
        $white = "\033[1;37m";
        $title = (string) ($currentSettings['title'] ?? 'Settings');

        echo "\n{$accent}  ⚙ {$title}{$r}\n";
        echo "{$dim}  Separate settings workspace. Press Enter to keep a value unchanged.{$r}\n\n";

        $defaultScope = (string) ($currentSettings['scope'] ?? 'project');
        $scope = strtolower(trim($this->prompt("  Scope [project/global, default {$defaultScope}]: ")));
        $scope = $scope === '' ? $defaultScope : ($scope === 'global' ? 'global' : 'project');

        $changes = [];
        $categories = $this->orderedCategories($currentSettings);

        foreach ($categories as $category) {
            echo "{$white}  {$category['label']}{$r}\n";

            foreach ($category['fields'] ?? [] as $field) {
                $id = (string) ($field['id'] ?? '');
                $type = (string) ($field['type'] ?? 'text');
                $current = $this->stringifySettingValue($field['value'] ?? '');

                if ($type === 'readonly') {
                    echo "{$dim}    {$field['label']}: {$current}{$r}\n";

                    continue;
                }

                $hint = '';
                $options = $this->stringifySettingOptions($field['options'] ?? []);
                if ($options !== []) {
                    $hint = ' ['.implode('/', $options).']';
                }

                $answer = $this->prompt("  {$field['label']}{$hint} [{$current}]: ");
                $answer = trim($answer);

                if ($answer === '') {
                    continue;
                }

                $changes[$id] = $answer;
            }

            echo "\n";
        }

        $customProvider = null;
        $customProviderId = trim((string) ($changes['custom_provider.id'] ?? ''));
        $customModelId = trim((string) ($changes['custom_provider.model_id'] ?? ''));
        if ($customProviderId !== '' && $customModelId !== '') {
            $customProvider = [
                'id' => $customProviderId,
                'definition' => [
                    'label' => (string) ($changes['custom_provider.label'] ?? $currentSettings['custom_provider_definitions'][$customProviderId]['label'] ?? ''),
                    'driver' => (string) ($changes['custom_provider.driver'] ?? 'openai-compatible'),
                    'auth' => (string) ($changes['custom_provider.auth'] ?? 'api_key'),
                    'url' => (string) ($changes['custom_provider.url'] ?? ''),
                    'default_model' => (string) ($changes['custom_provider.default_model'] ?? $customModelId),
                    'modalities' => [
                        'input' => array_values(array_filter(array_map('trim', explode(',', (string) ($changes['custom_provider.input_modalities'] ?? 'text'))))),
                        'output' => array_values(array_filter(array_map('trim', explode(',', (string) ($changes['custom_provider.output_modalities'] ?? 'text'))))),
                    ],
                    'models' => [
                        $customModelId => [
                            'display_name' => (string) ($changes['custom_provider.label'] ?? $customModelId),
                            'context' => (int) ($changes['custom_provider.context'] ?? 0),
                            'max_output' => (int) ($changes['custom_provider.max_output'] ?? 0),
                            'modalities' => [
                                'input' => array_values(array_filter(array_map('trim', explode(',', (string) ($changes['custom_provider.input_modalities'] ?? 'text'))))),
                                'output' => array_values(array_filter(array_map('trim', explode(',', (string) ($changes['custom_provider.output_modalities'] ?? 'text'))))),
                            ],
                        ],
                    ],
                ],
            ];
        }

        return [
            'scope' => $scope,
            'changes' => $changes,
            'custom_provider' => $customProvider,
            'delete_custom_provider' => null,
        ];
    }

    public function pickSession(array $items): ?string
    {
        if ($items === []) {
            return null;
        }

        if (! defined('STDIN') || ! posix_isatty(STDIN)) {
            return $this->pickSessionReadline($items);
        }

        $maxVisible = 8;
        $selected = 0;
        $total = count($items);

        $originalTty = trim((string) shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty -icanon -echo 2>/dev/null');
        echo Theme::hideCursor();

        $restoreTerminal = static function () use ($originalTty): void {
            echo Theme::showCursor();
            shell_exec('stty '.escapeshellarg($originalTty).' 2>/dev/null');
        };
        register_shutdown_function($restoreTerminal);

        $renderedLines = 0;

        try {
            $renderedLines = $this->renderSessionFrame($items, $selected, $maxVisible);

            while (true) {
                $input = fread(STDIN, 8);
                if ($input === false || $input === '') {
                    continue;
                }

                $prev = $selected;

                if ($input === "\033[A") { // Up
                    $selected = $selected > 0 ? $selected - 1 : $total - 1;
                } elseif ($input === "\033[B") { // Down
                    $selected = $selected < $total - 1 ? $selected + 1 : 0;
                } elseif ($input === "\033[5~") { // PageUp
                    $selected = max(0, $selected - $maxVisible);
                } elseif ($input === "\033[6~") { // PageDown
                    $selected = min($total - 1, $selected + $maxVisible);
                } elseif ($input === "\n" || $input === "\r") {
                    return $items[$selected]['value'];
                } elseif ($input === "\033" || $input === "\x03" || $input === 'q') {
                    return null;
                } else {
                    continue;
                }

                if ($prev !== $selected) {
                    // Move cursor up to overwrite previous frame
                    echo "\033[{$renderedLines}A";
                    $renderedLines = $this->renderSessionFrame($items, $selected, $maxVisible);
                }
            }
        } finally {
            // Clear the frame before restoring
            if ($renderedLines > 0) {
                echo "\033[{$renderedLines}A";
                for ($i = 0; $i < $renderedLines; $i++) {
                    echo "\033[2K\n";
                }
                echo "\033[{$renderedLines}A";
            }
            $restoreTerminal();
        }
    }

    /**
     * Render the scrollable session picker frame and return line count.
     *
     * @param  array<array{value: string, label: string, description?: string}>  $items
     */
    private function renderSessionFrame(array $items, int $selected, int $maxVisible): int
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $accent = Theme::accent();
        $white = "\033[1;37m";
        $total = count($items);
        $project = basename(getcwd());

        $offset = max(0, min($selected - (int) floor($maxVisible / 2), $total - $maxVisible));
        $end = min($offset + $maxVisible, $total);
        $lines = 0;

        echo "\033[2K{$white}  Sessions ({$project}):{$r}\n";
        $lines++;

        for ($i = $offset; $i < $end; $i++) {
            $item = $items[$i];
            $label = $item['label'];
            $desc = $item['description'] ?? '';

            if ($i === $selected) {
                echo "\033[2K{$accent}  → {$label}  {$dim}{$desc}{$r}\n";
            } else {
                echo "\033[2K{$dim}    {$r}{$label}  {$dim}{$desc}{$r}\n";
            }
            $lines++;
        }

        $nav = '↑↓ navigate  Enter select  Esc cancel';
        echo "\033[2K{$dim}  (".($selected + 1)."/{$total})  {$nav}{$r}\n";
        $lines++;

        return $lines;
    }

    /**
     * Fallback numbered-list picker for non-TTY environments.
     *
     * @param  array<array{value: string, label: string, description?: string}>  $items
     */
    private function pickSessionReadline(array $items): ?string
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $white = "\033[1;37m";

        echo "\n{$white}  Select a session:{$r}\n";
        foreach ($items as $i => $item) {
            $num = $i + 1;
            $desc = $item['description'] ?? '';
            echo "{$dim}  [{$num}] {$white}{$item['label']}{$r}  {$dim}{$desc}{$r}\n";
        }
        echo "{$dim}  [0] Cancel{$r}\n";

        $choice = (int) $this->prompt('  > ');
        if ($choice < 1 || $choice > count($items)) {
            return null;
        }

        return $items[$choice - 1]['value'];
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $gold = Theme::accent();
        $white = "\033[1;37m";
        $border = Theme::borderTask();

        $permissions = [
            'g' => ['id' => 'guardian', 'label' => 'Guardian ◈'],
            'a' => ['id' => 'argus', 'label' => 'Argus ◉'],
            'p' => ['id' => 'prometheus', 'label' => 'Prometheus ⚡'],
        ];

        $contexts = [
            'k' => ['id' => 'keep', 'label' => 'keep context'],
            'c' => ['id' => 'compact', 'label' => 'compact'],
            'r' => ['id' => 'clear', 'label' => 'clear'],
        ];

        $permissionId = $currentPermissionMode;
        $contextId = 'keep';

        echo "\n{$border}  ┌ {$gold}Plan Complete{$r}\n";
        echo "{$border}  │{$r}\n";

        // Permission selection
        $permHints = [];
        foreach ($permissions as $key => $perm) {
            $marker = $perm['id'] === $permissionId ? $white : $dim;
            $permHints[] = "[{$key}]{$marker}{$perm['label']}{$r}";
        }
        echo "{$border}  │{$r} {$dim}Permission:{$r} ".implode("{$dim} · {$r}", $permHints)."\n";

        // Context selection
        $ctxHints = [];
        foreach ($contexts as $key => $ctx) {
            $marker = $ctx['id'] === $contextId ? $white : $dim;
            $ctxHints[] = "[{$key}]{$marker}{$ctx['label']}{$r}";
        }
        echo "{$border}  │{$r} {$dim}Context:   {$r} ".implode("{$dim} · {$r}", $ctxHints)."\n";
        echo "{$border}  │{$r}\n";

        while (true) {
            $answer = $this->prompt("{$border}  └ {$gold}Enter{$r}{$dim} implement / {$r}{$gold}d{$r}{$dim} dismiss ▸{$r} ");

            if ($answer === false) {
                return null;
            }

            $char = strtolower(trim($answer));

            // Accept with defaults
            if ($char === '' || $char === 'i') {
                return ['permission' => $permissionId, 'context' => $contextId];
            }

            // Dismiss
            if ($char === 'd') {
                return null;
            }

            // Permission change
            if (isset($permissions[$char])) {
                $permissionId = $permissions[$char]['id'];

                return ['permission' => $permissionId, 'context' => $contextId];
            }

            // Context change
            if (isset($contexts[$char])) {
                $contextId = $contexts[$char]['id'];

                return ['permission' => $permissionId, 'context' => $contextId];
            }
        }
    }

    public function askUser(string $question): string
    {
        $r = Theme::reset();
        $accent = Theme::accent();
        echo "\n{$accent}?{$r} {$question}\n";
        $answer = $this->prompt('> ');
        $trimmed = trim($answer);

        ($this->queueQuestionRecapCallback)($question, $trimmed, $trimmed !== '', false);

        return $answer;
    }

    public function askChoice(string $question, array $choices): string
    {
        $r = Theme::reset();
        $accent = Theme::accent();
        $dim = Theme::dim();

        echo "\n{$accent}?{$r} {$question}\n";
        foreach ($choices as $i => $choice) {
            echo "  {$accent}".($i + 1).".{$r} {$choice['label']}\n";
            if ($choice['detail'] !== null) {
                echo "{$dim}{$choice['detail']}{$r}\n";
            }
        }
        echo "  {$dim}".(count($choices) + 1).". Dismiss{$r}\n";

        $pick = (int) $this->prompt("{$dim}>{$r} ");
        if ($pick >= 1 && $pick <= count($choices)) {
            $choice = $choices[$pick - 1];
            ($this->queueQuestionRecapCallback)($question, $choice['label'], true, (bool) ($choice['recommended'] ?? false));

            return $choice['label'];
        }

        ($this->queueQuestionRecapCallback)($question, '', false, false);

        return 'dismissed';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderedCategories(array $currentSettings): array
    {
        $categories = is_array($currentSettings['categories'] ?? null) ? $currentSettings['categories'] : [];
        $initialCategory = trim((string) ($currentSettings['initial_category'] ?? ''));
        if ($initialCategory === '') {
            return $categories;
        }

        usort($categories, static function (array $a, array $b) use ($initialCategory): int {
            $aId = (string) ($a['id'] ?? '');
            $bId = (string) ($b['id'] ?? '');

            if ($aId === $initialCategory) {
                return -1;
            }

            if ($bId === $initialCategory) {
                return 1;
            }

            return 0;
        });

        return $categories;
    }

    private function prompt(string $message): string
    {
        if (\function_exists('readline')) {
            $input = \readline($message);

            return $input === false ? '' : $input;
        }

        echo $message;
        $input = fgets(STDIN);

        return $input === false ? '' : rtrim($input, "\r\n");
    }

    private function stringifySettingValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $parts[] = (string) $item;
                }
            }

            return implode(', ', $parts);
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function stringifySettingOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $labels = [];

        foreach ($options as $option) {
            if (is_scalar($option)) {
                $labels[] = (string) $option;

                continue;
            }

            if (is_array($option)) {
                $label = $option['label'] ?? $option['value'] ?? null;
                if (is_scalar($label)) {
                    $labels[] = (string) $label;
                }
            }
        }

        return $labels;
    }
}

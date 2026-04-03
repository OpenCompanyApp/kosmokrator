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
    /** @var \Closure(string $question, string $answer, bool $answered, bool $recommended): void */
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

        echo "\n{$accent}  ⚙ Settings{$r}\n";
        echo "{$dim}  Separate settings workspace. Press Enter to keep a value unchanged.{$r}\n\n";

        $scope = strtolower(trim(readline('  Scope [project/global, default project]: ')));
        $scope = $scope === 'global' ? 'global' : 'project';

        $changes = [];
        $categories = is_array($currentSettings['categories'] ?? null) ? $currentSettings['categories'] : [];

        foreach ($categories as $category) {
            echo "{$white}  {$category['label']}{$r}\n";

            foreach ($category['fields'] ?? [] as $field) {
                $id = (string) ($field['id'] ?? '');
                $type = (string) ($field['type'] ?? 'text');
                $current = (string) ($field['value'] ?? '');

                if ($type === 'readonly') {
                    echo "{$dim}    {$field['label']}: {$current}{$r}\n";

                    continue;
                }

                $hint = '';
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                if ($options !== []) {
                    $hint = ' ['.implode('/', $options).']';
                }

                $answer = readline("  {$field['label']}{$hint} [{$current}]: ");
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

        $choice = (int) readline('  > ');
        if ($choice < 1 || $choice > count($items)) {
            return null;
        }

        return $items[$choice - 1]['value'];
    }

    /** No-op: ANSI mode has no interactive plan approval dialog. */
    public function approvePlan(string $currentPermissionMode): ?array
    {
        return null;
    }

    public function askUser(string $question): string
    {
        $r = Theme::reset();
        $accent = Theme::accent();
        echo "\n{$accent}?{$r} {$question}\n";
        $answer = readline('> ') ?: '';
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

        $pick = (int) readline("{$dim}>{$r} ");
        if ($pick >= 1 && $pick <= count($choices)) {
            $choice = $choices[$pick - 1];
            ($this->queueQuestionRecapCallback)($question, $choice['label'], true, (bool) ($choice['recommended'] ?? false));

            return $choice['label'];
        }

        ($this->queueQuestionRecapCallback)($question, '', false, false);

        return 'dismissed';
    }
}

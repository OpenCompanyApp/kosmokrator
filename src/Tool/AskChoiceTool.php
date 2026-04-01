<?php

declare(strict_types=1);

namespace Kosmokrator\Tool;

use Kosmokrator\UI\RendererInterface;

class AskChoiceTool implements ToolInterface
{
    public function __construct(
        private readonly RendererInterface $ui,
    ) {}

    public function name(): string
    {
        return 'ask_choice';
    }

    public function description(): string
    {
        return 'Present multiple-choice options to the user. Each choice can have an optional detail/mockup shown when selected and an optional recommended flag for transcript styling. Returns the selected option text or "dismissed" if the user cancels. A dismiss option is always appended.';
    }

    public function parameters(): array
    {
        return [
            'question' => ['type' => 'string', 'description' => 'The question or prompt to display'],
            'choices' => ['type' => 'string', 'description' => 'JSON array of choice objects: [{"label": "Option A", "detail": "ASCII art or text shown when this option is highlighted", "recommended": true}, ...]. The "detail" and "recommended" fields are optional. Simple strings are also accepted: ["Option A", "Option B"].'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['question', 'choices'];
    }

    public function execute(array $args): ToolResult
    {
        $question = $args['question'] ?? '';
        $choicesJson = $args['choices'] ?? '[]';

        $raw = json_decode($choicesJson, true);
        if (! is_array($raw) || $raw === []) {
            return ToolResult::error('choices must be a non-empty JSON array');
        }

        // Normalize: accept simple strings or {label, detail, recommended} objects
        $choices = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $choices[] = ['label' => $item, 'detail' => null, 'recommended' => false];
            } elseif (is_array($item) && isset($item['label'])) {
                $choices[] = [
                    'label' => (string) $item['label'],
                    'detail' => isset($item['detail']) ? (string) $item['detail'] : null,
                    'recommended' => (bool) ($item['recommended'] ?? false),
                ];
            }
        }

        if ($choices === []) {
            return ToolResult::error('choices must contain at least one valid option');
        }

        $answer = $this->ui->askChoice($question, $choices);

        return ToolResult::success($answer);
    }
}

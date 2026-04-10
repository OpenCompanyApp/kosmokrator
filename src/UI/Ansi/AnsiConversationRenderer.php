<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Kosmokrator\LLM\ToolCallMapper;
use Kosmokrator\UI\ConversationRendererInterface;
use Kosmokrator\UI\Theme;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * ANSI fallback implementation of conversation history display.
 *
 * Handles clearing and replaying conversation context.
 */
final class AnsiConversationRenderer implements ConversationRendererInterface
{
    private ?MarkdownToAnsi $markdownRenderer = null;

    /** @var \Closure(): void */
    private \Closure $flushQuestionRecapCallback;

    /** @var \Closure(): void */
    private \Closure $clearQuestionRecapCallback;

    /** @var \Closure(string, string, bool, bool): void */
    private \Closure $queueQuestionRecapCallback;

    private AnsiToolRenderer $toolRenderer;

    public function __construct(
        AnsiToolRenderer $toolRenderer,
        \Closure $flushQuestionRecapCallback,
        \Closure $clearQuestionRecapCallback,
        \Closure $queueQuestionRecapCallback,
    ) {
        $this->toolRenderer = $toolRenderer;
        $this->flushQuestionRecapCallback = $flushQuestionRecapCallback;
        $this->clearQuestionRecapCallback = $clearQuestionRecapCallback;
        $this->queueQuestionRecapCallback = $queueQuestionRecapCallback;
    }

    public function clearConversation(): void
    {
        ($this->clearQuestionRecapCallback)();
    }

    public function replayHistory(array $messages): void
    {
        ($this->clearQuestionRecapCallback)();
        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();
        $gold = Theme::accent();
        $border = Theme::borderTask();

        // Index tool results by toolCallId for pairing
        $resultsByCallId = [];
        foreach ($messages as $msg) {
            if ($msg instanceof ToolResultMessage) {
                foreach ($msg->toolResults as $toolResult) {
                    $resultsByCallId[$toolResult->toolCallId] = $toolResult;
                }
            }
        }

        foreach ($messages as $msg) {
            if ($msg instanceof SystemMessage
                || $msg instanceof ToolResultMessage) {
                continue;
            }

            if ($msg instanceof UserMessage) {
                ($this->flushQuestionRecapCallback)();
                echo "\n  {$white}⟡ {$msg->content}{$r}\n";

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                if ($msg->content !== '') {
                    ($this->flushQuestionRecapCallback)();
                    if (str_contains($msg->content, "\x1b[")) {
                        echo "\n".$msg->content.$r."\n";
                    } else {
                        echo $this->getMarkdownRenderer()->render($msg->content);
                    }
                }

                foreach ($msg->toolCalls as $toolCall) {
                    $name = $toolCall->name;
                    $args = ToolCallMapper::safeArguments($toolCall);
                    $toolResult = $resultsByCallId[$toolCall->id] ?? null;

                    if ($name === 'ask_user') {
                        $answer = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE))
                            : '';
                        $trimmed = trim($answer);

                        ($this->queueQuestionRecapCallback)(
                            (string) ($args['question'] ?? ''),
                            $trimmed,
                            $trimmed !== '',
                            false,
                        );

                        continue;
                    }

                    if ($name === 'ask_choice') {
                        $answer = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE))
                            : 'dismissed';
                        $selected = $this->findChoiceFromArgs($args, $answer);

                        ($this->queueQuestionRecapCallback)(
                            (string) ($args['question'] ?? ''),
                            $answer === 'dismissed' ? '' : $answer,
                            $answer !== 'dismissed',
                            (bool) ($selected['recommended'] ?? false),
                        );

                        continue;
                    }

                    if ($this->isTaskTool($name)) {
                        if ($name === 'task_create') {
                            $icon = Theme::toolIcon($name);
                            $friendly = Theme::toolLabel($name);
                            $label = $this->formatTaskToolCallLabel($name, $args, $icon, $friendly, $dim, $r);
                            if ($label !== null) {
                                echo "{$border}  ┃ {$gold}{$label}{$r}\n";
                            }
                        }

                        continue;
                    }

                    // Render tool call
                    ($this->flushQuestionRecapCallback)();
                    $this->toolRenderer->setLastToolArgs($args);
                    $this->toolRenderer->showToolCall($name, $args);

                    // Render paired result immediately after
                    if ($toolResult !== null) {
                        $this->toolRenderer->setLastToolArgs($toolResult->args);
                        $output = is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE);
                        $this->toolRenderer->showToolResult($name, $output, true);
                    }
                }

                continue;
            }
        }
        ($this->flushQuestionRecapCallback)();
        echo "\n";
    }

    private function isTaskTool(string $name): bool
    {
        return in_array($name, ['task_create', 'task_update', 'task_list', 'task_get'], true);
    }

    private function getMarkdownRenderer(): MarkdownToAnsi
    {
        return $this->markdownRenderer ??= new MarkdownToAnsi;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{label: string, detail: string|null, recommended?: bool}|null
     */
    private function findChoiceFromArgs(array $args, string $label): ?array
    {
        $raw = json_decode((string) ($args['choices'] ?? '[]'), true);
        if (! is_array($raw)) {
            return null;
        }

        $choices = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $choices[] = ['label' => $item, 'detail' => null, 'recommended' => false];

                continue;
            }

            if (! is_array($item) || ! isset($item['label'])) {
                continue;
            }

            $choices[] = [
                'label' => (string) $item['label'],
                'detail' => isset($item['detail']) ? (string) $item['detail'] : null,
                'recommended' => (bool) ($item['recommended'] ?? false),
            ];
        }

        foreach ($choices as $choice) {
            if ($choice['label'] === $label) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * Format task tool call label. Returns null to suppress output entirely.
     */
    private function formatTaskToolCallLabel(string $name, array $args, string $icon, string $friendly, string $dim, string $r): ?string
    {
        $white = Theme::white();

        if ($name === 'task_create') {
            if (isset($args['tasks']) && $args['tasks'] !== '') {
                $items = json_decode($args['tasks'], true);
                if (is_array($items)) {
                    return "{$icon} {$friendly} {$dim}created ".count($items)." tasks{$r}";
                }
            }
            $subject = $args['subject'] ?? '';

            return "{$icon} {$friendly} {$white}{$subject}{$r}";
        }

        return null;
    }
}

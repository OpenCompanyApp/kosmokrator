<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Kosmokrator\LLM\ToolCallMapper;
use Kosmokrator\UI\ConversationRendererInterface;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\Tui\Widget\AnsiArtWidget;
use Kosmokrator\UI\Tui\Widget\BashCommandWidget;
use Kosmokrator\UI\Tui\Widget\CollapsibleWidget;
use Kosmokrator\UI\Tui\Widget\DiscoveryBatchWidget;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * TUI implementation of conversation history display and clearing.
 *
 * Delegates widget manipulation to TuiCoreRenderer and tool-specific
 * rendering logic to TuiToolRenderer.
 */
final class TuiConversationRenderer implements ConversationRendererInterface
{
    public function __construct(
        private readonly TuiCoreRenderer $core,
        private readonly TuiToolRenderer $tool,
    ) {}

    public function clearConversation(): void
    {
        $this->core->clearConversationState();
        $this->tool->resetActiveBashWidget();
        $this->tool->finalizeDiscoveryBatch();
        $this->core->flushRender();
    }

    public function replayHistory(array $messages): void
    {
        $this->tool->resetActiveBashWidget();
        $this->core->clearPendingQuestionRecap();
        $this->tool->finalizeDiscoveryBatch();

        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();
        $text = Theme::text();

        // Index tool results by toolCallId for pairing with tool calls
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
                $this->core->flushPendingQuestionRecap();
                $widget = new TextWidget('⟡ '.$msg->content);
                $widget->addStyleClass('user-message');
                $this->core->addConversationWidget($widget);

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                $discoveryGroup = [];
                $flushDiscoveryGroup = function () use (&$discoveryGroup): void {
                    if ($discoveryGroup === []) {
                        return;
                    }

                    $widget = new DiscoveryBatchWidget($discoveryGroup);
                    $widget->addStyleClass('tool-batch');
                    $this->core->addConversationWidget($widget);
                    $discoveryGroup = [];
                };

                // Text content
                if ($msg->content !== '') {
                    $this->core->flushPendingQuestionRecap();
                    if ($this->containsAnsiEscapes($msg->content)) {
                        $widget = new AnsiArtWidget($msg->content);
                        $widget->addStyleClass('ansi-art');
                    } else {
                        $widget = new MarkdownWidget($msg->content);
                        $widget->addStyleClass('response');
                    }
                    $this->core->addConversationWidget($widget);
                }

                // Tool calls — each paired with its result
                foreach ($msg->toolCalls as $toolCall) {
                    $name = $toolCall->name;
                    $args = ToolCallMapper::safeArguments($toolCall);
                    $toolResult = $resultsByCallId[$toolCall->id] ?? null;

                    if ($name === 'ask_user') {
                        $flushDiscoveryGroup();
                        $answer = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE))
                            : '';
                        $trimmed = trim($answer);

                        $this->core->queueQuestionRecap(
                            question: (string) ($args['question'] ?? ''),
                            answer: $trimmed,
                            answered: $trimmed !== '',
                        );

                        continue;
                    }

                    if ($name === 'ask_choice') {
                        $flushDiscoveryGroup();
                        $answer = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE))
                            : 'dismissed';
                        $selected = $this->findChoiceFromArgs($args, $answer);

                        $this->core->queueQuestionRecap(
                            question: (string) ($args['question'] ?? ''),
                            answer: $answer === 'dismissed' ? '' : $answer,
                            answered: $answer !== 'dismissed',
                            recommended: (bool) ($selected['recommended'] ?? false),
                        );

                        continue;
                    }

                    // Task tools: skip — task bar shows the tree
                    if ($this->tool->isTaskTool($name)) {
                        $flushDiscoveryGroup();

                        continue;
                    }

                    $this->core->flushPendingQuestionRecap();

                    if ($this->tool->isOmensTool($name, $args)) {
                        $output = $toolResult !== null
                            ? (is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE))
                            : '';
                        $discoveryGroup[] = $this->tool->buildDiscoveryItem(
                            $name,
                            $args,
                            $output,
                            $toolResult !== null,
                            $this->tool->inferHistoricToolSuccess($name, $toolResult),
                        );

                        continue;
                    }

                    $flushDiscoveryGroup();

                    if ($name === 'bash') {
                        $bashWidget = new BashCommandWidget((string) ($args['command'] ?? ''));
                        $bashWidget->addStyleClass('tool-shell');
                        if ($toolResult !== null) {
                            $output = is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE);
                            $bashWidget->setResult($output, $this->tool->inferHistoricToolSuccess($name, $toolResult));
                        }
                        $this->core->addConversationWidget($bashWidget);

                        continue;
                    }

                    // Render tool call line
                    $icon = Theme::toolIcon($name);
                    $friendly = Theme::toolLabel($name);

                    if (in_array($name, ['file_read', 'file_write', 'file_edit']) && isset($args['path'])) {
                        $path = Theme::relativePath($args['path']);
                        $label = "{$icon} {$friendly}  {$path}";
                        if (isset($args['offset'])) {
                            $label .= ":{$args['offset']}";
                        }
                    } else {
                        $skipKeys = ['content', 'old_string', 'new_string'];
                        $parts = [];
                        foreach ($args as $key => $value) {
                            if (in_array($key, $skipKeys, true)) {
                                continue;
                            }
                            $display = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                            $parts[] = "{$key}: {$display}";
                        }
                        $label = "{$icon} {$friendly}  ".implode('  ', $parts);
                    }

                    $maxWidth = 120;
                    if (mb_strlen($label) > $maxWidth) {
                        $header = "{$icon} {$friendly}";
                        $argsStr = mb_substr($label, mb_strlen($header) + 2);
                        $w = new CollapsibleWidget($header, $argsStr, 1, $maxWidth);
                        $w->addStyleClass('tool-call');
                    } else {
                        $w = new TextWidget($label);
                        $w->addStyleClass('tool-call');
                    }
                    $this->core->addConversationWidget($w);

                    // Render paired result immediately after the call
                    if ($toolResult !== null) {
                        $this->tool->setLastToolArgs($toolResult->args);
                        $output = is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE);
                        $statusColor = Theme::success();
                        $resultHeader = "{$statusColor}✓{$r}";

                        if ($name === 'file_edit' && isset($toolResult->args['old_string'])) {
                            $content = $this->tool->buildDiffView(
                                $toolResult->args['old_string'],
                                $toolResult->args['new_string'] ?? '',
                                $toolResult->args['path'] ?? '',
                            );
                            $lineCount = count(explode("\n", $content));
                        } elseif ($name === 'file_read') {
                            $content = $this->tool->highlightFileOutput($output);
                            $lineCount = count(explode("\n", $output));
                        } else {
                            $content = implode("\n", array_map(fn (string $l) => "{$text}{$l}{$r}", explode("\n", $output)));
                            $lineCount = count(explode("\n", $output));
                        }

                        $rw = new CollapsibleWidget($resultHeader, $content, $lineCount);
                        $rw->addStyleClass('tool-result');
                        $this->core->addConversationWidget($rw);
                    }
                }

                $flushDiscoveryGroup();

                continue;
            }
        }

        $this->core->flushPendingQuestionRecap();
        $this->core->flushRender();
    }

    private function containsAnsiEscapes(string $text): bool
    {
        return str_contains($text, "\x1b[");
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
}

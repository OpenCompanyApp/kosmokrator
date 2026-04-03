<?php

declare(strict_types=1);

namespace Kosmokrator\Tool;

use Kosmokrator\UI\RendererInterface;

/**
 * Interactive tool that prompts the user with a free-text question and returns
 * their response. Used when the agent needs clarification before proceeding.
 *
 * Delegates rendering to the UI layer via RendererInterface.
 */
class AskUserTool extends AbstractTool
{
    public function __construct(
        private readonly RendererInterface $ui,
    ) {}

    public function name(): string
    {
        return 'ask_user';
    }

    public function description(): string
    {
        return 'Ask the user a question and wait for their free-text response. Use when you need clarification or input before proceeding.';
    }

    public function parameters(): array
    {
        return [
            'question' => ['type' => 'string', 'description' => 'The question to ask the user'],
        ];
    }

    protected function handle(array $args): ToolResult
    {
        $question = $args['question'] ?? '';

        if ($question === '') {
            return ToolResult::error('No question provided');
        }

        $answer = $this->ui->askUser($question);

        return ToolResult::success($answer ?: '(no response)');
    }
}

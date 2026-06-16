<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\ValueObjects\Messages\ToolResultMessage;
use Kosmokrator\Task\TaskStore;

final class WorkingStateCollector
{
    public function __construct(
        private readonly int $maxRecentFiles = 5,
        private readonly int $maxTasksChars = 4000,
    ) {}

    public function collect(ConversationHistory $history, ?TaskStore $taskStore = null, ?AgentContext $agentContext = null): WorkingStateSnapshot
    {
        $files = [];
        foreach (array_reverse($history->messages()) as $message) {
            if (! $message instanceof ToolResultMessage) {
                continue;
            }
            foreach ($message->toolResults as $result) {
                $path = (string) ($result->args['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $label = $result->toolName.': '.$path;
                if (! in_array($label, $files, true)) {
                    $files[] = $label;
                }
                if (count($files) >= $this->maxRecentFiles) {
                    break 2;
                }
            }
        }

        $taskTree = '';
        if ($taskStore !== null && ! $taskStore->isEmpty()) {
            $taskTree = $taskStore->renderTree();
            if (mb_strlen($taskTree) > $this->maxTasksChars) {
                $taskTree = mb_substr($taskTree, 0, $this->maxTasksChars).' [truncated]';
            }
        }

        $background = [];
        if ($agentContext !== null && $agentContext->orchestrator->hasActiveBackgroundAgents($agentContext->id)) {
            $background[] = 'background subagent work is still active';
        }

        return new WorkingStateSnapshot($files, $taskTree, $background);
    }
}

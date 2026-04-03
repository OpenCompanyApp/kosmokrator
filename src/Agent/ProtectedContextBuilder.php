<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\Task\TaskStore;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

/**
 * Builds the protected (non-overridable) portion of the system prompt.
 * Injects runtime facts — agent mode, working directory, git state,
 * and agent nesting context — that the LLM must always see.
 */
final class ProtectedContextBuilder
{
    public function __construct(?TaskStore $taskStore = null) {}

    /**
     * @param  AgentMode  $mode  Current agent operating mode
     * @param  AgentContext|null  $agentContext  Nesting depth context for sub-agents
     * @return list<SystemMessage>  System messages containing the protected context
     */
    public function build(AgentMode $mode, ?AgentContext $agentContext = null): array
    {
        // Protected context is always injected as a SystemMessage so the LLM cannot override it
        $cwd = getcwd() ?: '.';
        $lines = [
            '## Protected Context',
            '- Mode: '.$mode->label(),
            '- Working directory: '.$cwd,
        ];

        $gitRoot = InstructionLoader::gitRoot();
        if ($gitRoot !== null && $gitRoot !== $cwd) {
            $lines[] = '- Repository root: '.$gitRoot;
        }

        $branch = $this->gitBranch($cwd);
        if ($branch !== null) {
            $lines[] = '- Git branch: '.$branch;
        }

        if ($agentContext !== null) {
            $lines[] = '- Agent type: '.$agentContext->type->value;
            $lines[] = '- Agent depth: '.$agentContext->depth.'/'.$agentContext->maxDepth;
        }

        return [new SystemMessage(implode("\n", $lines))];
    }

    /**
     * Resolve the current git branch name, or null if not in a git repo.
     */
    private function gitBranch(string $cwd): ?string
    {
        $head = @shell_exec('git -C '.escapeshellarg($cwd).' rev-parse --abbrev-ref HEAD 2>/dev/null');
        $branch = is_string($head) ? trim($head) : '';

        return $branch !== '' ? $branch : null;
    }
}

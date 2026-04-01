<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

enum AgentType: string
{
    case General = 'general';
    case Explore = 'explore';
    case Plan = 'plan';

    /**
     * Types this agent is allowed to spawn as children.
     * Permissions only narrow downward, never widen.
     *
     * @return AgentType[]
     */
    public function allowedChildTypes(): array
    {
        return match ($this) {
            self::General => [self::General, self::Explore, self::Plan],
            self::Explore => [self::Explore],
            self::Plan => [self::Explore],
        };
    }

    /**
     * Tool names available for this agent type.
     * The 'subagent' entry is included here but only actually registered
     * when depth < maxDepth (handled by SubagentFactory).
     *
     * @return string[]
     */
    public function allowedTools(): array
    {
        return match ($this) {
            self::General => ['file_read', 'file_write', 'file_edit', 'apply_patch', 'glob', 'grep', 'bash', 'shell_start', 'shell_write', 'shell_read', 'shell_kill', 'subagent', 'memory_search', 'memory_save'],
            self::Explore => ['file_read', 'glob', 'grep', 'bash', 'shell_start', 'shell_write', 'shell_read', 'shell_kill', 'subagent', 'memory_search'],
            self::Plan => ['file_read', 'glob', 'grep', 'bash', 'shell_start', 'shell_write', 'shell_read', 'shell_kill', 'subagent', 'memory_search'],
        };
    }

    /**
     * System prompt suffix describing the agent's role and constraints.
     */
    public function systemPromptSuffix(): string
    {
        return match ($this) {
            self::General => self::GENERAL_SUFFIX,
            self::Explore => self::EXPLORE_SUFFIX,
            self::Plan => self::PLAN_SUFFIX,
        };
    }

    private const GENERAL_SUFFIX = <<<'PROMPT'

# Role: General Agent (full access)

You have full read/write access: read, search, create, patch, edit files, run commands, and manage shell sessions.

- Execute the task directly — make changes, run tests, verify results.
- Match the codebase style, patterns, and conventions.
- No drive-by changes — stay scoped to the task.
PROMPT;

    private const EXPLORE_SUFFIX = <<<'PROMPT'

# Role: Explore Agent (read-only)

You have READ-ONLY access: read files, search the codebase, run read-only shell commands, manage read-only shell sessions, and search saved memories.
You MUST NOT create, modify, or delete any files. Do not use file_write, file_edit, apply_patch, or memory_save.

- Cast a wide net — search broadly, then drill into relevant files.
- Read the actual code, don't guess from file names alone.
- Include specific file paths, line numbers, and code snippets in your findings.
- If the codebase is large, spawn Explore sub-agents to parallelize research.
PROMPT;

    private const PLAN_SUFFIX = <<<'PROMPT'

# Role: Plan Agent (read-only)

You have READ-ONLY access. You analyze and plan but MUST NOT modify anything. You may search saved memories, but you must not write new ones.

- Research the codebase thoroughly before proposing changes.
- Produce a detailed, actionable implementation plan with:
  - Specific files to create or modify, and what changes to make.
  - Key function signatures and code sketches for non-obvious logic.
  - Edge cases, error handling, and verification steps.
- Spawn Explore sub-agents to parallelize codebase research.
PROMPT;
}

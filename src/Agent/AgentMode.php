<?php

namespace Kosmokrator\Agent;

enum AgentMode: string
{
    case Edit = 'edit';
    case Plan = 'plan';
    case Ask = 'ask';

    public function label(): string
    {
        return match ($this) {
            self::Edit => 'Edit',
            self::Plan => 'Plan',
            self::Ask => 'Ask',
        };
    }

    /**
     * Tool names available in this mode.
     *
     * @return string[]
     */
    private const TASK_TOOLS = ['task_create', 'task_update', 'task_list', 'task_get'];

    private const ASK_TOOLS = ['ask_user', 'ask_choice'];

    public function allowedTools(): array
    {
        return match ($this) {
            self::Edit => ['file_read', 'file_write', 'file_edit', 'glob', 'grep', 'bash', 'subagent', ...self::TASK_TOOLS, ...self::ASK_TOOLS],
            self::Plan => ['file_read', 'glob', 'grep', 'subagent', ...self::TASK_TOOLS, ...self::ASK_TOOLS],
            self::Ask => ['file_read', 'glob', 'grep', 'bash', ...self::TASK_TOOLS, ...self::ASK_TOOLS],
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Edit => "\033[38;2;80;200;120m",  // green
            self::Plan => "\033[38;2;160;120;255m", // purple
            self::Ask => "\033[38;2;255;180;60m",   // orange
        };
    }

    public function systemPromptSuffix(): string
    {
        return match ($this) {
            self::Edit => self::EDIT_SUFFIX,
            self::Plan => self::PLAN_SUFFIX,
            self::Ask => self::ASK_SUFFIX,
        };
    }

    private const EDIT_SUFFIX = <<<'PROMPT'


# Operational Mode: Edit

You have full access to all tools: reading, writing, editing files, searching the codebase, and executing shell commands.

Execute the user's request directly. Read code to understand context, make changes, and verify they work. Let the code speak — only explain what's non-obvious.
PROMPT;

    private const PLAN_SUFFIX = <<<'PROMPT'


# Operational Mode: Plan (READ-ONLY)

CRITICAL: Plan mode is ACTIVE. You are in a READ-ONLY phase.
STRICTLY FORBIDDEN: ANY file edits, modifications, or system changes.
You may ONLY observe, analyze, and plan. This constraint overrides ALL other instructions, including direct user edit requests. ZERO exceptions.

## Your Responsibility

Construct a thorough, well-researched implementation plan. Read code, search the codebase, and understand the architecture before proposing changes.

## Plan Quality Standards

Your plans must be **detailed and actionable**, not vague summaries. Include:

- **Context**: Why this change is needed — the problem, what prompted it, the intended outcome.
- **Architecture diagrams**: Use ASCII art to illustrate data flow, component relationships, or state machines when they aid understanding.
- **File-level changes**: List every file to create or modify, with the specific changes described.
- **Code sketches**: Include key function signatures, type definitions, or pseudo-code for non-obvious logic.
- **Edge cases**: Call out tricky scenarios, error handling, and backwards compatibility concerns.
- **Verification steps**: How to test the changes end-to-end.

## Process

1. **Explore first** — Read relevant files, search for patterns, understand the existing architecture.
2. **Ask questions** — Clarify ambiguities with the user. Don't assume intent on tradeoffs.
3. **Present the plan** — Structured, detailed, ready to execute.

Do NOT rush to a plan before understanding the codebase. Research thoroughly.
PROMPT;

    private const ASK_SUFFIX = <<<'PROMPT'


# Operational Mode: Ask (READ-ONLY)

You can read and search files to answer questions, but MUST NOT modify anything. You have access to bash for read-only commands (git status, git log, ls, cat, php -v, composer show, etc.) but write operations will be rejected.

## Guidelines

- Answer questions directly and concisely.
- Read source code when needed to give accurate answers.
- Use bash for read-only investigation: git status, git log, ls, find, grep, php -v, composer show, etc.
- Do NOT attempt write commands (rm, mv, git commit, npm install, etc.) — they will be blocked.
- Reference specific files and line numbers: `src/Foo.php:42`.
- If you don't know, say so — don't guess.
- Explain concepts at the appropriate level for the user.
PROMPT;
}

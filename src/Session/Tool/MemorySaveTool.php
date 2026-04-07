<?php

declare(strict_types=1);

namespace Kosmokrator\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Persists memories (project facts, user preferences, architectural decisions) across sessions.
 * Exposed as the "memory_save" tool so agents can store knowledge for later recall.
 */
class MemorySaveTool extends AbstractTool
{
    private const VALID_TYPES = ['project', 'user', 'decision'];

    private const VALID_CLASSES = ['priority', 'working', 'durable'];

    public function __construct(
        private readonly SessionManager $session,
    ) {}

    /** @return string Tool identifier used by the agent runtime */
    public function name(): string
    {
        return 'memory_save';
    }

    /** @return string Human-readable description presented to the LLM */
    public function description(): string
    {
        return 'Save or update a persistent memory. Use to store important facts about the project, user preferences, or architectural decisions across sessions.';
    }

    /** @return array<string,array{type:string,description:string}> JSON Schema-style parameter definitions */
    public function parameters(): array
    {
        return [
            'type' => ['type' => 'string', 'description' => 'Memory type: "project" (codebase facts, architecture), "user" (preferences, workflow), or "decision" (architectural choices, trade-offs)'],
            'title' => ['type' => 'string', 'description' => 'Short descriptive title for the memory'],
            'content' => ['type' => 'string', 'description' => 'Memory content — the knowledge to persist'],
            'class' => ['type' => 'string', 'description' => 'Memory class: "priority", "working", or "durable". Defaults to "durable".'],
            'pinned' => ['type' => 'boolean', 'description' => 'Whether the memory should be favored during recall'],
            'expires_days' => ['type' => 'number', 'description' => 'Optional expiry in days, useful for working memory'],
            'id' => ['type' => 'string', 'description' => 'Existing memory ID to update. Omit to create a new memory.'],
        ];
    }

    /** @return list<string> Names of parameters that must be provided */
    public function requiredParameters(): array
    {
        return ['type', 'title', 'content'];
    }

    /**
     * @param  array{type?:string, title?:string, content?:string, class?:string, pinned?:bool, expires_days?:int, id?:string}  $args  Memory fields from the agent call
     * @return ToolResult Success message with the new/updated memory ID, or an error description
     */
    protected function handle(array $args): ToolResult
    {
        $type = $args['type'] ?? '';
        $title = $args['title'] ?? '';
        $content = $args['content'] ?? '';
        $memoryClass = $args['class'] ?? 'durable';
        $pinned = (bool) ($args['pinned'] ?? false);
        $expiresDays = isset($args['expires_days']) ? (int) $args['expires_days'] : null;
        $id = $args['id'] ?? null;

        if (! in_array($type, self::VALID_TYPES, true)) {
            return ToolResult::error("Invalid memory type '{$type}'. Must be one of: ".implode(', ', self::VALID_TYPES));
        }
        if (! in_array($memoryClass, self::VALID_CLASSES, true)) {
            return ToolResult::error("Invalid memory class '{$memoryClass}'. Must be one of: ".implode(', ', self::VALID_CLASSES));
        }

        $expiresAt = $expiresDays !== null && $expiresDays > 0
            ? date('c', time() + ($expiresDays * 86400))
            : null;

        if ($id !== null && $id !== '') {
            $existing = $this->session->findMemory((int) $id);
            if ($existing === null) {
                return ToolResult::error("Memory #{$id} not found.");
            }

            $this->session->updateMemory((int) $id, $content, $title, $memoryClass, $pinned, $expiresAt);

            return ToolResult::success("Updated memory #{$id}: {$title} ({$type}/{$memoryClass})");
        }

        $duplicate = $this->session->findDuplicateMemory($content, $title);
        if ($duplicate !== null) {
            $dupId = $duplicate['id'];
            $dupTitle = $duplicate['title'];

            return ToolResult::error("A memory with this content already exists: #{$dupId} \"{$dupTitle}\". Use `id: \"{$dupId}\"` to update it instead of creating a duplicate.");
        }

        $newId = $this->session->addMemory($type, $title, $content, $memoryClass, $pinned, $expiresAt);

        return ToolResult::success("Saved memory #{$newId}: {$title} ({$type}/{$memoryClass})");
    }
}

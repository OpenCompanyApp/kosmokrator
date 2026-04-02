<?php

declare(strict_types=1);

namespace Kosmokrator\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class MemorySaveTool implements ToolInterface
{
    private const VALID_TYPES = ['project', 'user', 'decision'];

    private const VALID_CLASSES = ['priority', 'working', 'durable'];

    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function name(): string
    {
        return 'memory_save';
    }

    public function description(): string
    {
        return 'Save or update a persistent memory. Use to store important facts about the project, user preferences, or architectural decisions across sessions.';
    }

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

    public function requiredParameters(): array
    {
        return ['type', 'title', 'content'];
    }

    public function execute(array $args): ToolResult
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

        $newId = $this->session->addMemory($type, $title, $content, $memoryClass, $pinned, $expiresAt);

        return ToolResult::success("Saved memory #{$newId}: {$title} ({$type}/{$memoryClass})");
    }
}

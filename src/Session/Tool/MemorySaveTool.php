<?php

declare(strict_types=1);

namespace Kosmokrator\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

class MemorySaveTool implements ToolInterface
{
    private const VALID_TYPES = ['project', 'user', 'decision'];

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
        $id = $args['id'] ?? null;

        if (! in_array($type, self::VALID_TYPES, true)) {
            return ToolResult::error("Invalid memory type '{$type}'. Must be one of: " . implode(', ', self::VALID_TYPES));
        }

        if ($id !== null && $id !== '') {
            $existing = $this->session->findMemory((int) $id);
            if ($existing === null) {
                return ToolResult::error("Memory #{$id} not found.");
            }

            $this->session->updateMemory((int) $id, $content, $title);

            return ToolResult::success("Updated memory #{$id}: {$title} ({$type})");
        }

        $newId = $this->session->addMemory($type, $title, $content);

        return ToolResult::success("Saved memory #{$newId}: {$title} ({$type})");
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Session\Tool;

use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;

/**
 * Loads the full conversation transcript of a prior session by ID or prefix.
 *
 * Companion to session_search — after finding a relevant session, the agent
 * can drill into its full conversation with this tool.
 */
class SessionReadTool extends AbstractTool
{
    public function __construct(
        private readonly SessionManager $session,
    ) {}

    public function name(): string
    {
        return 'session_read';
    }

    public function description(): string
    {
        return 'Load the full conversation transcript of a prior session by ID prefix. Use after session_search to drill into a specific session.';
    }

    public function parameters(): array
    {
        return [
            'session_id' => ['type' => 'string', 'description' => 'Session ID or unique prefix (at least 8 characters). Shown in session_search results as [xxxxxxxx].'],
            'limit' => ['type' => 'integer', 'description' => 'Maximum messages to return. Defaults to 50, max 200.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['session_id'];
    }

    /**
     * @param  array{session_id?:string, limit?:int|string}  $args
     */
    protected function handle(array $args): ToolResult
    {
        $idOrPrefix = trim((string) ($args['session_id'] ?? ''));
        if ($idOrPrefix === '') {
            return ToolResult::error('session_id is required.');
        }

        $rawLimit = $args['limit'] ?? 50;
        $limit = is_numeric((string) $rawLimit) ? (int) $rawLimit : 50;
        $limit = max(1, min(200, $limit));

        $session = $this->session->findSession($idOrPrefix);
        if ($session === null) {
            return ToolResult::error("No session found matching \"{$idOrPrefix}\".");
        }

        $sessionId = (string) $session['id'];
        $title = (string) ($session['title'] ?? 'untitled');
        $model = (string) ($session['model'] ?? 'unknown');
        $date = isset($session['created_at']) ? $this->formatDate((string) $session['created_at']) : 'unknown';

        $messages = $this->session->loadSessionTranscript($sessionId, $limit);
        if ($messages === []) {
            return ToolResult::success("Session \"{$title}\" exists but has no messages.");
        }

        $lines = [
            "# Session: {$title}",
            "ID: {$sessionId} | Model: {$model} | Date: {$date} | Messages: ".count($messages),
            '',
        ];

        foreach ($messages as $msg) {
            $role = strtoupper((string) ($msg['role'] ?? 'unknown'));
            $content = (string) ($msg['content'] ?? '');
            $toolCalls = (string) ($msg['tool_calls'] ?? '');

            if ($content !== '') {
                // Truncate very long messages to avoid blowing up context
                $display = mb_strlen($content) > 2000
                    ? mb_substr($content, 0, 2000).'... [truncated]'
                    : $content;
                $lines[] = "[{$role}]: {$display}";
            } elseif ($toolCalls !== '') {
                $calls = json_decode($toolCalls, true);
                if (is_array($calls)) {
                    $names = array_map(fn (array $c) => (string) ($c['name'] ?? '?'), $calls);
                    $lines[] = "[{$role}]: [Called: ".implode(', ', $names).']';
                }
            }

            $lines[] = '';
        }

        if (count($messages) === $limit) {
            $lines[] = "(Showing first {$limit} messages. Use a higher limit to see more.)";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function formatDate(string $timestamp): string
    {
        if (is_numeric($timestamp)) {
            return date('Y-m-d', (int) ((float) $timestamp));
        }

        $time = strtotime($timestamp);

        return $time !== false ? date('Y-m-d', $time) : substr($timestamp, 0, 10);
    }
}

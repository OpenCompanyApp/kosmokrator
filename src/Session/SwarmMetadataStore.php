<?php

declare(strict_types=1);

namespace Kosmokrator\Session;

use Kosmokrator\Agent\SubagentStats;

final class SwarmMetadataStore
{
    public function __construct(private readonly Database $db) {}

    public function upsertAgent(SubagentStats $stats, string $rootSessionId): void
    {
        $now = $this->now();
        $stmt = $this->db->connection()->prepare(
            <<<'SQL'
            INSERT INTO swarm_agents (
                root_session_id, agent_id, parent_id, type, mode, status, group_name,
                depends_on_json, task_preview, tool_calls, tokens_in, tokens_out, retries,
                queue_reason, last_tool, last_message_preview, next_retry_at,
                output_ref, output_bytes, output_preview, error,
                created_at, started_at, last_activity_at, ended_at, updated_at
            ) VALUES (
                :root_session_id, :agent_id, :parent_id, :type, :mode, :status, :group_name,
                :depends_on_json, :task_preview, :tool_calls, :tokens_in, :tokens_out, :retries,
                :queue_reason, :last_tool, :last_message_preview, :next_retry_at,
                :output_ref, :output_bytes, :output_preview, :error,
                :created_at, :started_at, :last_activity_at, :ended_at, :updated_at
            )
            ON CONFLICT(root_session_id, agent_id) DO UPDATE SET
                parent_id = excluded.parent_id,
                type = excluded.type,
                mode = excluded.mode,
                status = excluded.status,
                group_name = excluded.group_name,
                depends_on_json = excluded.depends_on_json,
                task_preview = excluded.task_preview,
                tool_calls = excluded.tool_calls,
                tokens_in = excluded.tokens_in,
                tokens_out = excluded.tokens_out,
                retries = excluded.retries,
                queue_reason = excluded.queue_reason,
                last_tool = excluded.last_tool,
                last_message_preview = excluded.last_message_preview,
                next_retry_at = excluded.next_retry_at,
                output_ref = excluded.output_ref,
                output_bytes = excluded.output_bytes,
                output_preview = excluded.output_preview,
                error = excluded.error,
                started_at = excluded.started_at,
                last_activity_at = excluded.last_activity_at,
                ended_at = excluded.ended_at,
                updated_at = excluded.updated_at
            SQL
        );

        $stmt->execute([
            'root_session_id' => $rootSessionId,
            'agent_id' => $stats->id,
            'parent_id' => $stats->parentId,
            'type' => $stats->agentType,
            'mode' => $stats->mode,
            'status' => $stats->status,
            'group_name' => $stats->group,
            'depends_on_json' => json_encode(array_values($stats->dependsOn), JSON_THROW_ON_ERROR),
            'task_preview' => $stats->task,
            'tool_calls' => $stats->toolCalls,
            'tokens_in' => $stats->tokensIn,
            'tokens_out' => $stats->tokensOut,
            'retries' => $stats->retries,
            'queue_reason' => $stats->queueReason,
            'last_tool' => $stats->lastTool,
            'last_message_preview' => $stats->lastMessagePreview,
            'next_retry_at' => $this->timestamp($stats->nextRetryAt),
            'output_ref' => $stats->outputRef,
            'output_bytes' => $stats->outputBytes,
            'output_preview' => $stats->outputPreview,
            'error' => $stats->error,
            'created_at' => $now,
            'started_at' => $this->timestamp($stats->startTime),
            'last_activity_at' => $this->timestamp($stats->lastActivityTime),
            'ended_at' => $this->timestamp($stats->endTime),
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, SubagentStats>
     */
    public function latestForSession(string $rootSessionId): array
    {
        $stmt = $this->db->connection()->prepare(
            'SELECT * FROM swarm_agents WHERE root_session_id = :session ORDER BY updated_at DESC, agent_id ASC'
        );
        $stmt->execute(['session' => $rootSessionId]);

        $stats = [];
        foreach ($stmt->fetchAll() as $row) {
            $agent = new SubagentStats((string) $row['agent_id']);
            $agent->parentId = $row['parent_id'] !== null ? (string) $row['parent_id'] : null;
            $agent->agentType = (string) ($row['type'] ?? '');
            $agent->mode = (string) ($row['mode'] ?? 'await');
            $agent->status = (string) $row['status'];
            $agent->group = $row['group_name'] !== null ? (string) $row['group_name'] : null;
            $agent->dependsOn = $this->decodeStringList($row['depends_on_json'] ?? '[]');
            $agent->task = (string) ($row['task_preview'] ?? '');
            $agent->toolCalls = (int) $row['tool_calls'];
            $agent->tokensIn = (int) $row['tokens_in'];
            $agent->tokensOut = (int) $row['tokens_out'];
            $agent->retries = (int) $row['retries'];
            $agent->queueReason = $row['queue_reason'] !== null ? (string) $row['queue_reason'] : 'offline snapshot';
            $agent->lastTool = $row['last_tool'] !== null ? (string) $row['last_tool'] : null;
            $agent->lastMessagePreview = $row['last_message_preview'] !== null ? (string) $row['last_message_preview'] : null;
            $agent->nextRetryAt = $this->parseTimestamp($row['next_retry_at'] ?? null);
            $agent->outputRef = $row['output_ref'] !== null ? (string) $row['output_ref'] : null;
            $agent->outputBytes = (int) ($row['output_bytes'] ?? 0);
            $agent->outputPreview = $row['output_preview'] !== null ? (string) $row['output_preview'] : null;
            $agent->error = $row['error'] !== null ? (string) $row['error'] : null;
            $agent->startTime = $this->parseTimestamp($row['started_at'] ?? null) ?? 0.0;
            $agent->lastActivityTime = $this->parseTimestamp($row['last_activity_at'] ?? null) ?? 0.0;
            $agent->endTime = $this->parseTimestamp($row['ended_at'] ?? null) ?? 0.0;

            $stats[$agent->id] = $agent;
        }

        return $stats;
    }

    private function now(): string
    {
        return gmdate('c');
    }

    private function timestamp(?float $timestamp): ?string
    {
        if ($timestamp === null || $timestamp <= 0.0) {
            return null;
        }

        return gmdate('c', (int) $timestamp);
    }

    private function parseTimestamp(mixed $value): ?float
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : (float) $timestamp;
    }

    /**
     * @return string[]
     */
    private function decodeStringList(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }
}

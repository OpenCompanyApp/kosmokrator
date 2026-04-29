<?php

declare(strict_types=1);

namespace Kosmokrator\Acp;

use JsonSerializable;
use Kosmokrator\Agent\SubagentStats;

final class AcpKosmokratorProtocol
{
    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function capabilities(): array
    {
        return [
            'protocolVersion' => self::VERSION,
            'uiEvents' => true,
            'textDeltas' => true,
            'thinkingDeltas' => true,
            'toolLifecycle' => true,
            'permissions' => true,
            'subagentTree' => true,
            'subagentDashboard' => true,
            'tasks' => true,
            'memories' => true,
            'integrations' => true,
            'mcp' => true,
            'lua' => true,
            'runtimeConfig' => true,
            'sessions' => true,
            'permissionModes' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function event(?string $sessionId, ?string $runId, string $type, array $fields = []): array
    {
        return [
            'protocolVersion' => self::VERSION,
            'type' => $type,
            'sessionId' => $sessionId,
            'runId' => $runId,
            'timestamp' => microtime(true),
        ] + self::normalize($fields);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof SubagentStats) {
            return self::subagentStats($value);
        }

        if ($value instanceof JsonSerializable) {
            return self::normalizeValue($value->jsonSerialize());
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeValue($item);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return method_exists($value, 'toArray')
                ? self::normalizeValue($value->toArray())
                : (array) $value;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function subagentStats(SubagentStats $stats): array
    {
        return [
            'id' => $stats->id,
            'status' => $stats->status,
            'mode' => $stats->mode,
            'toolCalls' => $stats->toolCalls,
            'tokensIn' => $stats->tokensIn,
            'tokensOut' => $stats->tokensOut,
            'elapsed' => round($stats->elapsed(), 3),
            'startTime' => $stats->startTime,
            'endTime' => $stats->endTime,
            'lastActivityTime' => $stats->lastActivityTime,
            'task' => $stats->task,
            'agentType' => $stats->agentType,
            'group' => $stats->group,
            'dependsOn' => $stats->dependsOn,
            'error' => $stats->error,
            'parentId' => $stats->parentId,
            'depth' => $stats->depth,
            'retries' => $stats->retries,
            'queueReason' => $stats->queueReason,
            'lastTool' => $stats->lastTool,
            'lastMessagePreview' => $stats->lastMessagePreview,
            'nextRetryAt' => $stats->nextRetryAt,
            'outputRef' => $stats->outputRef,
            'outputBytes' => $stats->outputBytes,
            'outputPreview' => $stats->outputPreview,
        ];
    }
}

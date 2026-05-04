<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

use Kosmokrator\Session\Database;

final class GatewayApprovalStore
{
    public function __construct(
        private readonly Database $database,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function createPending(
        string $platform,
        string $routeKey,
        string $sessionId,
        string $toolName,
        array $arguments,
        string $chatId,
        ?string $threadId = null,
        ?int $requestMessageId = null,
        ?string $requesterUserId = null,
        ?string $requesterUsername = null,
    ): GatewayApproval {
        $now = (new \DateTimeImmutable)->format(DATE_ATOM);
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO gateway_approvals (
                platform, route_key, session_id, tool_name, arguments_json, status, chat_id, thread_id, request_message_id, requester_user_id, requester_username, created_at
            ) VALUES (
                :platform, :route_key, :session_id, :tool_name, :arguments_json, :status, :chat_id, :thread_id, :request_message_id, :requester_user_id, :requester_username, :created_at
            )'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'arguments_json' => json_encode($arguments, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'request_message_id' => $requestMessageId,
            'requester_user_id' => $requesterUserId,
            'requester_username' => $requesterUsername !== null ? ltrim($requesterUsername, '@') : null,
            'created_at' => $now,
        ]);

        return new GatewayApproval(
            id: (int) $this->database->connection()->lastInsertId(),
            platform: $platform,
            routeKey: $routeKey,
            sessionId: $sessionId,
            toolName: $toolName,
            arguments: $arguments,
            status: 'pending',
            chatId: $chatId,
            threadId: $threadId,
            requestMessageId: $requestMessageId,
            requesterUserId: $requesterUserId,
            requesterUsername: $requesterUsername !== null ? ltrim($requesterUsername, '@') : null,
        );
    }

    public function latestPending(string $platform, string $routeKey): ?GatewayApproval
    {
        return $this->findLatestByStatus($platform, $routeKey, 'pending');
    }

    public function resolve(int $id, string $status): void
    {
        $stmt = $this->database->connection()->prepare(
            'UPDATE gateway_approvals SET status = :status, resolved_at = :resolved_at WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'resolved_at' => (new \DateTimeImmutable)->format(DATE_ATOM),
            'id' => $id,
        ]);
    }

    public function find(int $id): ?GatewayApproval
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT * FROM gateway_approvals WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function resolveLatestPending(string $platform, string $routeKey, string $status): ?GatewayApproval
    {
        $approval = $this->latestPending($platform, $routeKey);
        if ($approval === null) {
            return null;
        }

        $this->resolve($approval->id, $status);

        return $this->find($approval->id);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hydrate(array $row): GatewayApproval
    {
        $arguments = json_decode((string) $row['arguments_json'], true);

        return new GatewayApproval(
            id: (int) $row['id'],
            platform: (string) $row['platform'],
            routeKey: (string) $row['route_key'],
            sessionId: (string) $row['session_id'],
            toolName: (string) $row['tool_name'],
            arguments: is_array($arguments) ? $arguments : [],
            status: (string) $row['status'],
            chatId: (string) $row['chat_id'],
            threadId: $row['thread_id'] !== null ? (string) $row['thread_id'] : null,
            requestMessageId: $row['request_message_id'] !== null ? (int) $row['request_message_id'] : null,
            requesterUserId: isset($row['requester_user_id']) && $row['requester_user_id'] !== null ? (string) $row['requester_user_id'] : null,
            requesterUsername: isset($row['requester_username']) && $row['requester_username'] !== null ? (string) $row['requester_username'] : null,
        );
    }

    private function findLatestByStatus(string $platform, string $routeKey, string $status): ?GatewayApproval
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT * FROM gateway_approvals
             WHERE platform = :platform AND route_key = :route_key AND status = :status
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
            'status' => $status,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }
}

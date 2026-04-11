<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

use Kosmokrator\Session\Database;

final class GatewaySessionStore
{
    public function __construct(
        private readonly Database $database,
    ) {}

    public function find(string $platform, string $routeKey): ?GatewaySessionLink
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT * FROM gateway_sessions WHERE platform = :platform AND route_key = :route_key LIMIT 1'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
        ]);

        $row = $stmt->fetch();
        if (! is_array($row)) {
            return null;
        }

        return new GatewaySessionLink(
            platform: (string) $row['platform'],
            routeKey: (string) $row['route_key'],
            sessionId: (string) $row['session_id'],
            chatId: (string) $row['chat_id'],
            threadId: $row['thread_id'] !== null ? (string) $row['thread_id'] : null,
            userId: $row['user_id'] !== null ? (string) $row['user_id'] : null,
            metadata: $this->decodeJsonMap($row['metadata'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function save(
        string $platform,
        string $routeKey,
        string $sessionId,
        string $chatId,
        ?string $threadId = null,
        ?string $userId = null,
        array $metadata = [],
    ): void {
        $now = (new \DateTimeImmutable)->format(DATE_ATOM);
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO gateway_sessions (platform, route_key, session_id, chat_id, thread_id, user_id, metadata, created_at, updated_at)
             VALUES (:platform, :route_key, :session_id, :chat_id, :thread_id, :user_id, :metadata, :created_at, :updated_at)
             ON CONFLICT(platform, route_key) DO UPDATE SET
                session_id = excluded.session_id,
                chat_id = excluded.chat_id,
                thread_id = excluded.thread_id,
                user_id = excluded.user_id,
                metadata = excluded.metadata,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
            'session_id' => $sessionId,
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'user_id' => $userId,
            'metadata' => $metadata !== [] ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function delete(string $platform, string $routeKey): void
    {
        $stmt = $this->database->connection()->prepare(
            'DELETE FROM gateway_sessions WHERE platform = :platform AND route_key = :route_key'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
        ]);
    }

    private function decodeJsonMap(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

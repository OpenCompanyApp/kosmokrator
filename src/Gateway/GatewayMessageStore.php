<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

use Kosmokrator\Session\Database;

final class GatewayMessageStore
{
    public function __construct(
        private readonly Database $database,
    ) {}

    public function find(string $platform, string $routeKey, string $messageKind): ?GatewayMessagePointer
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT * FROM gateway_messages WHERE platform = :platform AND route_key = :route_key AND message_kind = :message_kind LIMIT 1'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
            'message_kind' => $messageKind,
        ]);

        $row = $stmt->fetch();
        if (! is_array($row)) {
            return null;
        }

        return new GatewayMessagePointer(
            platform: (string) $row['platform'],
            routeKey: (string) $row['route_key'],
            messageKind: (string) $row['message_kind'],
            chatId: (string) $row['chat_id'],
            messageId: (int) $row['message_id'],
            threadId: $row['thread_id'] !== null ? (string) $row['thread_id'] : null,
        );
    }

    public function save(
        string $platform,
        string $routeKey,
        string $messageKind,
        string $chatId,
        int $messageId,
        ?string $threadId = null,
    ): void {
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO gateway_messages (platform, route_key, message_kind, chat_id, message_id, thread_id, updated_at)
             VALUES (:platform, :route_key, :message_kind, :chat_id, :message_id, :thread_id, :updated_at)
             ON CONFLICT(platform, route_key, message_kind) DO UPDATE SET
                chat_id = excluded.chat_id,
                message_id = excluded.message_id,
                thread_id = excluded.thread_id,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
            'message_kind' => $messageKind,
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'thread_id' => $threadId,
            'updated_at' => (new \DateTimeImmutable)->format(DATE_ATOM),
        ]);
    }

    public function delete(string $platform, string $routeKey, string $messageKind): void
    {
        $stmt = $this->database->connection()->prepare(
            'DELETE FROM gateway_messages WHERE platform = :platform AND route_key = :route_key AND message_kind = :message_kind'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
            'message_kind' => $messageKind,
        ]);
    }
}

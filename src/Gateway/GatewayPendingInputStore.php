<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

use Kosmokrator\Session\Database;

final class GatewayPendingInputStore
{
    public function __construct(
        private readonly Database $database,
    ) {}

    public function enqueue(string $platform, string $routeKey, GatewayMessageEvent $event): void
    {
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO gateway_pending_inputs (platform, route_key, payload_json, created_at)
             VALUES (:platform, :route_key, :payload_json, :created_at)'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
            'payload_json' => json_encode($event->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable)->format(DATE_ATOM),
        ]);
    }

    public function count(string $platform, string $routeKey): int
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT COUNT(*) AS cnt FROM gateway_pending_inputs WHERE platform = :platform AND route_key = :route_key'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? (int) ($row['cnt'] ?? 0) : 0;
    }

    public function dequeueNext(string $platform, string $routeKey): ?GatewayPendingInput
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT * FROM gateway_pending_inputs WHERE platform = :platform AND route_key = :route_key ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
        ]);

        $row = $stmt->fetch();
        if (! is_array($row)) {
            return null;
        }

        $delete = $this->database->connection()->prepare(
            'DELETE FROM gateway_pending_inputs WHERE id = :id'
        );
        $delete->execute(['id' => $row['id']]);

        $payload = json_decode((string) $row['payload_json'], true);

        return new GatewayPendingInput(
            id: (int) $row['id'],
            platform: (string) $row['platform'],
            routeKey: (string) $row['route_key'],
            payload: is_array($payload) ? $payload : [],
            createdAt: (string) $row['created_at'],
        );
    }

    public function clear(string $platform, string $routeKey): void
    {
        $stmt = $this->database->connection()->prepare(
            'DELETE FROM gateway_pending_inputs WHERE platform = :platform AND route_key = :route_key'
        );
        $stmt->execute([
            'platform' => $platform,
            'route_key' => $routeKey,
        ]);
    }
}

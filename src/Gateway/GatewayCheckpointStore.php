<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway;

use Kosmokrator\Session\Database;

final class GatewayCheckpointStore
{
    public function __construct(
        private readonly Database $database,
    ) {}

    public function get(string $platform, string $checkpoint): ?string
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT value FROM gateway_checkpoints WHERE platform = :platform AND checkpoint = :checkpoint LIMIT 1'
        );
        $stmt->execute([
            'platform' => $platform,
            'checkpoint' => $checkpoint,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? (string) $row['value'] : null;
    }

    public function set(string $platform, string $checkpoint, ?string $value): void
    {
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO gateway_checkpoints (platform, checkpoint, value, updated_at)
             VALUES (:platform, :checkpoint, :value, :updated_at)
             ON CONFLICT(platform, checkpoint) DO UPDATE SET
                value = excluded.value,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'platform' => $platform,
            'checkpoint' => $checkpoint,
            'value' => $value,
            'updated_at' => (new \DateTimeImmutable)->format(DATE_ATOM),
        ]);
    }
}

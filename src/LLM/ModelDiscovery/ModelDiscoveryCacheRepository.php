<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ModelDiscovery;

use Kosmokrator\Session\Database;

final class ModelDiscoveryCacheRepository
{
    public function __construct(private readonly Database $database) {}

    public function get(string $provider, string $account = 'default', bool $freshOnly = false): ?ModelDiscoveryResult
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT * FROM provider_model_cache WHERE provider = :provider AND account = :account LIMIT 1',
        );
        $stmt->execute(['provider' => $provider, 'account' => $account]);
        $row = $stmt->fetch();
        $stmt->closeCursor();

        if (! is_array($row)) {
            return null;
        }

        $result = $this->hydrate($row);
        if ($result === null || ($freshOnly && ! $result->isFresh())) {
            return null;
        }

        return $result;
    }

    /**
     * @param  list<DiscoveredModel>  $models
     */
    public function putSuccess(string $provider, array $models, string $source, int $ttlSeconds, string $account = 'default'): ModelDiscoveryResult
    {
        $now = new \DateTimeImmutable;
        $result = new ModelDiscoveryResult(
            provider: $provider,
            source: $source,
            models: $models,
            fetchedAt: $now,
            expiresAt: $now->modify(($ttlSeconds >= 0 ? '+' : '').$ttlSeconds.' seconds'),
            account: $account,
        );

        $this->write($result);

        return $result;
    }

    public function putError(string $provider, string $source, string $error, int $ttlSeconds = 300, string $account = 'default'): ModelDiscoveryResult
    {
        $now = new \DateTimeImmutable;
        $result = new ModelDiscoveryResult(
            provider: $provider,
            source: $source,
            models: [],
            fetchedAt: $now,
            expiresAt: $now->modify(($ttlSeconds >= 0 ? '+' : '').$ttlSeconds.' seconds'),
            error: $error,
            account: $account,
        );

        $this->write($result);

        return $result;
    }

    /**
     * @return list<ModelDiscoveryResult>
     */
    public function all(): array
    {
        $stmt = $this->database->connection()->query('SELECT * FROM provider_model_cache ORDER BY provider, account');
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        $results = [];
        foreach ($rows as $row) {
            $result = is_array($row) ? $this->hydrate($row) : null;
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function write(ModelDiscoveryResult $result): void
    {
        $stmt = $this->database->connection()->prepare(
            <<<'SQL'
            INSERT INTO provider_model_cache (
                provider, account, source, models_json, error, fetched_at, expires_at
            ) VALUES (
                :provider, :account, :source, :models_json, :error, :fetched_at, :expires_at
            )
            ON CONFLICT(provider, account) DO UPDATE SET
                source = excluded.source,
                models_json = excluded.models_json,
                error = excluded.error,
                fetched_at = excluded.fetched_at,
                expires_at = excluded.expires_at
            SQL
        );

        $stmt->execute([
            'provider' => $result->provider,
            'account' => $result->account,
            'source' => $result->source,
            'models_json' => json_encode(
                array_map(static fn (DiscoveredModel $model): array => $model->toArray(), $result->models),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
            'error' => $result->error,
            'fetched_at' => $result->fetchedAt->format(DATE_ATOM),
            'expires_at' => $result->expiresAt->format(DATE_ATOM),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hydrate(array $row): ?ModelDiscoveryResult
    {
        try {
            $decoded = json_decode((string) ($row['models_json'] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $models = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $model = DiscoveredModel::fromArray($item);
            if ($model->id !== '') {
                $models[] = $model;
            }
        }

        try {
            $fetchedAt = new \DateTimeImmutable((string) ($row['fetched_at'] ?? 'now'));
            $expiresAt = new \DateTimeImmutable((string) ($row['expires_at'] ?? 'now'));
        } catch (\Exception) {
            return null;
        }

        return new ModelDiscoveryResult(
            provider: (string) ($row['provider'] ?? ''),
            source: (string) ($row['source'] ?? 'provider_live'),
            models: $models,
            fetchedAt: $fetchedAt,
            expiresAt: $expiresAt,
            error: is_string($row['error'] ?? null) && $row['error'] !== '' ? $row['error'] : null,
            account: (string) ($row['account'] ?? 'default'),
        );
    }
}

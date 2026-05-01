<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ModelDiscovery;

final readonly class ModelDiscoveryResult
{
    /**
     * @param  list<DiscoveredModel>  $models
     */
    public function __construct(
        public string $provider,
        public string $source,
        public array $models,
        public \DateTimeImmutable $fetchedAt,
        public \DateTimeImmutable $expiresAt,
        public ?string $error = null,
        public string $account = 'default',
    ) {}

    public function isFresh(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt > ($now ?? new \DateTimeImmutable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'account' => $this->account,
            'source' => $this->source,
            'fetched_at' => $this->fetchedAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'fresh' => $this->isFresh(),
            'error' => $this->error,
            'models' => array_map(static fn (DiscoveredModel $model): array => $model->toArray(), $this->models),
        ];
    }
}

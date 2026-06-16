<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\Codex;

final readonly class CodexToken
{
    /** @param array<string, mixed> $tokenData */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public \DateTimeImmutable $expiresAt,
        public ?string $accountId = null,
        public ?string $email = null,
        public array $tokenData = [],
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: (string) ($data['refresh_token'] ?? ''),
            expiresAt: self::normalizeDate($data['expires_at'] ?? 'now'),
            accountId: isset($data['account_id']) ? (string) $data['account_id'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            tokenData: is_array($data['token_data'] ?? null) ? $data['token_data'] : [],
            createdAt: isset($data['created_at']) ? self::normalizeDate($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? self::normalizeDate($data['updated_at']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'account_id' => $this->accountId,
            'email' => $this->email,
            'token_data' => $this->tokenData,
            'created_at' => $this->createdAt?->format(DATE_ATOM),
            'updated_at' => $this->updatedAt?->format(DATE_ATOM),
        ];
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable;
    }

    public function isExpiringSoon(int $bufferSeconds = 60): bool
    {
        return $this->expiresAt <= (new \DateTimeImmutable)->modify("+{$bufferSeconds} seconds");
    }

    private static function normalizeDate(\DateTimeImmutable|\DateTimeInterface|string $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        return new \DateTimeImmutable($value);
    }
}

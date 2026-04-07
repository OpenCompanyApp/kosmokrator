<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Input;

/**
 * Immutable value object representing a single input history entry.
 *
 * Each entry captures the submitted text, when it was entered, and an optional
 * session context identifier so entries can be scoped to a conversation session.
 */
final readonly class HistoryEntry
{
    public function __construct(
        public string $text,
        public float $timestamp,
        public ?string $sessionId = null,
    ) {}

    /**
     * Reconstruct an entry from its persisted associative-array form.
     *
     * @param  array{text: string, timestamp: float, session_id?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'],
            timestamp: $data['timestamp'],
            sessionId: $data['session_id'] ?? null,
        );
    }

    /**
     * Convert to an associative array suitable for JSON persistence.
     *
     * @return array{text: string, timestamp: float, session_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'timestamp' => $this->timestamp,
            'session_id' => $this->sessionId,
        ];
    }

    /**
     * Compare two entries by their text content (ignoring timestamp and session).
     */
    public function textEquals(self $other): bool
    {
        return $this->text === $other->text;
    }
}

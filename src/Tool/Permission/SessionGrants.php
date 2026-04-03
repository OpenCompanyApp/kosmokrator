<?php

namespace Kosmokrator\Tool\Permission;

/**
 * Tracks which tools the user has approved for the remainder of the session,
 * so subsequent calls to the same tool skip the Ask prompt.
 *
 * Grants are per-tool (not per-path or per-command) and reset when the session ends.
 */
class SessionGrants
{
    /** @var array<string, true> */
    private array $grants = [];

    /** Mark a tool as session-approved. */
    public function grant(string $toolName): void
    {
        $this->grants[$toolName] = true;
    }

    /** Check whether a tool has been session-approved. */
    public function isGranted(string $toolName): bool
    {
        return isset($this->grants[$toolName]);
    }

    /** Clear all grants (e.g. on session reset). */
    public function reset(): void
    {
        $this->grants = [];
    }
}

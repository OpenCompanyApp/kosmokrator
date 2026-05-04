<?php

namespace Kosmokrator\Tool\Permission;

/**
 * Tracks exact tool calls the user has approved for the remainder of the session,
 * so identical subsequent calls skip the Ask prompt.
 *
 * Grants are scoped by tool name and a canonical hash of the arguments. This
 * prevents "always allow bash" from silently approving a different command.
 */
class SessionGrants
{
    /** @var array<string, true> */
    private array $grants = [];

    /** Mark an exact tool call as session-approved. */
    public function grant(string $toolName, array $args = []): void
    {
        $this->grants[$this->grantKey($toolName, $args)] = true;
    }

    /** Check whether an exact tool call has been session-approved. */
    public function isGranted(string $toolName, array $args = []): bool
    {
        return isset($this->grants[$this->grantKey($toolName, $args)]);
    }

    /** Clear all grants (e.g. on session reset). */
    public function reset(): void
    {
        $this->grants = [];
    }

    private function grantKey(string $toolName, array $args): string
    {
        $encoded = json_encode($this->canonicalize($args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = serialize($args);
        }

        return $toolName.':'.hash('sha256', $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        return $canonical;
    }
}

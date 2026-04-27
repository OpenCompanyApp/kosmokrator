<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

final class IntegrationArgumentMapper
{
    /**
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    public function map(array $tokens, ?string $payload = null): array
    {
        $args = [];

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('JSON payload must decode to an object.');
            }
            $args = $decoded;
        }

        foreach ($tokens as $index => $token) {
            if ($token === '--arg') {
                $pair = $tokens[$index + 1] ?? '';
                if ($pair !== '') {
                    $this->applyPair($args, $pair);
                }

                continue;
            }

            if (! str_starts_with($token, '--')) {
                continue;
            }

            $option = substr($token, 2);
            if ($option === '' || in_array($option, ['json', 'pretty', 'dry-run'], true) || str_starts_with($option, 'format')) {
                continue;
            }

            if (str_contains($option, '=')) {
                [$key, $value] = explode('=', $option, 2);
            } else {
                $key = $option;
                $next = $tokens[$index + 1] ?? null;
                $value = $next !== null && ! str_starts_with($next, '--') ? $next : 'true';
            }

            if (in_array($key, ['account', 'force', 'timeout', 'permission-mode'], true)) {
                continue;
            }

            $args[$this->normalizeKey($key)] = $this->coerce($value);
        }

        return $args;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function applyPair(array &$args, string $pair): void
    {
        if (! str_contains($pair, '=')) {
            throw new \RuntimeException('--arg values must use key=value syntax.');
        }

        [$key, $value] = explode('=', $pair, 2);
        $args[$this->normalizeKey($key)] = $this->coerce($value);
    }

    private function normalizeKey(string $key): string
    {
        return str_replace('-', '_', trim($key));
    }

    private function coerce(string $value): mixed
    {
        $trimmed = trim($value);

        return match (strtolower($trimmed)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => is_numeric($trimmed)
                ? (str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed)
                : $value,
        };
    }
}

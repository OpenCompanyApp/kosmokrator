<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

final class CompactionInputSlimmer
{
    private const LARGE_STRING_CHARS = 20_000;

    public function slim(string $text): string
    {
        $text = preg_replace_callback(
            '/data:([a-zA-Z0-9.+\/-]+);base64,([A-Za-z0-9+\/=\r\n]{256,})/',
            static fn (array $m): string => '[attached data URL omitted: mime='.$m[1].', bytes~'.strlen($m[2]).', hash='.substr(hash('sha256', $m[2]), 0, 12).']',
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '/(?<![A-Za-z0-9+\/=])([A-Za-z0-9+\/]{1200,}={0,2})(?![A-Za-z0-9+\/=])/',
            static fn (array $m): string => '[large base64-like payload omitted: bytes~'.strlen($m[1]).', hash='.substr(hash('sha256', $m[1]), 0, 12).']',
            $text,
        ) ?? $text;

        if (strlen($text) > self::LARGE_STRING_CHARS && $this->looksBinaryOrOpaque($text)) {
            return '[large opaque payload omitted: bytes='.strlen($text).', hash='.substr(hash('sha256', $text), 0, 12).']';
        }

        return $text;
    }

    private function looksBinaryOrOpaque(string $text): bool
    {
        $sample = substr($text, 0, 4096);
        $control = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample);
        if ($control > 0) {
            return true;
        }

        $nonWhitespace = preg_replace('/\s+/', '', $sample) ?? $sample;
        if ($nonWhitespace === '') {
            return false;
        }

        return strlen($nonWhitespace) > 2000 && preg_match('/^[A-Za-z0-9+\/=_-]+$/', $nonWhitespace) === 1;
    }
}

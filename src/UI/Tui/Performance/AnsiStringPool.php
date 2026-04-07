<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Performance;

/**
 * Interns (deduplicates) ANSI escape sequences and other short strings
 * that are allocated repeatedly during TUI rendering.
 *
 * Every Theme method call returns a fresh PHP string. A single render frame
 * produces 60–80 identical ANSI strings; at 30fps that is ~1,800 duplicate
 * allocations per second. By routing through this pool the first call for a
 * given byte sequence stores it, and every subsequent call returns the same
 * reference — eliminating both the allocation and the GC pressure it creates.
 *
 * Usage:
 *   // Low-level — intern any string
 *   $seq = AnsiStringPool::intern(Theme::rgb(255, 200, 80));
 *
 *   // Convenience — intern 24-bit foreground color directly
 *   $seq = AnsiStringPool::fgRgb(255, 200, 80);
 *
 * The pool is static and lives for the entire request. Call {@see clear()}
 * on theme change or when the TUI shuts down.
 *
 * @see docs/plans/tui-overhaul/13-architecture/03-string-interning.md
 */
final class AnsiStringPool
{
    /** @var array<string, string> Cache keyed by "method:serialized(args)" for get() */
    private static array $methodCache = [];

    /** @var array<string, string> Cache keyed by "name:r:g:b" for themeColor() */
    private static array $themeColorCache = [];

    /** @var array<string, string> Keyed by raw bytes; value is the shared reference */
    private static array $pool = [];

    /** @var int Cumulative number of intern hits (lookups that returned an existing entry) */
    private static int $hitCount = 0;

    /** @var int Cumulative number of intern misses (new entries added) */
    private static int $missCount = 0;

    /**
     * Intern an arbitrary string.
     *
     * Returns the same PHP string reference for identical input across all calls,
     * eliminating duplicate allocations. The first call stores the value;
     * subsequent calls return the stored reference via the `$a ??= $a` pattern.
     */
    public static function intern(string $value): string
    {
        if (isset(self::$pool[$value])) {
            ++self::$hitCount;

            return self::$pool[$value];
        }

        ++self::$missCount;

        return self::$pool[$value] = $value;
    }

    /**
     * Get or compute a cached string by method name and arguments.
     *
     * Caches the result of `$producer` keyed by `method + serialize(args)`.
     * On subsequent calls with the same method and args, returns the cached value
     * without invoking the producer again.
     *
     * @param non-empty-string $method Method/operation identifier
     * @param array<mixed> $args Arguments that distinguish this call
     * @param callable(): string $producer Factory that produces the string on cache miss
     */
    public static function get(string $method, array $args, callable $producer): string
    {
        $key = $method . ':' . serialize($args);

        if (isset(self::$methodCache[$key])) {
            ++self::$hitCount;

            return self::$methodCache[$key];
        }

        ++self::$missCount;

        return self::$methodCache[$key] = $producer();
    }

    /**
     * Get a cached Theme::rgb() result for a named color.
     *
     * Avoids repeated Theme::rgb() calls for the same named color by caching
     * the ANSI escape sequence under "name:r:g:b".
     *
     * @param non-empty-string $name Semantic color name (e.g. 'primary', 'accent')
     * @param int<0, 255> $r Red channel
     * @param int<0, 255> $g Green channel
     * @param int<0, 255> $b Blue channel
     */
    public static function themeColor(string $name, int $r, int $g, int $b): string
    {
        $key = "{$name}:{$r}:{$g}:{$b}";

        if (isset(self::$themeColorCache[$key])) {
            ++self::$hitCount;

            return self::$themeColorCache[$key];
        }

        ++self::$missCount;

        return self::$themeColorCache[$key] = \KosmoKrator\UI\Theme::rgb($r, $g, $b);
    }

    /**
     * Intern a 24-bit foreground color escape sequence.
     *
     * Equivalent to `intern("\e[38;2;{$r};{$g};{$b}m")` but avoids
     * building the key string on every hit.
     */
    public static function fgRgb(int $r, int $g, int $b): string
    {
        $key = "\x1b[38;2;{$r};{$g};{$b}m";

        return self::intern($key);
    }

    /**
     * Intern a 24-bit background color escape sequence.
     */
    public static function bgRgb(int $r, int $g, int $b): string
    {
        $key = "\x1b[48;2;{$r};{$g};{$b}m";

        return self::intern($key);
    }

    /**
     * Intern a 256-color foreground escape sequence.
     */
    public static function fg256(int $code): string
    {
        $key = "\x1b[38;5;{$code}m";

        return self::intern($key);
    }

    /**
     * Intern a 256-color background escape sequence.
     */
    public static function bg256(int $code): string
    {
        $key = "\x1b[48;5;{$code}m";

        return self::intern($key);
    }

    /**
     * Intern a Theme-style named method result.
     *
     * Used by Theme cache wrappers: `AnsiStringPool::theme('reset', "\e[0m")`
     * stores the value under a readable key for debugging, but the returned
     * string is the interned ANSI bytes.
     *
     * @param non-empty-string $method Theme method name (e.g. 'reset', 'accent', 'dim')
     */
    public static function theme(string $method, string $ansi): string
    {
        return self::intern($ansi);
    }

    // ── Diagnostics ─────────────────────────────────────────────────────

    /**
     * Return the total number of unique strings held in the pool.
     */
    public static function size(): int
    {
        return count(self::$pool);
    }

    /**
     * Estimated memory consumed by the pool in bytes.
     *
     * Uses `strlen` on every key + value as a rough approximation.
     * Each PHP string also carries ~56 bytes of zval/oparray overhead,
     * but that is not counted here — this is the pure content size.
     */
    public static function estimatedBytes(): int
    {
        $bytes = 0;
        foreach (self::$pool as $key => $value) {
            $bytes += strlen($key) + strlen($value);
        }

        return $bytes;
    }

    /**
     * Return the hit/miss statistics for the pool.
     *
     * A high hit rate (>95%) means the pool is working as intended.
     *
     * @return array{hits: int, misses: int, hit_rate: float}
     */
    public static function stats(): array
    {
        $total = self::$hitCount + self::$missCount;

        return [
            'hits' => self::$hitCount,
            'misses' => self::$missCount,
            'hit_rate' => $total > 0 ? self::$hitCount / $total : 0.0,
        ];
    }

    /**
     * Clear the pool and reset all counters.
     *
     * Call on theme change, `/new` session reset, or TUI teardown.
     */
    public static function clear(): void
    {
        self::$methodCache = [];
        self::$themeColorCache = [];
        self::$pool = [];
        self::$hitCount = 0;
        self::$missCount = 0;
    }
}

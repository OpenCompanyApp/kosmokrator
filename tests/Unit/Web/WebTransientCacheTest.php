<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Web;

use Kosmokrator\Web\Cache\WebTransientCache;
use PHPUnit\Framework\TestCase;

final class WebTransientCacheTest extends TestCase
{
    public function test_cache_expires_after_keep_turns(): void
    {
        $cache = new WebTransientCache(keepTurns: 2, maxEntries: 16);
        $cache->put('a', 'value');

        $this->assertSame('value', $cache->get('a'));

        $cache->advanceTurn();
        $cache->advanceTurn();
        $this->assertSame('value', $cache->get('a'));

        $cache->advanceTurn();
        $this->assertNull($cache->get('a'));
    }

    public function test_remember_uses_cached_value(): void
    {
        $cache = new WebTransientCache(keepTurns: 2, maxEntries: 16);
        $calls = 0;

        $first = $cache->remember('key', function () use (&$calls): string {
            $calls++;

            return 'cached';
        });
        $second = $cache->remember('key', function () use (&$calls): string {
            $calls++;

            return 'new';
        });

        $this->assertSame('cached', $first);
        $this->assertSame('cached', $second);
        $this->assertSame(1, $calls);
    }
}

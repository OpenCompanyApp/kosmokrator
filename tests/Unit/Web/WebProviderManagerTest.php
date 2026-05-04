<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Web;

use Illuminate\Config\Repository;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Web\Cache\WebTransientCache;
use Kosmokrator\Web\Contracts\WebFetchProvider;
use Kosmokrator\Web\Contracts\WebSearchProvider;
use Kosmokrator\Web\Exception\WebFetchPermanentException;
use Kosmokrator\Web\Provider\WebFetchProviderManager;
use Kosmokrator\Web\Provider\WebSearchProviderManager;
use Kosmokrator\Web\Value\WebFetchRequest;
use Kosmokrator\Web\Value\WebFetchResponse;
use Kosmokrator\Web\Value\WebSearchHit;
use Kosmokrator\Web\Value\WebSearchRequest;
use Kosmokrator\Web\Value\WebSearchResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebProviderManagerTest extends TestCase
{
    public function test_search_manager_uses_cache_for_identical_request(): void
    {
        $state = (object) ['calls' => 0];
        $provider = new class($state) implements WebSearchProvider
        {
            public function __construct(private readonly object $state) {}

            public function id(): string
            {
                return 'tavily';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function search(WebSearchRequest $request): WebSearchResponse
            {
                $this->state->calls++;

                return new WebSearchResponse(
                    provider: 'tavily',
                    query: $request->query,
                    results: [new WebSearchHit('Result', 'https://example.com', 'Snippet')],
                );
            }
        };

        $manager = new WebSearchProviderManager(
            [$provider],
            $this->makeSettingsManager(),
            new WebTransientCache(keepTurns: 2, maxEntries: 16),
        );

        $request = new WebSearchRequest('phpunit');
        $manager->search($request);
        $manager->search($request);

        $this->assertSame(1, $state->calls);
    }

    public function test_fetch_manager_does_not_fallback_after_permanent_failure(): void
    {
        $state = (object) ['fallback_calls' => 0];

        $first = new class implements WebFetchProvider
        {
            public function id(): string
            {
                return 'direct';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function fetch(WebFetchRequest $request): WebFetchResponse
            {
                throw new WebFetchPermanentException('Direct fetch failed (404) for '.$request->url.'.');
            }
        };

        $fallback = new class($state) implements WebFetchProvider
        {
            public function __construct(private readonly object $state) {}

            public function id(): string
            {
                return 'zai';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function fetch(WebFetchRequest $request): WebFetchResponse
            {
                $this->state->fallback_calls++;

                throw new \RuntimeException('Should not be called');
            }
        };

        $manager = new WebFetchProviderManager(
            [$first, $fallback],
            $this->makeSettingsManager(),
            new WebTransientCache(keepTurns: 2, maxEntries: 16),
        );

        $this->expectException(WebFetchPermanentException::class);

        try {
            $manager->fetch(new WebFetchRequest(url: 'https://example.com/missing'));
        } finally {
            $this->assertSame(0, $state->fallback_calls);
        }
    }

    public function test_search_manager_hard_filters_allowed_domains(): void
    {
        $provider = new class implements WebSearchProvider
        {
            public function id(): string
            {
                return 'zai';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function search(WebSearchRequest $request): WebSearchResponse
            {
                return new WebSearchResponse(
                    provider: 'zai',
                    query: $request->query,
                    results: [
                        new WebSearchHit('Good', 'https://symfony.com/doc', 'good'),
                        new WebSearchHit('Wrong', 'https://github.com/symfony/symfony', 'wrong'),
                    ],
                );
            }
        };

        $manager = new WebSearchProviderManager(
            [$provider],
            $this->makeSettingsManager(),
            new WebTransientCache(keepTurns: 2, maxEntries: 16),
        );

        $response = $manager->search(new WebSearchRequest(
            query: 'symfony routing',
            allowedDomains: ['symfony.com'],
        ));

        $this->assertCount(1, $response->results);
        $this->assertSame('https://symfony.com/doc', $response->results[0]->url);
    }

    public function test_fetch_manager_provider_only_excludes_direct_without_explicit_override(): void
    {
        $state = (object) ['direct_calls' => 0, 'zai_calls' => 0];

        $direct = new class($state) implements WebFetchProvider
        {
            public function __construct(private readonly object $state) {}

            public function id(): string
            {
                return 'direct';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function fetch(WebFetchRequest $request): WebFetchResponse
            {
                $this->state->direct_calls++;

                throw new \RuntimeException('Direct should not be used');
            }
        };

        $zai = new class($state) implements WebFetchProvider
        {
            public function __construct(private readonly object $state) {}

            public function id(): string
            {
                return 'zai';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function fetch(WebFetchRequest $request): WebFetchResponse
            {
                $this->state->zai_calls++;

                return new WebFetchResponse(
                    provider: 'zai',
                    url: $request->url,
                    finalUrl: $request->url,
                    statusCode: 200,
                    contentType: 'text/plain',
                    format: 'markdown',
                    title: 'Z.AI',
                    metadata: [],
                    outline: [],
                    sections: [],
                    content: 'ok',
                );
            }
        };

        $manager = new WebFetchProviderManager(
            [$direct, $zai],
            $this->makeSettingsManager(),
            new WebTransientCache(keepTurns: 2, maxEntries: 16),
        );

        $response = $manager->fetch(new WebFetchRequest(
            url: 'https://example.com/docs',
            strategy: 'provider_only',
        ));

        $this->assertSame('zai', $response->provider);
        $this->assertSame(0, $state->direct_calls);
        $this->assertSame(1, $state->zai_calls);
    }

    private function makeSettingsManager(): SettingsManager
    {
        $dir = sys_get_temp_dir().'/kosmo-web-tests-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);

        return new SettingsManager(
            new Repository([
                'kosmo' => [
                    'web' => [
                        'search' => ['default_provider' => 'tavily', 'fallback_providers' => []],
                        'fetch' => ['default_provider' => 'direct', 'fallback_providers' => []],
                    ],
                ],
            ]),
            new SettingsSchema,
            new YamlConfigStore(new NullLogger),
            $dir,
        );
    }
}

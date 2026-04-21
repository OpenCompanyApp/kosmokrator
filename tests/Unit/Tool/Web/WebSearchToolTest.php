<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Web;

use Illuminate\Config\Repository;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Tool\Web\WebSearchTool;
use Kosmokrator\Web\Cache\WebTransientCache;
use Kosmokrator\Web\Contracts\WebSearchProvider;
use Kosmokrator\Web\Provider\WebSearchProviderManager;
use Kosmokrator\Web\Value\WebSearchHit;
use Kosmokrator\Web\Value\WebSearchRequest;
use Kosmokrator\Web\Value\WebSearchResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebSearchToolTest extends TestCase
{
    public function test_formats_search_results(): void
    {
        $provider = new class implements WebSearchProvider
        {
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
                return new WebSearchResponse(
                    provider: 'tavily',
                    query: $request->query,
                    results: [new WebSearchHit('PHPUnit docs', 'https://phpunit.de', 'Assertions and testing')],
                    answer: 'PHPUnit is the standard testing framework for PHP.',
                );
            }
        };

        $tool = new WebSearchTool(
            new WebSearchProviderManager([$provider], $this->makeSettingsManager(), new WebTransientCache),
            $this->makeSettingsManager(),
        );

        $result = $tool->execute(['query' => 'phpunit']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Provider: tavily', $result->output);
        $this->assertStringContainsString('Answer:', $result->output);
        $this->assertStringContainsString('PHPUnit docs', $result->output);
        $this->assertStringContainsString('https://phpunit.de', $result->output);
        $this->assertSame('PHPUnit is the standard testing framework for PHP.', $result->metadata['answer'] ?? null);
    }

    public function test_provider_parameter_only_lists_available_providers(): void
    {
        $available = new class implements WebSearchProvider
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
                throw new \RuntimeException('not used');
            }
        };

        $unavailable = new class implements WebSearchProvider
        {
            public function id(): string
            {
                return 'exa';
            }

            public function isAvailable(): bool
            {
                return false;
            }

            public function search(WebSearchRequest $request): WebSearchResponse
            {
                throw new \RuntimeException('not used');
            }
        };

        $tool = new WebSearchTool(
            new WebSearchProviderManager([$available, $unavailable], $this->makeSettingsManager(), new WebTransientCache),
            $this->makeSettingsManager(),
        );

        $providerParameter = $tool->parameters()['provider'];

        $this->assertArrayHasKey('options', $providerParameter);
        /** @var array{type: string, description: string, options: list<string>} $providerParameter */
        $this->assertSame(['zai'], $providerParameter['options']);
    }

    private function makeSettingsManager(): SettingsManager
    {
        $dir = sys_get_temp_dir().'/kosmo-web-search-tool-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);

        return new SettingsManager(
            new Repository([
                'kosmokrator' => [
                    'web' => [
                        'search' => ['default_provider' => 'tavily', 'fallback_providers' => [], 'max_results' => 5],
                        'fetch' => ['default_provider' => 'direct', 'fallback_providers' => [], 'max_chars' => 12000],
                    ],
                ],
            ]),
            new SettingsSchema,
            new YamlConfigStore(new NullLogger),
            $dir,
        );
    }
}

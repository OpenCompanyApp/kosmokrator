<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Web;

use Illuminate\Config\Repository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebProviderException;
use Kosmokrator\Web\WebProviderRegistry;
use PHPUnit\Framework\TestCase;

final class WebProviderRegistryTest extends TestCase
{
    public function test_lists_supported_provider_statuses(): void
    {
        $registry = new WebProviderRegistry($this->config(), new InMemoryWebSettings);
        $statuses = $registry->statuses();

        $names = array_column($statuses, 'name');
        $this->assertContains('tavily', $names);
        $this->assertContains('firecrawl', $names);
        $this->assertContains('openai_native', $names);
        $this->assertContains('anthropic_native', $names);
        $this->assertTrue($statuses[array_search('jina', $names, true)]['configured']);
    }

    public function test_resolves_configured_search_provider(): void
    {
        $config = $this->config([
            'web' => [
                'search' => ['provider' => 'brave'],
                'providers' => [
                    'brave' => ['enabled' => true, 'api_key' => 'test-key', 'api_url' => 'https://api.search.brave.com'],
                ],
            ],
        ]);
        $registry = new WebProviderRegistry($config, new InMemoryWebSettings);

        $provider = $registry->searchProvider();

        $this->assertSame('brave', $provider->name());
        $this->assertTrue($provider->supports(WebCapability::Search));
        $this->assertTrue($registry->enabled('brave'));
        $this->assertTrue($registry->isConfigured('brave'));
    }

    public function test_rejects_native_fetch_as_external_provider(): void
    {
        $registry = new WebProviderRegistry($this->config(), new InMemoryWebSettings);

        $this->expectException(WebProviderException::class);
        $this->expectExceptionMessage('Native web_fetch');

        $registry->fetchProvider('native');
    }

    public function test_uses_stored_llm_key_for_native_provider(): void
    {
        $settings = new InMemoryWebSettings([
            'global' => [
                'provider.openai.api_key' => 'stored-openai-key',
            ],
        ]);
        $registry = new WebProviderRegistry($this->config(), $settings);

        $this->assertTrue($registry->isConfigured('openai_native'));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function config(array $override = []): Repository
    {
        $base = [
            'web' => [
                'search' => ['provider' => null],
                'fetch' => ['provider' => 'native'],
                'crawl' => ['provider' => null],
                'native' => [
                    'mode' => 'cached',
                    'max_uses' => 5,
                    'openai' => ['model' => 'gpt-5'],
                    'anthropic' => ['model' => 'claude-sonnet-4-20250514'],
                ],
                'providers' => [
                    'tavily' => ['enabled' => false, 'api_key_env' => 'TAVILY_API_KEY', 'api_url' => 'https://api.tavily.com'],
                    'firecrawl' => ['enabled' => false, 'api_key_env' => 'FIRECRAWL_API_KEY', 'api_url' => 'https://api.firecrawl.dev'],
                    'exa' => ['enabled' => false, 'api_key_env' => 'EXA_API_KEY', 'api_url' => 'https://api.exa.ai'],
                    'brave' => ['enabled' => false, 'api_key_env' => 'BRAVE_SEARCH_API_KEY', 'api_url' => 'https://api.search.brave.com'],
                    'parallel' => ['enabled' => false, 'api_key_env' => 'PARALLEL_API_KEY', 'api_url' => 'https://api.parallel.ai'],
                    'jina' => ['enabled' => false, 'api_key_env' => 'JINA_API_KEY'],
                    'searxng' => ['enabled' => false, 'base_url' => null],
                    'perplexity' => ['enabled' => false, 'api_key_env' => 'PERPLEXITY_API_KEY', 'api_url' => 'https://api.perplexity.ai'],
                    'openai_native' => ['enabled' => false, 'api_key_env' => 'OPENAI_API_KEY', 'api_url' => 'https://api.openai.com/v1'],
                    'anthropic_native' => ['enabled' => false, 'api_key_env' => 'ANTHROPIC_API_KEY', 'api_url' => 'https://api.anthropic.com'],
                ],
            ],
        ];

        return new Repository(['kosmokrator' => array_replace_recursive($base, $override)]);
    }
}

final class InMemoryWebSettings implements SettingsRepositoryInterface
{
    /**
     * @param  array<string, array<string, string>>  $values
     */
    public function __construct(private array $values = []) {}

    public function get(string $scope, string $key): ?string
    {
        return $this->values[$scope][$key] ?? null;
    }

    public function set(string $scope, string $key, string $value): void
    {
        $this->values[$scope][$key] = $value;
    }

    public function all(string $scope): array
    {
        return $this->values[$scope] ?? [];
    }

    public function delete(string $scope, string $key): void
    {
        unset($this->values[$scope][$key]);
    }

    public function resolve(string $key, string $projectScope): ?string
    {
        return $this->values[$projectScope][$key] ?? $this->values['global'][$key] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Kosmokrator\LLM\ProviderAuthService;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Web\Cache\WebTransientCache;
use Kosmokrator\Web\Contracts\WebFetchProvider;
use Kosmokrator\Web\Contracts\WebSearchProvider;
use Kosmokrator\Web\Extract\HtmlPageExtractor;
use Kosmokrator\Web\Extract\MarkdownPageExtractor;
use Kosmokrator\Web\Mcp\McpToolInvokerInterface;
use Kosmokrator\Web\Mcp\StreamableMcpToolInvoker;
use Kosmokrator\Web\Provider\Fetch\DirectFetchProvider;
use Kosmokrator\Web\Provider\Fetch\ZaiReaderFetchProvider;
use Kosmokrator\Web\Provider\Search\TavilySearchProvider;
use Kosmokrator\Web\Provider\Search\ZaiMcpSearchProvider;
use Kosmokrator\Web\Provider\WebFetchProviderManager;
use Kosmokrator\Web\Provider\WebSearchProviderManager;
use Kosmokrator\Web\Safety\WebRequestGuard;

final class WebServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->container->make('config');

        $this->container->singleton(WebTransientCache::class, fn () => new WebTransientCache(
            keepTurns: max(1, (int) $config->get('kosmokrator.web.cache.keep_turns', 2)),
            maxEntries: max(16, (int) $config->get('kosmokrator.web.cache.max_entries', 128)),
        ));
        $this->container->singleton(WebRequestGuard::class);
        $this->container->singleton(HtmlPageExtractor::class);
        $this->container->singleton(MarkdownPageExtractor::class);
        $this->container->singleton(McpToolInvokerInterface::class, fn () => new StreamableMcpToolInvoker);

        $this->container->singleton(TavilySearchProvider::class, function () use ($config) {
            return new TavilySearchProvider(
                apiKey: $config->get('kosmokrator.web.search.providers.tavily.api_key'),
            );
        });

        $this->container->singleton(ZaiMcpSearchProvider::class, function () use ($config) {
            return new ZaiMcpSearchProvider(
                invoker: $this->container->make(McpToolInvokerInterface::class),
                auth: $this->container->make(ProviderAuthService::class),
                apiKeyOverride: $config->get('kosmokrator.web.search.providers.zai.api_key'),
                remoteUrl: (string) $config->get('kosmokrator.web.search.providers.zai.remote_url', 'https://api.z.ai/api/mcp/web_search_prime/mcp'),
            );
        });

        $this->container->singleton(DirectFetchProvider::class, function () use ($config) {
            return new DirectFetchProvider(
                $this->container->make(WebRequestGuard::class),
                $this->container->make(HtmlPageExtractor::class),
                (int) $config->get('kosmokrator.web.fetch.timeout', 20),
                (int) $config->get('kosmokrator.web.fetch.max_bytes', 10_485_760),
            );
        });

        $this->container->singleton(ZaiReaderFetchProvider::class, function () use ($config) {
            return new ZaiReaderFetchProvider(
                auth: $this->container->make(ProviderAuthService::class),
                guard: $this->container->make(WebRequestGuard::class),
                extractor: $this->container->make(MarkdownPageExtractor::class),
                baseUrl: (string) $config->get('kosmokrator.web.fetch.providers.zai.base_url', 'https://api.z.ai/api/coding/paas/v4'),
                apiKeyOverride: $config->get('kosmokrator.web.fetch.providers.zai.api_key'),
                defaultTimeout: (int) $config->get('kosmokrator.web.fetch.timeout', 20),
            );
        });

        $this->container->singleton(WebSearchProviderManager::class, function () {
            return new WebSearchProviderManager(
                providers: [
                    $this->container->make(TavilySearchProvider::class),
                    $this->container->make(ZaiMcpSearchProvider::class),
                ],
                settings: $this->container->make(SettingsManager::class),
                cache: $this->container->make(WebTransientCache::class),
            );
        });

        $this->container->singleton(WebFetchProviderManager::class, function () {
            return new WebFetchProviderManager(
                providers: [
                    $this->container->make(DirectFetchProvider::class),
                    $this->container->make(ZaiReaderFetchProvider::class),
                ],
                settings: $this->container->make(SettingsManager::class),
                cache: $this->container->make(WebTransientCache::class),
            );
        });

        $this->container->alias(TavilySearchProvider::class, WebSearchProvider::class);
        $this->container->alias(DirectFetchProvider::class, WebFetchProvider::class);
    }
}

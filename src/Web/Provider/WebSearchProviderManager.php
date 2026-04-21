<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Web\Cache\WebTransientCache;
use Kosmokrator\Web\Contracts\WebSearchProvider;
use Kosmokrator\Web\Value\WebSearchRequest;
use Kosmokrator\Web\Value\WebSearchResponse;

final class WebSearchProviderManager
{
    /** @param iterable<WebSearchProvider> $providers */
    public function __construct(
        iterable $providers,
        private readonly SettingsManager $settings,
        private readonly WebTransientCache $cache,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->id()] = $provider;
        }
    }

    /** @var array<string, WebSearchProvider> */
    private array $providers = [];

    /**
     * @return list<string>
     */
    public function availableProviderIds(): array
    {
        $available = [];

        foreach ($this->providers as $providerId => $provider) {
            if ($provider->isAvailable()) {
                $available[] = $providerId;
            }
        }

        return $available;
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $providerIds = $this->candidateProviders($request->provider);
        $errors = [];

        foreach ($providerIds as $providerId) {
            $provider = $this->providers[$providerId] ?? null;
            if ($provider === null || ! $provider->isAvailable()) {
                continue;
            }

            $cacheKey = 'web_search:'.$providerId.':'.hash('sha256', json_encode($request->toCachePayload(), JSON_THROW_ON_ERROR));

            try {
                /** @var WebSearchResponse $response */
                $response = $this->cache->remember($cacheKey, fn () => $provider->search($request));
                $response = $this->normalizeResponse($response, $request, $cacheKey);

                if ($response->results === [] && trim((string) $response->answer) === '') {
                    $errors[] = "{$providerId}: empty result set";

                    continue;
                }

                return $response;
            } catch (\Throwable $e) {
                $errors[] = "{$providerId}: {$e->getMessage()}";
            }
        }

        $suffix = $errors === [] ? '' : ' Tried providers: '.implode(' | ', $errors);
        throw new \RuntimeException('No available web search provider succeeded.'.$suffix);
    }

    /**
     * @return list<string>
     */
    private function candidateProviders(?string $explicitProvider): array
    {
        if (is_string($explicitProvider) && $explicitProvider !== '') {
            return [$explicitProvider];
        }

        $ordered = [];
        $default = $this->settings->getRaw('kosmokrator.web.search.default_provider');
        if (is_string($default) && $default !== '') {
            $ordered[] = $default;
        }

        $fallbacks = $this->settings->getRaw('kosmokrator.web.search.fallback_providers');
        if (is_array($fallbacks)) {
            foreach ($fallbacks as $fallback) {
                if (is_string($fallback) && $fallback !== '') {
                    $ordered[] = $fallback;
                }
            }
        }

        foreach (array_keys($this->providers) as $providerId) {
            $ordered[] = $providerId;
        }

        return array_values(array_unique($ordered));
    }

    private function normalizeResponse(WebSearchResponse $response, WebSearchRequest $request, string $cacheKey): WebSearchResponse
    {
        $results = [];

        foreach ($response->results as $hit) {
            $host = parse_url($hit->url, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                continue;
            }

            if ($request->allowedDomains !== [] && ! $this->matchesAnyDomain($host, $request->allowedDomains)) {
                continue;
            }

            if ($request->blockedDomains !== [] && $this->matchesAnyDomain($host, $request->blockedDomains)) {
                continue;
            }

            $results[] = $hit;
        }

        if ($request->maxResults > 0) {
            $results = array_slice($results, 0, $request->maxResults);
        }

        return new WebSearchResponse(
            provider: $response->provider,
            query: $response->query,
            results: $results,
            answer: $response->answer,
            meta: array_merge($response->meta, ['cache_key' => $cacheKey]),
        );
    }

    /**
     * @param  list<string>  $domains
     */
    private function matchesAnyDomain(string $host, array $domains): bool
    {
        $host = strtolower($host);

        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}

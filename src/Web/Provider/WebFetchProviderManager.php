<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Web\Cache\WebTransientCache;
use Kosmokrator\Web\Contracts\WebFetchProvider;
use Kosmokrator\Web\Exception\WebFetchPermanentException;
use Kosmokrator\Web\Value\WebFetchRequest;
use Kosmokrator\Web\Value\WebFetchResponse;

final class WebFetchProviderManager
{
    /** @param iterable<WebFetchProvider> $providers */
    public function __construct(
        iterable $providers,
        private readonly SettingsManager $settings,
        private readonly WebTransientCache $cache,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->id()] = $provider;
        }
    }

    /** @var array<string, WebFetchProvider> */
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

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        $providerIds = $this->candidateProviders($request->provider, $request->strategy);
        $errors = [];

        if ($providerIds === []) {
            throw new \RuntimeException('No available web fetch provider matches the requested strategy.');
        }

        foreach ($providerIds as $providerId) {
            $provider = $this->providers[$providerId] ?? null;
            if ($provider === null || ! $provider->isAvailable()) {
                continue;
            }

            $cacheKey = 'web_fetch:'.$providerId.':'.hash('sha256', json_encode($request->toCachePayload(), JSON_THROW_ON_ERROR));

            try {
                /** @var WebFetchResponse $response */
                $response = $this->cache->remember($cacheKey, fn () => $provider->fetch($request));

                return new WebFetchResponse(
                    provider: $response->provider,
                    url: $response->url,
                    finalUrl: $response->finalUrl,
                    statusCode: $response->statusCode,
                    contentType: $response->contentType,
                    format: $response->format,
                    title: $response->title,
                    metadata: $response->metadata,
                    outline: $response->outline,
                    sections: $response->sections,
                    content: $response->content,
                    rawHtml: $response->rawHtml,
                    truncated: $response->truncated,
                    nextChunkToken: $response->nextChunkToken,
                    extractionMethod: $response->extractionMethod,
                    meta: array_merge($response->meta, ['cache_key' => $cacheKey]),
                );
            } catch (WebFetchPermanentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $errors[] = "{$providerId}: {$e->getMessage()}";

                if (($request->strategy === 'direct_only' || $request->strategy === 'provider_only') && $request->provider !== null) {
                    break;
                }
            }
        }

        $suffix = $errors === [] ? '' : ' Tried providers: '.implode(' | ', $errors);
        throw new \RuntimeException('No available web fetch provider succeeded.'.$suffix);
    }

    /**
     * @return list<string>
     */
    private function candidateProviders(?string $explicitProvider, string $strategy): array
    {
        if (is_string($explicitProvider) && $explicitProvider !== '') {
            return [$explicitProvider];
        }

        if ($strategy === 'direct_only') {
            return isset($this->providers['direct']) ? ['direct'] : [];
        }

        $ordered = [];
        $default = $this->settings->getRaw('kosmokrator.web.fetch.default_provider');
        if (is_string($default) && $default !== '') {
            $ordered[] = $default;
        }

        $fallbacks = $this->settings->getRaw('kosmokrator.web.fetch.fallback_providers');
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

        $ordered = array_values(array_unique($ordered));

        if ($strategy !== 'provider_only') {
            return $ordered;
        }

        return array_values(array_filter(
            $ordered,
            static fn (string $providerId): bool => $providerId !== 'direct'
        ));
    }
}

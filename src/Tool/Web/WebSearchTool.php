<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Web;

use Illuminate\Config\Repository;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebProviderRegistry;
use Kosmokrator\Web\WebSearchRequest;

final class WebSearchTool extends AbstractTool
{
    public function __construct(
        private readonly WebProviderRegistry $providers,
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Search the web using an optional configured external provider. Disabled unless web.search.enabled is on.';
    }

    public function parameters(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Search query'],
            'provider' => ['type' => 'enum', 'description' => 'Optional provider override', 'options' => $this->providers->names()],
            'max_results' => ['type' => 'integer', 'description' => 'Maximum results to return. Defaults to web.search.max_results.'],
            'mode' => ['type' => 'enum', 'description' => 'Search mode/depth when supported', 'options' => ['auto', 'fast', 'deep', 'cached', 'live']],
            'allowed_domains' => ['type' => 'array', 'description' => 'Optional allow-list of domains'],
            'blocked_domains' => ['type' => 'array', 'description' => 'Optional block-list of domains'],
            'country' => ['type' => 'string', 'description' => 'Optional ISO country/region hint'],
            'language' => ['type' => 'string', 'description' => 'Optional language hint'],
            'recency' => ['type' => 'string', 'description' => 'Optional recency filter when supported'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['query'];
    }

    protected function handle(array $args): ToolResult
    {
        if (! $this->enabled('kosmokrator.web.search.enabled')) {
            return ToolResult::error('web_search is disabled. Enable it with web.search.enabled and configure web.search.provider.');
        }

        $providerName = is_string($args['provider'] ?? null) && $args['provider'] !== '' ? $args['provider'] : null;
        $provider = $this->providers->searchProvider($providerName);
        if (! $this->providers->enabled($provider->name())) {
            return ToolResult::error("Web provider '{$provider->name()}' is disabled. Enable web.providers.{$provider->name()}.enabled first.");
        }

        $response = $provider->search(new WebSearchRequest(
            query: (string) ($args['query'] ?? ''),
            maxResults: max(1, (int) ($args['max_results'] ?? $this->config->get('kosmokrator.web.search.max_results', 8))),
            timeoutSeconds: max(1, (int) $this->config->get('kosmokrator.web.search.timeout_seconds', 30)),
            outputLimitChars: max(1000, (int) $this->config->get('kosmokrator.web.search.output_limit_chars', 60000)),
            mode: is_string($args['mode'] ?? null) ? $args['mode'] : $this->config->get('kosmokrator.web.native.mode'),
            country: is_string($args['country'] ?? null) ? $args['country'] : null,
            language: is_string($args['language'] ?? null) ? $args['language'] : null,
            recency: is_string($args['recency'] ?? null) ? $args['recency'] : null,
            allowedDomains: $this->stringList($args['allowed_domains'] ?? []),
            blockedDomains: $this->stringList($args['blocked_domains'] ?? []),
        ));

        return ToolResult::successWithMetadata(WebFormatter::search($response), $response->toArray());
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value)));
    }

    private function enabled(string $key): bool
    {
        $value = $this->config->get($key, false);

        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}

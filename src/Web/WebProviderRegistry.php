<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

use Illuminate\Config\Repository;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Web\Provider\AnthropicNativeSearchProvider;
use Kosmokrator\Web\Provider\BraveProvider;
use Kosmokrator\Web\Provider\ExaProvider;
use Kosmokrator\Web\Provider\FirecrawlProvider;
use Kosmokrator\Web\Provider\JinaProvider;
use Kosmokrator\Web\Provider\OpenAiNativeSearchProvider;
use Kosmokrator\Web\Provider\ParallelProvider;
use Kosmokrator\Web\Provider\PerplexitySearchProvider;
use Kosmokrator\Web\Provider\SearxngProvider;
use Kosmokrator\Web\Provider\TavilyProvider;

final class WebProviderRegistry
{
    /** @var array<string, WebProviderInterface> */
    private array $providers = [];

    public function __construct(
        private readonly Repository $config,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ?ProviderCatalog $providerCatalog = null,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return [
            'tavily',
            'firecrawl',
            'exa',
            'brave',
            'parallel',
            'jina',
            'searxng',
            'perplexity',
            'openai_native',
            'anthropic_native',
        ];
    }

    public function provider(string $name): WebProviderInterface
    {
        $name = $this->normalize($name);
        if (! in_array($name, $this->names(), true)) {
            throw new WebProviderException("Unknown web provider [{$name}].");
        }

        return $this->providers[$name] ??= $this->make($name);
    }

    public function searchProvider(?string $requested = null): WebProviderInterface
    {
        $name = $requested !== null && $requested !== ''
            ? $requested
            : (string) $this->config->get('kosmokrator.web.search.provider', '');
        if ($name === '') {
            throw new WebProviderException('No web search provider configured. Set web.search.provider or pass provider explicitly.');
        }

        $provider = $this->provider($name);
        if (! $provider->supports(WebCapability::Search)) {
            throw new WebProviderException($provider->label().' does not support search.');
        }

        return $provider;
    }

    public function fetchProvider(?string $requested = null): WebProviderInterface
    {
        $name = $requested !== null && $requested !== ''
            ? $requested
            : (string) $this->config->get('kosmokrator.web.fetch.provider', 'native');
        if ($name === 'native') {
            throw new WebProviderException('Native web_fetch is handled by the native tool, not external providers.');
        }

        $provider = $this->provider($name);
        if (! $provider->supports(WebCapability::Fetch)) {
            throw new WebProviderException($provider->label().' does not support external fetch/extract.');
        }

        return $provider;
    }

    public function crawlProvider(?string $requested = null): WebProviderInterface
    {
        $name = $requested !== null && $requested !== ''
            ? $requested
            : (string) $this->config->get('kosmokrator.web.crawl.provider', '');
        if ($name === '') {
            throw new WebProviderException('No web crawl provider configured. Set web.crawl.provider or pass provider explicitly.');
        }

        $provider = $this->provider($name);
        if (! $provider->supports(WebCapability::Crawl)) {
            throw new WebProviderException($provider->label().' does not support crawl.');
        }

        return $provider;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statuses(): array
    {
        $rows = [];
        foreach ($this->names() as $name) {
            $provider = $this->provider($name);
            $rows[] = [
                'name' => $name,
                'label' => $provider->label(),
                'enabled' => $this->enabled($name),
                'configured' => $this->isConfigured($name),
                'api_key_env' => $this->apiKeyEnv($name),
                'base_url' => $this->baseUrl($name),
                'capabilities' => array_values(array_filter(
                    array_map(static fn (WebCapability $capability): ?string => $provider->supports($capability) ? $capability->value : null, WebCapability::cases()),
                )),
            ];
        }

        return $rows;
    }

    public function enabled(string $name): bool
    {
        return $this->bool($this->config->get('kosmokrator.web.providers.'.$this->normalize($name).'.enabled', false));
    }

    public function isConfigured(string $name): bool
    {
        $name = $this->normalize($name);
        if ($name === 'searxng') {
            return $this->baseUrl($name) !== null && $this->baseUrl($name) !== '';
        }

        if ($name === 'jina') {
            return true;
        }

        if ($name === 'openai_native') {
            return $this->apiKey($name, 'openai') !== '';
        }

        if ($name === 'anthropic_native') {
            return $this->apiKey($name, 'anthropic') !== '';
        }

        return $this->apiKey($name) !== '';
    }

    private function make(string $name): WebProviderInterface
    {
        return match ($name) {
            'tavily' => new TavilyProvider($this->apiKey($name), $this->baseUrl($name)),
            'firecrawl' => new FirecrawlProvider($this->apiKey($name), $this->baseUrl($name)),
            'exa' => new ExaProvider($this->apiKey($name), $this->baseUrl($name)),
            'brave' => new BraveProvider($this->apiKey($name), $this->baseUrl($name)),
            'parallel' => new ParallelProvider($this->apiKey($name), $this->baseUrl($name)),
            'jina' => new JinaProvider($this->apiKey($name), $this->baseUrl($name)),
            'searxng' => new SearxngProvider('', $this->baseUrl($name)),
            'perplexity' => new PerplexitySearchProvider($this->apiKey($name), $this->baseUrl($name)),
            'openai_native' => new OpenAiNativeSearchProvider(
                $this->apiKey($name, 'openai'),
                $this->baseUrl($name),
                (string) $this->config->get('kosmokrator.web.native.openai.model', $this->config->get('kosmokrator.agent.default_model', 'gpt-5')),
                (string) $this->config->get('kosmokrator.web.native.mode', 'cached'),
            ),
            'anthropic_native' => new AnthropicNativeSearchProvider(
                $this->apiKey($name, 'anthropic'),
                $this->baseUrl($name),
                (string) $this->config->get('kosmokrator.web.native.anthropic.model', 'claude-sonnet-4-20250514'),
                max(1, (int) $this->config->get('kosmokrator.web.native.max_uses', 5)),
            ),
            default => throw new WebProviderException("Unknown web provider [{$name}]."),
        };
    }

    private function apiKey(string $name, ?string $llmProvider = null): string
    {
        $name = $this->normalize($name);
        $stored = $this->settings->get('global', "provider.{$name}.api_key")
            ?? ($llmProvider !== null ? $this->settings->get('global', "provider.{$llmProvider}.api_key") : null);
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        if ($llmProvider !== null && $this->providerCatalog !== null) {
            $catalogKey = trim($this->providerCatalog->apiKey($llmProvider));
            if ($catalogKey !== '') {
                return $catalogKey;
            }
        }

        $configured = $this->config->get("kosmokrator.web.providers.{$name}.api_key");
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $env = $this->apiKeyEnv($name);
        $value = $env !== null ? getenv($env) : false;

        return is_string($value) ? trim($value) : '';
    }

    private function apiKeyEnv(string $name): ?string
    {
        $env = $this->config->get('kosmokrator.web.providers.'.$this->normalize($name).'.api_key_env');

        return is_string($env) && $env !== '' ? $env : null;
    }

    private function baseUrl(string $name): ?string
    {
        $url = $this->config->get('kosmokrator.web.providers.'.$this->normalize($name).'.base_url')
            ?? $this->config->get('kosmokrator.web.providers.'.$this->normalize($name).'.api_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    private function normalize(string $name): string
    {
        return str_replace('-', '_', strtolower(trim($name)));
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes', 'enabled'], true);
    }
}

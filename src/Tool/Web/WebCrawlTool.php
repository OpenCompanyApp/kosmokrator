<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Web;

use Illuminate\Config\Repository;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Kosmokrator\Web\WebCrawlRequest;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebProviderRegistry;

final class WebCrawlTool extends AbstractTool
{
    public function __construct(
        private readonly WebProviderRegistry $providers,
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'web_crawl';
    }

    public function description(): string
    {
        return 'Crawl a website with an enabled external provider. Disabled unless web.crawl.enabled is on.';
    }

    public function parameters(): array
    {
        return [
            'url' => ['type' => 'string', 'description' => 'Starting URL to crawl'],
            'provider' => ['type' => 'enum', 'description' => 'External provider to use', 'options' => $this->providers->names()],
            'max_pages' => ['type' => 'integer', 'description' => 'Maximum pages to return'],
            'instructions' => ['type' => 'string', 'description' => 'Optional crawl instructions when supported'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['url'];
    }

    protected function handle(array $args): ToolResult
    {
        if (! $this->enabled('kosmo.web.crawl.enabled')) {
            return ToolResult::error('web_crawl is disabled. Enable web.crawl.enabled and configure web.crawl.provider.');
        }

        $providerName = is_string($args['provider'] ?? null) && $args['provider'] !== '' ? $args['provider'] : null;
        $provider = $this->providers->crawlProvider($providerName);
        if (! $this->providers->enabled($provider->name())) {
            return ToolResult::error("Web provider '{$provider->name()}' is disabled. Enable web.providers.{$provider->name()}.enabled first.");
        }

        $response = $provider->crawl(new WebCrawlRequest(
            url: (string) ($args['url'] ?? ''),
            maxPages: max(1, (int) ($args['max_pages'] ?? $this->config->get('kosmo.web.crawl.max_pages', 20))),
            timeoutSeconds: max(1, (int) $this->config->get('kosmo.web.crawl.timeout_seconds', 60)),
            outputLimitChars: max(1000, (int) $this->config->get('kosmo.web.crawl.output_limit_chars', 100000)),
            instructions: is_string($args['instructions'] ?? null) ? $args['instructions'] : null,
        ));

        return ToolResult::successWithMetadata(WebFormatter::crawl($response), $response->toArray());
    }

    private function enabled(string $key): bool
    {
        $value = $this->config->get($key, false);

        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}

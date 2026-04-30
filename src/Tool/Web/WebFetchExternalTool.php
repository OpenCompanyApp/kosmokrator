<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Web;

use Illuminate\Config\Repository;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Kosmokrator\Web\WebFetchRequest;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebProviderRegistry;

final class WebFetchExternalTool extends AbstractTool
{
    public function __construct(
        private readonly WebProviderRegistry $providers,
        private readonly Repository $config,
        private readonly string $toolName = 'web_fetch_external',
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Fetch or extract a specific URL using an external provider such as Firecrawl, Tavily, Exa, Jina, or Parallel. Native web_fetch remains the default fetch path.';
    }

    public function parameters(): array
    {
        return [
            'url' => ['type' => 'string', 'description' => 'URL to fetch or extract'],
            'provider' => ['type' => 'enum', 'description' => 'External provider to use', 'options' => $this->providers->names()],
            'format' => ['type' => 'enum', 'description' => 'Requested output format', 'options' => ['markdown', 'text', 'html']],
            'timeout_seconds' => ['type' => 'integer', 'description' => 'Optional timeout in seconds'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['url'];
    }

    protected function handle(array $args): ToolResult
    {
        if (! $this->enabled('kosmo.web.fetch.allow_external')) {
            return ToolResult::error('External web fetch is disabled. Enable web.fetch.allow_external first.');
        }

        $providerName = is_string($args['provider'] ?? null) && $args['provider'] !== '' ? $args['provider'] : null;
        $provider = $this->providers->fetchProvider($providerName);
        if (! $this->providers->enabled($provider->name())) {
            return ToolResult::error("Web provider '{$provider->name()}' is disabled. Enable web.providers.{$provider->name()}.enabled first.");
        }

        $response = $provider->fetch(new WebFetchRequest(
            url: (string) ($args['url'] ?? ''),
            format: is_string($args['format'] ?? null) ? $args['format'] : 'markdown',
            timeoutSeconds: max(1, (int) ($args['timeout_seconds'] ?? $this->config->get('kosmo.web.fetch.timeout_seconds', 30))),
            outputLimitChars: max(1000, (int) $this->config->get('kosmo.web.fetch.output_limit_chars', 100000)),
        ));

        return ToolResult::successWithMetadata(WebFormatter::fetch($response), $response->toArray());
    }

    private function enabled(string $key): bool
    {
        $value = $this->config->get($key, false);

        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}

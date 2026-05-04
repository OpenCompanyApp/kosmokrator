<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Web;

use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Kosmokrator\Web\Provider\WebSearchProviderManager;
use Kosmokrator\Web\Value\WebSearchRequest;

final class WebSearchTool extends AbstractTool
{
    public function __construct(
        private readonly WebSearchProviderManager $providers,
        private readonly SettingsManager $settings,
    ) {}

    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return <<<'DESC'
Search the web with a provider-backed search tool.

Use this to discover relevant sources before fetching them. For multi-source research, prefer spawning subagents to search and inspect different sources in parallel, then synthesize the results in the parent agent.

After finding promising URLs, use web_fetch in metadata, outline, or section mode to load only the parts you actually need.
DESC;
    }

    public function parameters(): array
    {
        $availableProviders = $this->providers->availableProviderIds();

        return [
            'query' => ['type' => 'string', 'description' => 'Search query.'],
            'provider' => ['type' => 'enum', 'description' => 'Optional provider override.', 'options' => $availableProviders],
            'max_results' => ['type' => 'integer', 'description' => 'Maximum number of search results to return. Defaults to 5.'],
            'allowed_domains' => ['type' => 'array', 'description' => 'Optional domain allowlist, e.g. ["docs.python.org", "developer.mozilla.org"].'],
            'blocked_domains' => ['type' => 'array', 'description' => 'Optional domain blocklist.'],
            'search_depth' => ['type' => 'enum', 'description' => 'Provider-specific depth hint. Defaults to basic.', 'options' => ['basic', 'advanced']],
            'include_snippets' => ['type' => 'boolean', 'description' => 'Include result snippets when the provider supports them. Defaults to true.'],
            'include_answer' => ['type' => 'boolean', 'description' => 'Request an answer/summary from the provider when supported. Defaults to false.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['query'];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function handle(array $args): ToolResult
    {
        $request = new WebSearchRequest(
            query: trim((string) ($args['query'] ?? '')),
            provider: $this->nullableString($args['provider'] ?? null),
            maxResults: max(1, min(10, (int) ($args['max_results'] ?? ($this->settings->getRaw('kosmo.web.search.max_results') ?? 5)))),
            allowedDomains: $this->stringList($args['allowed_domains'] ?? []),
            blockedDomains: $this->stringList($args['blocked_domains'] ?? []),
            searchDepth: in_array((string) ($args['search_depth'] ?? 'basic'), ['basic', 'advanced'], true) ? (string) ($args['search_depth'] ?? 'basic') : 'basic',
            includeSnippets: filter_var($args['include_snippets'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            includeAnswer: filter_var($args['include_answer'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
        );

        if ($request->query === '') {
            return ToolResult::error('Search query is required.');
        }

        $response = $this->providers->search($request);

        $lines = [
            "Provider: {$response->provider}",
            "Query: {$response->query}",
            'Results: '.count($response->results),
        ];

        if ($response->answer !== null && trim($response->answer) !== '') {
            $lines[] = '';
            $lines[] = 'Answer:';
            $lines[] = trim($response->answer);
        }

        if ($response->results !== []) {
            $lines[] = '';
            $lines[] = 'Top results:';

            foreach ($response->results as $index => $hit) {
                $lines[] = sprintf('%d. %s', $index + 1, $hit->title);
                $lines[] = "   URL: {$hit->url}";
                if ($hit->snippet !== '') {
                    $lines[] = '   Snippet: '.$this->truncate($hit->snippet, 280);
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Use web_fetch in metadata, outline, or section mode to inspect only the relevant parts of a result page.';

        return ToolResult::successWithMetadata(
            implode("\n", $lines),
            [
                'provider' => $response->provider,
                'query' => $response->query,
                'answer' => $response->answer,
                'results' => array_map(static fn ($hit): array => [
                    'title' => $hit->title,
                    'url' => $hit->url,
                    'snippet' => $hit->snippet,
                    'score' => $hit->score,
                    'published_at' => $hit->publishedAt,
                ], $response->results),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return array_values(array_unique($items));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit).'...';
    }
}

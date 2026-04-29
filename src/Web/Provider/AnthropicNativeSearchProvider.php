<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebSearchItem;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

final class AnthropicNativeSearchProvider extends AbstractHttpWebProvider
{
    public function __construct(
        string $apiKey = '',
        ?string $baseUrl = null,
        private readonly string $model = 'claude-sonnet-4-20250514',
        private readonly int $maxUses = 5,
    ) {
        parent::__construct($apiKey, $baseUrl);
    }

    public function name(): string
    {
        return 'anthropic_native';
    }

    public function label(): string
    {
        return 'Anthropic native web search';
    }

    public function supports(WebCapability $capability): bool
    {
        return $capability === WebCapability::Search;
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $tool = [
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => $this->maxUses,
        ];
        if ($request->allowedDomains !== []) {
            $tool['allowed_domains'] = $request->allowedDomains;
        } elseif ($request->blockedDomains !== []) {
            $tool['blocked_domains'] = $request->blockedDomains;
        }

        $data = $this->postJson($this->url('https://api.anthropic.com').'/v1/messages', [
            'model' => $this->model,
            'max_tokens' => 2048,
            'messages' => [
                ['role' => 'user', 'content' => 'Search the web and cite sources for: '.$request->query],
            ],
            'tools' => [$tool],
        ], [
            'x-api-key' => $this->requireApiKey(),
            'anthropic-version' => '2023-06-01',
        ], $request->timeoutSeconds);

        $answer = $this->extractText($data);
        $results = $this->extractCitations($data, $answer);
        if ($results === []) {
            $results[] = new WebSearchItem('Anthropic native web search response', '', WebFormatter::limit($answer, $request->outputLimitChars));
        }

        return new WebSearchResponse($this->name(), $request->query, $results, WebFormatter::limit($answer, $request->outputLimitChars), ['id' => $data['id'] ?? null, 'usage' => $data['usage'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractText(array $data): string
    {
        $parts = [];
        foreach (is_array($data['content'] ?? null) ? $data['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<WebSearchItem>
     */
    private function extractCitations(array $data, string $answer): array
    {
        $results = [];
        foreach (is_array($data['content'] ?? null) ? $data['content'] : [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach (is_array($block['citations'] ?? null) ? $block['citations'] : [] as $citation) {
                if (! is_array($citation)) {
                    continue;
                }
                $url = $this->string($citation, 'url');
                if ($url === '') {
                    continue;
                }
                $results[$url] = new WebSearchItem($this->string($citation, 'title', $url), $url, WebFormatter::limit($answer, 700));
            }
        }

        return array_values($results);
    }
}

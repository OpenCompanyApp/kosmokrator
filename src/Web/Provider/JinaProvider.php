<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Web\UrlSafety;
use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebFetchRequest;
use Kosmokrator\Web\WebFetchResponse;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebSearchItem;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

final class JinaProvider extends AbstractHttpWebProvider
{
    public function name(): string
    {
        return 'jina';
    }

    public function label(): string
    {
        return 'Jina Reader';
    }

    public function supports(WebCapability $capability): bool
    {
        return in_array($capability, [WebCapability::Search, WebCapability::Fetch], true);
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $text = $this->getText('https://s.jina.ai/'.rawurlencode($request->query), $this->headers(), $request->timeoutSeconds);
        $results = [];
        foreach ($this->linksFromMarkdown($text, $request->maxResults) as $link) {
            $results[] = new WebSearchItem($link['title'], $link['url'], $link['snippet']);
        }

        if ($results === []) {
            $results[] = new WebSearchItem('Jina search response', '', WebFormatter::limit($text, $request->outputLimitChars));
        }

        return new WebSearchResponse($this->name(), $request->query, $results, metadata: ['raw_response' => WebFormatter::limit($text, $request->outputLimitChars)]);
    }

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        UrlSafety::assertSafe($request->url);
        $content = $this->getText('https://r.jina.ai/'.preg_replace('#^https?://#', 'http://', $request->url), $this->headers(), $request->timeoutSeconds);

        return new WebFetchResponse($this->name(), $request->url, WebFormatter::limit($content, $request->outputLimitChars), 'markdown');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return $this->apiKey === '' ? [] : ['Authorization' => 'Bearer '.$this->apiKey];
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function linksFromMarkdown(string $text, int $limit): array
    {
        preg_match_all('/\[(?<title>[^\]]+)\]\((?<url>https?:\/\/[^)]+)\)(?<tail>[^\n]*)/', $text, $matches, PREG_SET_ORDER);
        $links = [];
        foreach (array_slice($matches, 0, max(1, $limit)) as $match) {
            $links[] = [
                'title' => trim($match['title']),
                'url' => trim($match['url']),
                'snippet' => trim($match['tail'] ?? ''),
            ];
        }

        return $links;
    }
}

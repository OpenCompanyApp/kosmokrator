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

final class ExaProvider extends AbstractHttpWebProvider
{
    public function name(): string
    {
        return 'exa';
    }

    public function label(): string
    {
        return 'Exa';
    }

    public function supports(WebCapability $capability): bool
    {
        return in_array($capability, [WebCapability::Search, WebCapability::Fetch], true);
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $data = $this->postJson($this->url('https://api.exa.ai').'/search', [
            'query' => $request->query,
            'numResults' => max(1, min(20, $request->maxResults)),
            'type' => in_array($request->mode, ['auto', 'fast', 'deep'], true) ? $request->mode : 'auto',
            'contents' => ['text' => ['maxCharacters' => 1000]],
        ], ['x-api-key' => $this->requireApiKey()], $request->timeoutSeconds);

        $results = [];
        foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $results[] = new WebSearchItem(
                title: $this->string($item, 'title', $this->string($item, 'url')),
                url: $this->string($item, 'url'),
                snippet: $this->string($item, 'text'),
                score: isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : null,
                publishedAt: $this->string($item, 'publishedDate') ?: null,
            );
        }

        return new WebSearchResponse($this->name(), $request->query, $results, metadata: ['requestId' => $data['requestId'] ?? null]);
    }

    public function fetch(WebFetchRequest $request): WebFetchResponse
    {
        UrlSafety::assertSafe($request->url);
        $data = $this->postJson($this->url('https://api.exa.ai').'/contents', [
            'urls' => [$request->url],
            'text' => ['maxCharacters' => $request->outputLimitChars],
            'livecrawl' => 'fallback',
        ], ['x-api-key' => $this->requireApiKey()], $request->timeoutSeconds);

        $item = is_array(($data['results'] ?? [])[0] ?? null) ? $data['results'][0] : [];

        return new WebFetchResponse($this->name(), $request->url, WebFormatter::limit($this->string($item, 'text'), $request->outputLimitChars), 'text', $this->string($item, 'title') ?: null);
    }
}

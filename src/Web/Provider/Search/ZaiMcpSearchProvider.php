<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider\Search;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Kosmokrator\LLM\ProviderAuthService;
use Kosmokrator\Web\Contracts\WebSearchProvider;
use Kosmokrator\Web\Mcp\McpToolInvokerInterface;
use Kosmokrator\Web\Value\WebSearchHit;
use Kosmokrator\Web\Value\WebSearchRequest;
use Kosmokrator\Web\Value\WebSearchResponse;

final class ZaiMcpSearchProvider implements WebSearchProvider
{
    private readonly HttpClient $httpClient;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    public function __construct(
        private readonly McpToolInvokerInterface $invoker,
        private readonly ProviderAuthService $auth,
        private readonly ?string $apiKeyOverride = null,
        private readonly string $remoteUrl = 'https://api.z.ai/api/mcp/web_search_prime/mcp',
        private readonly string $chatBaseUrl = 'https://api.z.ai/api/coding/paas/v4',
        private readonly array $rateLimitRetryDelays = [1, 2],
        ?HttpClient $httpClient = null,
        ?callable $sleep = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClientBuilder::buildDefault();
        if ($sleep instanceof \Closure) {
            $this->sleep = static function (int $seconds) use ($sleep): void {
                $sleep($seconds);
            };

            return;
        }

        if ($sleep !== null) {
            $callback = \Closure::fromCallable($sleep);
            $this->sleep = static function (int $seconds) use ($callback): void {
                $callback($seconds);
            };

            return;
        }

        $this->sleep = static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function id(): string
    {
        return 'zai';
    }

    public function isAvailable(): bool
    {
        return $this->resolveApiKey() !== '';
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('Z.AI web search is not configured.');
        }

        $answer = null;
        $transport = 'remote_mcp';
        $results = $this->searchViaMcp($request, $apiKey);

        if ($results === [] || $this->shouldPreferChatSearch($request) || ($request->includeAnswer && trim((string) $answer) === '')) {
            ['results' => $chatResults, 'answer' => $chatAnswer] = $this->searchViaChatSearch($request, $apiKey);

            if ($chatResults !== []) {
                $results = $chatResults;
                $transport = 'chat_search';
            }

            if (trim((string) $chatAnswer) !== '') {
                $answer = $chatAnswer;
            }
        }

        if ($request->maxResults > 0) {
            $results = array_slice($results, 0, $request->maxResults);
        }

        return new WebSearchResponse(
            provider: $this->id(),
            query: $request->query,
            results: $results,
            answer: $answer,
            meta: [
                'transport' => $transport,
                'remote_url' => $this->remoteUrl,
            ],
        );
    }

    private function resolveApiKey(): string
    {
        $key = $this->apiKeyOverride;
        if (is_string($key) && trim($key) !== '') {
            return trim($key);
        }

        return trim($this->auth->apiKey('z'));
    }

    /**
     * @param  list<string>  $blockedDomains
     */
    private function isBlockedDomain(string $url, array $blockedDomains): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        foreach ($blockedDomains as $blockedDomain) {
            $blockedDomain = strtolower(trim($blockedDomain));
            if ($blockedDomain === '') {
                continue;
            }

            if ($host === $blockedDomain || str_ends_with($host, '.'.$blockedDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<WebSearchHit>
     */
    private function searchViaMcp(WebSearchRequest $request, string $apiKey): array
    {
        $attempt = 0;

        while (true) {
            try {
                $arguments = [
                    'search_query' => $request->query,
                    'content_size' => $request->searchDepth === 'advanced' ? 'high' : 'medium',
                ];

                if ($request->allowedDomains !== []) {
                    $arguments['search_domain_filter'] = implode(',', $request->allowedDomains);
                }

                $payload = $this->invoker->call(
                    $this->remoteUrl,
                    'web_search_prime',
                    $arguments,
                    ['Authorization' => 'Bearer '.$apiKey],
                );

                if (! is_array($payload)) {
                    return [];
                }

                $results = [];
                foreach ($payload as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $url = (string) ($item['link'] ?? '');
                    if ($url === '') {
                        continue;
                    }

                    if ($request->blockedDomains !== [] && $this->isBlockedDomain($url, $request->blockedDomains)) {
                        continue;
                    }

                    $results[] = new WebSearchHit(
                        title: (string) ($item['title'] ?? $url),
                        url: $url,
                        snippet: $request->includeSnippets ? trim((string) ($item['content'] ?? '')) : '',
                        score: null,
                        publishedAt: isset($item['publish_date']) ? (string) $item['publish_date'] : null,
                        source: (string) ($item['media'] ?? 'zai'),
                    );
                }

                return $results;
            } catch (\Throwable $e) {
                if (! $this->isRateLimitError($e->getMessage())) {
                    return [];
                }

                if (! isset($this->rateLimitRetryDelays[$attempt])) {
                    throw new \RuntimeException('Z.AI web search is rate limited. Please retry shortly.', 0, $e);
                }

                ($this->sleep)((int) $this->rateLimitRetryDelays[$attempt]);
                $attempt++;
            }
        }
    }

    /**
     * @return list<WebSearchHit>
     */
    /**
     * @return array{results: list<WebSearchHit>, answer: ?string}
     */
    private function searchViaChatSearch(WebSearchRequest $request, string $apiKey): array
    {
        $webSearch = [
            'enable' => true,
            'search_engine' => 'search-prime',
            'search_result' => true,
            'count' => max(1, min(10, $request->maxResults)),
            'content_size' => $request->searchDepth === 'advanced' ? 'high' : 'medium',
        ];

        if ($request->allowedDomains !== []) {
            $webSearch['search_domain_filter'] = implode(',', $request->allowedDomains);
        }

        $instruction = sprintf(
            'Search the web for: %s. Return only valid JSON with keys "answer" and "results". '.
            '"answer" should be a concise summary when requested, otherwise null. '.
            '"results" should be an array of up to %d objects with keys: title, url, source, published_at, snippet. '.
            'Do not wrap the JSON in markdown fences.',
            $request->query,
            max(1, min(10, $request->maxResults))
        );

        $payload = [
            'model' => 'glm-5.1',
            'messages' => [
                ['role' => 'user', 'content' => $instruction],
            ],
            'tools' => [[
                'type' => 'web_search',
                'web_search' => $webSearch,
            ]],
            'temperature' => 0,
        ];

        if (! $request->includeAnswer) {
            $payload['messages'][0]['content'] .= ' Set "answer" to null.';
        }

        $httpRequest = new Request(rtrim($this->chatBaseUrl, '/').'/chat/completions', 'POST');
        $httpRequest->setHeader('Authorization', 'Bearer '.$apiKey);
        $httpRequest->setHeader('Content-Type', 'application/json');
        $httpRequest->setBody(json_encode($payload, JSON_THROW_ON_ERROR));
        $httpRequest->setTransferTimeout(60);
        $httpRequest->setInactivityTimeout(60);

        $response = $this->httpClient->request($httpRequest);
        $status = $response->getStatus();
        $body = $response->getBody()->buffer();
        $data = json_decode($body, true);

        if ($status !== 200 || ! is_array($data)) {
            return ['results' => [], 'answer' => null];
        }

        $content = (string) ($data['choices'][0]['message']['content'] ?? '');
        $parsed = $this->parseChatSearchPayload($content);
        if ($parsed !== null) {
            return $parsed;
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
        $results = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, ' | ')) {
                continue;
            }

            $parts = array_map('trim', explode(' | ', $line));
            if (count($parts) < 4) {
                continue;
            }

            [$title, $url, $source, $publishedAt] = array_pad($parts, 4, '');
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            if ($request->blockedDomains !== [] && $this->isBlockedDomain($url, $request->blockedDomains)) {
                continue;
            }

            $results[] = new WebSearchHit(
                title: $title !== '' ? $title : $url,
                url: $url,
                snippet: '',
                score: null,
                publishedAt: $publishedAt !== '' ? $publishedAt : null,
                source: $source !== '' ? $source : 'zai',
            );
        }

        return ['results' => $results, 'answer' => null];
    }

    private function isRateLimitError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'code":"1302')
            || str_contains($normalized, 'code 1302')
            || str_contains($normalized, 'mcp error -429')
            || str_contains($normalized, '429');
    }

    private function shouldPreferChatSearch(WebSearchRequest $request): bool
    {
        $query = trim($request->query);
        if ($query === '') {
            return false;
        }

        $wordCount = count(array_filter(preg_split('/\s+/', $query) ?: [], static fn (string $part): bool => $part !== ''));

        return $request->includeAnswer || $wordCount <= 1 || mb_strlen($query) < 16;
    }

    /**
     * @return array{results: list<WebSearchHit>, answer: ?string}|null
     */
    private function parseChatSearchPayload(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $trimmed, $matches) === 1) {
            $trimmed = trim((string) $matches[1]);
        }

        $data = json_decode($trimmed, true);
        if (! is_array($data)) {
            return null;
        }

        $results = [];
        foreach (($data['results'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $results[] = new WebSearchHit(
                title: trim((string) ($item['title'] ?? $url)) ?: $url,
                url: $url,
                snippet: trim((string) ($item['snippet'] ?? '')),
                score: null,
                publishedAt: ($publishedAt = trim((string) ($item['published_at'] ?? ''))) !== '' ? $publishedAt : null,
                source: ($source = trim((string) ($item['source'] ?? ''))) !== '' ? $source : 'zai',
            );
        }

        $answer = $data['answer'] ?? null;
        if (! is_string($answer) || trim($answer) === '') {
            $answer = null;
        }

        return ['results' => $results, 'answer' => $answer];
    }
}

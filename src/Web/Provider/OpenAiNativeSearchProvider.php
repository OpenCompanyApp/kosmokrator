<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Provider;

use Kosmokrator\Web\WebCapability;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebSearchItem;
use Kosmokrator\Web\WebSearchRequest;
use Kosmokrator\Web\WebSearchResponse;

final class OpenAiNativeSearchProvider extends AbstractHttpWebProvider
{
    public function __construct(
        string $apiKey = '',
        ?string $baseUrl = null,
        private readonly string $model = 'gpt-5',
        private readonly string $mode = 'cached',
    ) {
        parent::__construct($apiKey, $baseUrl);
    }

    public function name(): string
    {
        return 'openai_native';
    }

    public function label(): string
    {
        return 'OpenAI native web search';
    }

    public function supports(WebCapability $capability): bool
    {
        return $capability === WebCapability::Search;
    }

    public function search(WebSearchRequest $request): WebSearchResponse
    {
        $tool = [
            'type' => 'web_search',
            'external_web_access' => ($request->mode ?? $this->mode) !== 'cached',
        ];
        if ($request->allowedDomains !== []) {
            $tool['filters'] = ['allowed_domains' => $request->allowedDomains];
        }

        $data = $this->postJson($this->url('https://api.openai.com/v1').'/responses', [
            'model' => $this->model,
            'tools' => [$tool],
            'tool_choice' => 'auto',
            'include' => ['web_search_call.action.sources'],
            'input' => 'Search the web for this query and return the most relevant cited findings: '.$request->query,
        ], ['Authorization' => 'Bearer '.$this->requireApiKey()], $request->timeoutSeconds);

        $answer = $this->extractOpenAiText($data);
        $results = $this->extractOpenAiCitations($data, $answer);
        if ($results === []) {
            $results[] = new WebSearchItem('OpenAI native web search response', '', WebFormatter::limit($answer, $request->outputLimitChars));
        }

        return new WebSearchResponse($this->name(), $request->query, $results, WebFormatter::limit($answer, $request->outputLimitChars), ['id' => $data['id'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractOpenAiText(array $data): string
    {
        if (is_string($data['output_text'] ?? null)) {
            return $data['output_text'];
        }

        $parts = [];
        foreach (is_array($data['output'] ?? null) ? $data['output'] : [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $content) {
                if (is_array($content) && is_string($content['text'] ?? null)) {
                    $parts[] = $content['text'];
                }
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<WebSearchItem>
     */
    private function extractOpenAiCitations(array $data, string $answer): array
    {
        $results = [];
        foreach (is_array($data['output'] ?? null) ? $data['output'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $content) {
                if (! is_array($content)) {
                    continue;
                }
                foreach (is_array($content['annotations'] ?? null) ? $content['annotations'] : [] as $annotation) {
                    if (! is_array($annotation) || ($annotation['type'] ?? null) !== 'url_citation') {
                        continue;
                    }
                    $url = $this->string($annotation, 'url');
                    if ($url === '') {
                        continue;
                    }
                    $results[$url] = new WebSearchItem($this->string($annotation, 'title', $url), $url, WebFormatter::limit($answer, 700));
                }
            }
        }

        return array_values($results);
    }
}

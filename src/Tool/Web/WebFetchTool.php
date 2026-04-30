<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Web;

use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Tool\AbstractTool;
use Kosmokrator\Tool\ToolResult;
use Kosmokrator\Web\Exception\WebFetchPermanentException;
use Kosmokrator\Web\Provider\WebFetchProviderManager;
use Kosmokrator\Web\Value\WebFetchRequest;
use Kosmokrator\Web\Value\WebFetchResponse;

final class WebFetchTool extends AbstractTool
{
    public function __construct(
        private readonly WebFetchProviderManager $providers,
        private readonly SettingsManager $settings,
    ) {}

    public function name(): string
    {
        return 'web_fetch';
    }

    public function description(): string
    {
        return <<<'DESC'
Fetch and extract a web page, with provider selection and context-aware modes.

Use metadata or outline mode first on large pages. Then fetch only the relevant section, matching blocks, or next chunk instead of repeatedly loading the full page. For multi-source web research, prefer subagents so each source can be inspected and summarized in parallel without bloating the parent context.
DESC;
    }

    public function parameters(): array
    {
        $availableProviders = $this->providers->availableProviderIds();

        return [
            'url' => ['type' => 'string', 'description' => 'The page URL to fetch.'],
            'provider' => ['type' => 'enum', 'description' => 'Optional fetch provider override.', 'options' => $availableProviders],
            'mode' => ['type' => 'enum', 'description' => 'Fetch mode. Defaults to main.', 'options' => ['metadata', 'outline', 'main', 'full', 'section', 'match', 'chunk']],
            'format' => ['type' => 'enum', 'description' => 'Preferred output format. Defaults to markdown.', 'options' => ['markdown', 'text', 'html']],
            'max_chars' => ['type' => 'integer', 'description' => 'Maximum content characters to return before chunking. Defaults to 12000.'],
            'summarize' => ['type' => 'boolean', 'description' => 'Reserved flag for future summarization. Currently ignored.'],
            'prompt' => ['type' => 'string', 'description' => 'Optional focus note for future summarization/extraction flows.'],
            'heading' => ['type' => 'string', 'description' => 'Section heading to fetch when mode=section.'],
            'section_id' => ['type' => 'string', 'description' => 'Section id to fetch when mode=section. Use ids returned by outline mode.'],
            'match' => ['type' => 'string', 'description' => 'Phrase to match when mode=match.'],
            'start_after' => ['type' => 'string', 'description' => 'Reserved for future boundary targeting.'],
            'end_before' => ['type' => 'string', 'description' => 'Reserved for future boundary targeting.'],
            'chunk_token' => ['type' => 'string', 'description' => 'Opaque token from a previous truncated response when mode=chunk.'],
            'timeout' => ['type' => 'integer', 'description' => 'Optional request timeout in seconds.'],
            'strategy' => ['type' => 'enum', 'description' => 'Provider selection strategy. Defaults to auto.', 'options' => ['auto', 'direct_only', 'provider_only']],
            'include_metadata' => ['type' => 'boolean', 'description' => 'Include page metadata in the response. Defaults to true.'],
            'include_outline' => ['type' => 'boolean', 'description' => 'Include outline information when available. Defaults to true.'],
        ];
    }

    public function requiredParameters(): array
    {
        return ['url'];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function handle(array $args): ToolResult
    {
        $request = new WebFetchRequest(
            url: trim((string) ($args['url'] ?? '')),
            provider: $this->nullableString($args['provider'] ?? null),
            mode: $this->enumValue((string) ($args['mode'] ?? 'main'), ['metadata', 'outline', 'main', 'full', 'section', 'match', 'chunk'], 'main'),
            format: $this->enumValue((string) ($args['format'] ?? 'markdown'), ['markdown', 'text', 'html'], 'markdown'),
            maxChars: max(50, min(50_000, (int) ($args['max_chars'] ?? ($this->settings->getRaw('kosmo.web.fetch.max_chars') ?? 12_000)))),
            summarize: filter_var($args['summarize'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            prompt: $this->nullableString($args['prompt'] ?? null),
            heading: $this->nullableString($args['heading'] ?? null),
            sectionId: $this->nullableString($args['section_id'] ?? null),
            match: $this->nullableString($args['match'] ?? null),
            startAfter: $this->nullableString($args['start_after'] ?? null),
            endBefore: $this->nullableString($args['end_before'] ?? null),
            chunkToken: $this->nullableString($args['chunk_token'] ?? null),
            timeout: isset($args['timeout']) ? max(5, min(60, (int) $args['timeout'])) : null,
            strategy: $this->enumValue((string) ($args['strategy'] ?? 'auto'), ['auto', 'direct_only', 'provider_only'], 'auto'),
            includeMetadata: filter_var($args['include_metadata'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            includeOutline: filter_var($args['include_outline'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        );

        if ($request->url === '') {
            return ToolResult::error('URL is required.');
        }

        $baseResponse = $this->providers->fetch($request);
        $selected = $this->selectContent($baseResponse, $request);
        $selectedContent = $selected['content'];

        if ($request->format === 'text' && $selected['source'] !== 'metadata' && $selected['source'] !== 'outline') {
            $selectedContent = $this->markdownToPlainText($selectedContent);
        }

        [$content, $truncated, $nextChunkToken] = $this->sliceContent(
            $selectedContent,
            $request->maxChars,
            $request,
            $selected['source'],
        );

        $response = new WebFetchResponse(
            provider: $baseResponse->provider,
            url: $baseResponse->url,
            finalUrl: $baseResponse->finalUrl,
            statusCode: $baseResponse->statusCode,
            contentType: $baseResponse->contentType,
            format: $baseResponse->format,
            title: $baseResponse->title,
            metadata: $baseResponse->metadata,
            outline: $baseResponse->outline,
            sections: $baseResponse->sections,
            content: $request->format === 'html' && $selected['source'] === 'content'
                ? ($baseResponse->rawHtml ?? $content)
                : $content,
            rawHtml: $baseResponse->rawHtml,
            truncated: $truncated,
            nextChunkToken: $nextChunkToken,
            extractionMethod: $baseResponse->extractionMethod,
            meta: array_merge($baseResponse->meta, ['selected_source' => $selected['source']]),
        );

        return ToolResult::successWithMetadata(
            $this->renderResponse($response, $request->mode, $request->includeMetadata, $request->includeOutline),
            [
                'provider' => $response->provider,
                'url' => $response->url,
                'final_url' => $response->finalUrl,
                'status_code' => $response->statusCode,
                'content_type' => $response->contentType,
                'title' => $response->title,
                'metadata' => $response->metadata,
                'outline' => $response->outline,
                'content' => $response->content,
                'truncated' => $response->truncated,
                'next_chunk_token' => $response->nextChunkToken,
            ],
        );
    }

    /**
     * @return array{content: string, source: string}
     */
    private function selectContent(WebFetchResponse $response, WebFetchRequest $request): array
    {
        return match ($request->mode) {
            'metadata' => ['content' => '', 'source' => 'metadata'],
            'outline' => ['content' => '', 'source' => 'outline'],
            'main', 'full' => ['content' => $this->baseContentForFormat($response, $request), 'source' => 'content'],
            'section' => $this->selectSection($response, $request),
            'match' => $this->selectMatches($response, $request),
            'chunk' => $this->selectChunkSource($response, $request),
            default => ['content' => $response->content, 'source' => 'content'],
        };
    }

    /**
     * @return array{content: string, source: string}
     */
    private function selectSection(WebFetchResponse $response, WebFetchRequest $request): array
    {
        if ($request->sectionId !== null && isset($response->sections[$request->sectionId])) {
            return ['content' => $response->sections[$request->sectionId], 'source' => 'section:'.$request->sectionId];
        }

        if ($request->sectionId !== null) {
            $normalizedRequestedId = $this->slugify($request->sectionId);

            foreach (array_keys($response->sections) as $sectionId) {
                if ($this->slugify($sectionId) === $normalizedRequestedId) {
                    return ['content' => $response->sections[$sectionId], 'source' => 'section:'.$sectionId];
                }
            }
        }

        if ($request->heading !== null) {
            foreach ($response->outline as $entry) {
                if (strcasecmp($entry['title'], $request->heading) === 0 && isset($response->sections[$entry['id']])) {
                    return ['content' => $response->sections[$entry['id']], 'source' => 'section:'.$entry['id']];
                }
            }
        }

        $availableIds = array_keys($response->sections);
        $suffix = $availableIds === [] ? '' : ' Available section ids: '.implode(', ', array_slice($availableIds, 0, 12));

        throw new WebFetchPermanentException('Requested section was not found. Use outline mode first to inspect available section ids and headings.'.$suffix);
    }

    /**
     * @return array{content: string, source: string}
     */
    private function selectMatches(WebFetchResponse $response, WebFetchRequest $request): array
    {
        if ($request->match === null || trim($request->match) === '') {
            throw new WebFetchPermanentException('match is required when mode=match.');
        }

        $blocks = [];
        foreach ($response->sections as $sectionId => $content) {
            if (stripos($content, $request->match) !== false) {
                $heading = $this->sectionHeading($response, $sectionId) ?? $sectionId;
                $blocks[] = "## {$heading}\n\n".$content;
            }
        }

        if ($blocks === []) {
            throw new WebFetchPermanentException("No matching content found for '{$request->match}'.");
        }

        return ['content' => implode("\n\n", $blocks), 'source' => 'match:'.$request->match];
    }

    /**
     * @return array{content: string, source: string}
     */
    private function selectChunkSource(WebFetchResponse $response, WebFetchRequest $request): array
    {
        if ($request->chunkToken === null) {
            throw new WebFetchPermanentException('chunk_token is required when mode=chunk.');
        }

        $token = $this->decodeChunkToken($request->chunkToken);
        $source = (string) ($token['source'] ?? 'content');

        if ($source === 'content') {
            return ['content' => $this->baseContentForFormat($response, $request), 'source' => 'content'];
        }

        if (str_starts_with($source, 'section:')) {
            $sectionId = substr($source, strlen('section:'));
            if (isset($response->sections[$sectionId])) {
                return ['content' => $response->sections[$sectionId], 'source' => $source];
            }
        }

        if (str_starts_with($source, 'match:')) {
            return $this->selectMatches($response, new WebFetchRequest(
                url: $request->url,
                provider: $request->provider,
                mode: 'match',
                format: $request->format,
                maxChars: $request->maxChars,
                summarize: $request->summarize,
                prompt: $request->prompt,
                match: substr($source, strlen('match:')),
                timeout: $request->timeout,
                strategy: $request->strategy,
                includeMetadata: $request->includeMetadata,
                includeOutline: $request->includeOutline,
            ));
        }

        throw new WebFetchPermanentException('Chunk token could not be resolved against the current page content.');
    }

    /**
     * @return array{0: string, 1: bool, 2: ?string}
     */
    private function sliceContent(string $content, int $maxChars, WebFetchRequest $request, string $source): array
    {
        if ($request->mode === 'metadata' || $request->mode === 'outline') {
            return ['', false, null];
        }

        $offset = 0;
        if ($request->mode === 'chunk' && $request->chunkToken !== null) {
            $decoded = $this->decodeChunkToken($request->chunkToken);
            $offset = max(0, (int) ($decoded['offset'] ?? 0));
        }

        if (mb_strlen($content) <= $offset + $maxChars) {
            return [mb_substr($content, $offset), false, null];
        }

        $slice = mb_substr($content, $offset, $maxChars);
        $nextToken = $this->encodeChunkToken([
            'source' => $source,
            'offset' => $offset + $maxChars,
        ]);

        return [$slice, true, $nextToken];
    }

    private function renderResponse(WebFetchResponse $response, string $mode, bool $includeMetadata, bool $includeOutline): string
    {
        $lines = [
            "Provider: {$response->provider}",
            "Mode: {$mode}",
            "URL: {$response->url}",
        ];

        if ($response->finalUrl !== null && $response->finalUrl !== $response->url) {
            $lines[] = "Final URL: {$response->finalUrl}";
        }

        if ($response->title !== null && $response->title !== '') {
            $lines[] = "Title: {$response->title}";
        }

        if ($includeMetadata && $response->metadata !== []) {
            $lines[] = '';
            $lines[] = 'Metadata:';
            foreach ($response->metadata as $key => $value) {
                if (is_scalar($value)) {
                    $lines[] = "- {$key}: {$value}";
                }
            }
        }

        if ($includeOutline && $response->outline !== []) {
            $lines[] = '';
            $lines[] = 'Outline:';
            foreach ($response->outline as $entry) {
                $indent = str_repeat('  ', max(0, $entry['level'] - 1));
                $lines[] = sprintf('%s- %s [id: %s]', $indent, $entry['title'], $entry['id']);
            }
        }

        if ($mode !== 'metadata' && $mode !== 'outline') {
            $lines[] = '';
            $lines[] = 'Content:';
            $lines[] = $response->content !== '' ? $response->content : '[No content extracted]';
        }

        if ($response->truncated && $response->nextChunkToken !== null) {
            $lines[] = '';
            $lines[] = 'More content is available.';
            $lines[] = 'Next chunk token: '.$response->nextChunkToken;
            $lines[] = 'Use web_fetch with mode="chunk" and this chunk_token to continue from the current position.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeChunkToken(string $token): array
    {
        $normalized = strtr($token, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding !== 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if (! is_string($decoded)) {
            throw new \RuntimeException('Invalid chunk token.');
        }

        $data = json_decode($decoded, true);
        if (! is_array($data)) {
            throw new \RuntimeException('Invalid chunk token.');
        }

        return $data;
    }

    private function baseContentForFormat(WebFetchResponse $response, WebFetchRequest $request): string
    {
        if ($request->format === 'html' && is_string($response->rawHtml) && $response->rawHtml !== '') {
            return $response->rawHtml;
        }

        if ($request->format === 'text') {
            return $this->markdownToPlainText($response->content);
        }

        return $response->content;
    }

    private function markdownToPlainText(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/```[^\n]*\n(.*?)```/s', "$1\n", $content) ?? $content;
        $content = preg_replace('/`([^`]+)`/', '$1', $content) ?? $content;
        $content = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '$1', $content) ?? $content;
        $content = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1', $content) ?? $content;
        $content = preg_replace('/^(#{1,6})\s*/m', '', $content) ?? $content;
        $content = preg_replace('/^\s*>\s?/m', '', $content) ?? $content;
        $content = preg_replace('/^\s*[-*+]\s+/m', '- ', $content) ?? $content;
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return trim($content);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeChunkToken(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function enumValue(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function sectionHeading(WebFetchResponse $response, string $sectionId): ?string
    {
        foreach ($response->outline as $entry) {
            if ($entry['id'] === $sectionId) {
                return $entry['title'];
            }
        }

        return null;
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? $value;

        return trim($value, '-');
    }
}

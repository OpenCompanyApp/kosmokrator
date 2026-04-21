<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Extract;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Kosmokrator\Web\Value\ExtractedPage;
use League\HTMLToMarkdown\HtmlConverter;

final class HtmlPageExtractor
{
    private const HEADING_PATTERN = '/^(?:\s*<[^>\n]+>\s*)*(#{1,6})\s+(.+)$/m';

    private readonly HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'header_style' => 'atx',
            'remove_nodes' => 'script style noscript template iframe svg canvas nav footer aside form button',
            'strip_tags' => false,
            'hard_break' => false,
            'list_item_style' => '-',
        ]);
    }

    public function extract(string $html, string $url): ExtractedPage
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        @$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $this->stripNoise($xpath);

        $title = $this->readTitle($xpath, $dom);
        $metadata = $this->readMetadata($xpath, $url, $title);
        $root = $this->pickContentRoot($xpath, $dom);
        $outline = $this->extractOutline($xpath, $root);
        $content = $this->normalizeMarkdown($this->converter->convert($this->innerHtml($root)));
        $sections = $this->splitSections($content, $outline);

        if ($sections === [] && $content !== '') {
            $sections = ['content' => $content];
        }

        return new ExtractedPage(
            title: $title,
            metadata: $metadata,
            outline: $outline,
            fullContent: trim($content),
            sections: $sections,
        );
    }

    private function stripNoise(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//script|//style|//noscript|//template|//iframe|//svg|//canvas|//nav|//footer|//aside|//form|//button');
        if ($nodes === false) {
            return;
        }

        /** @var DOMNode $node */
        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function readTitle(DOMXPath $xpath, DOMDocument $dom): ?string
    {
        $title = trim($dom->getElementsByTagName('title')->item(0)?->textContent ?? '');
        if ($title !== '') {
            return $title;
        }

        foreach ([
            "//meta[@property='og:title']/@content",
            "//meta[@name='twitter:title']/@content",
        ] as $query) {
            $value = trim((string) $xpath->evaluate("string({$query})"));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMetadata(DOMXPath $xpath, string $url, ?string $title): array
    {
        $description = $this->firstMetaValue($xpath, [
            "//meta[@name='description']/@content",
            "//meta[@property='og:description']/@content",
            "//meta[@name='twitter:description']/@content",
        ]);

        $canonical = trim((string) $xpath->evaluate("string(//link[@rel='canonical']/@href)"));
        $author = $this->firstMetaValue($xpath, [
            "//meta[@name='author']/@content",
            "//meta[@property='article:author']/@content",
        ]);
        $publishedAt = $this->firstMetaValue($xpath, [
            "//meta[@property='article:published_time']/@content",
            "//meta[@name='pubdate']/@content",
            '//time/@datetime',
        ]);

        return array_filter([
            'title' => $title,
            'description' => $description,
            'canonical_url' => $canonical !== '' ? $canonical : $url,
            'author' => $author,
            'published_at' => $publishedAt,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstMetaValue(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $value = trim((string) $xpath->evaluate("string({$query})"));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function pickContentRoot(DOMXPath $xpath, DOMDocument $dom): DOMNode
    {
        $queries = [
            '//main',
            '//*[@role="main"]',
            '//article',
            '//*[contains(@class, "content")]',
            '//*[contains(@class, "article")]',
            '//*[contains(@class, "markdown")]',
        ];

        foreach ($queries as $query) {
            $queryResult = $xpath->query($query);
            $node = $queryResult !== false ? $queryResult->item(0) : null;
            if ($node instanceof DOMNode) {
                return $node;
            }
        }

        return $dom->getElementsByTagName('body')->item(0) ?? $dom;
    }

    /**
     * @return list<array{id: string, title: string, level: int}>
     */
    private function extractOutline(DOMXPath $xpath, DOMNode $root): array
    {
        $outline = [];
        $seenIds = [];

        $headings = $xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $root);
        if ($headings === false) {
            return [];
        }

        foreach ($headings as $headingNode) {
            if (! $headingNode instanceof DOMElement) {
                continue;
            }

            $heading = $this->normalizeWhitespace($headingNode->textContent ?? '');
            if ($heading === '') {
                continue;
            }

            $level = (int) substr(strtolower($headingNode->tagName), 1);
            $outline[] = [
                'id' => $this->makeUniqueId($this->slugify($heading), $seenIds),
                'title' => $heading,
                'level' => $level,
            ];
        }

        return $outline;
    }

    /**
     * @param  list<array{id: string, title: string, level: int}>  $outline
     * @return array<string, string>
     */
    private function splitSections(string $markdown, array $outline): array
    {
        if ($markdown === '' || $outline === []) {
            return [];
        }

        preg_match_all(self::HEADING_PATTERN, $markdown, $matches, PREG_OFFSET_CAPTURE);

        if (($matches[0] ?? []) === []) {
            return [];
        }

        $sections = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $offset = $matches[1][$i][1];
            $nextOffset = $i + 1 < $count ? $matches[1][$i + 1][1] : mb_strlen($markdown);
            $chunk = trim(mb_substr($markdown, $offset, $nextOffset - $offset));

            $entry = $outline[$i] ?? null;
            if ($entry !== null) {
                $sections[$entry['id']] = $chunk;
            }
        }

        return $sections;
    }

    private function normalizeMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<string, true>  $seenIds
     */
    private function makeUniqueId(string $id, array &$seenIds): string
    {
        $candidate = $id !== '' ? $id : 'section';
        $suffix = 2;

        while (isset($seenIds[$candidate])) {
            $candidate = $id.'-'.$suffix;
            $suffix++;
        }

        $seenIds[$candidate] = true;

        return $candidate;
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? $text;

        return trim($text, '-');
    }
}

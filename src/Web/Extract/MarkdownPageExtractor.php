<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Extract;

use Kosmokrator\Web\Value\ExtractedPage;

final class MarkdownPageExtractor
{
    private const HEADING_PATTERN = '/^(?:\s*<[^>\n]+>\s*)*(#{1,6})\s+(.+)$/m';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function extract(string $markdown, ?string $title = null, array $metadata = []): ExtractedPage
    {
        $content = $this->normalizeMarkdown($markdown);
        $outline = $this->extractOutline($content);
        $sections = $this->splitSections($content, $outline);

        if ($sections === [] && $content !== '') {
            $sections = ['content' => $content];
        }

        return new ExtractedPage(
            title: $title,
            metadata: array_filter(
                array_merge(['title' => $title], $metadata),
                static fn (mixed $value): bool => $value !== null && $value !== ''
            ),
            outline: $outline,
            fullContent: $content,
            sections: $sections,
        );
    }

    /**
     * @return list<array{id: string, title: string, level: int}>
     */
    private function extractOutline(string $markdown): array
    {
        if ($markdown === '') {
            return [];
        }

        preg_match_all(self::HEADING_PATTERN, $markdown, $matches);

        if (($matches[0] ?? []) === []) {
            return [];
        }

        $outline = [];
        $seenIds = [];

        foreach ($matches[2] as $index => $heading) {
            $heading = $this->cleanHeading((string) $heading);
            if ($heading === '') {
                continue;
            }

            $outline[] = [
                'id' => $this->makeUniqueId($this->slugify($heading), $seenIds),
                'title' => $heading,
                'level' => strlen((string) ($matches[1][$index] ?? '#')),
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

    private function cleanHeading(string $heading): string
    {
        $heading = strip_tags($heading);
        $heading = html_entity_decode($heading, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($heading);
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

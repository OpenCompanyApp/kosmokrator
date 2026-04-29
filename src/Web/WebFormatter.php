<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

final class WebFormatter
{
    public static function search(WebSearchResponse $response): string
    {
        $lines = ["Provider: {$response->provider}", "Query: {$response->query}"];
        if ($response->answer !== null && trim($response->answer) !== '') {
            $lines[] = '';
            $lines[] = trim($response->answer);
        }

        foreach ($response->results as $index => $result) {
            $n = $index + 1;
            $lines[] = '';
            $lines[] = "{$n}. {$result->title}";
            if ($result->url !== '') {
                $lines[] = "   {$result->url}";
            }
            if ($result->snippet !== '') {
                $lines[] = '   '.self::oneLine($result->snippet);
            }
            if ($result->content !== null && trim($result->content) !== '') {
                $lines[] = '   '.self::oneLine($result->content);
            }
        }

        return trim(implode("\n", $lines));
    }

    public static function fetch(WebFetchResponse $response): string
    {
        $title = $response->title !== null && $response->title !== '' ? "Title: {$response->title}\n" : '';

        return trim("Provider: {$response->provider}\nURL: {$response->url}\nFormat: {$response->format}\n".$title."\n{$response->content}");
    }

    public static function crawl(WebCrawlResponse $response): string
    {
        $lines = ["Provider: {$response->provider}", "URL: {$response->url}", 'Pages: '.count($response->pages)];
        foreach ($response->pages as $index => $page) {
            $n = $index + 1;
            $lines[] = '';
            $lines[] = "{$n}. ".($page->title ?: $page->url);
            $lines[] = "   {$page->url}";
            $lines[] = '   '.self::oneLine($page->content);
        }

        return trim(implode("\n", $lines));
    }

    public static function limit(string $value, int $limit): string
    {
        if ($limit <= 0 || strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(substr($value, 0, $limit))."\n\n[truncated to {$limit} characters]";
    }

    private static function oneLine(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);

        return strlen($value) > 500 ? substr($value, 0, 497).'...' : $value;
    }
}

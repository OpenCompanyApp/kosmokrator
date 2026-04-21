<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Web;

use Kosmokrator\Web\Extract\MarkdownPageExtractor;
use PHPUnit\Framework\TestCase;

final class MarkdownPageExtractorTest extends TestCase
{
    public function test_extracts_outline_sections_and_metadata_from_markdown(): void
    {
        $extractor = new MarkdownPageExtractor;

        $page = $extractor->extract(
            "# Intro\n\nHello world.\n\n## Auth\n\nUse a token.\n",
            'Example Docs',
            ['description' => 'Reference'],
        );

        self::assertSame('Example Docs', $page->title);
        self::assertSame('Reference', $page->metadata['description']);
        self::assertCount(2, $page->outline);
        self::assertArrayHasKey('intro', $page->sections);
        self::assertArrayHasKey('auth', $page->sections);
    }

    public function test_outline_ids_round_trip_to_section_keys(): void
    {
        $extractor = new MarkdownPageExtractor;

        $page = $extractor->extract(
            "# PHP 8.4: Here’s what’s new and improved\n\nIntro.\n\n## New features and improvements in PHP 8.4\n\nBody.\n\n<div></div><kinsta-auto-toc data-test=\"x\"></kinsta-auto-toc>### Property hooks\n\nHook body.\n"
        );

        self::assertSame([
            'php-8-4-here-s-what-s-new-and-improved',
            'new-features-and-improvements-in-php-8-4',
            'property-hooks',
        ], array_column($page->outline, 'id'));
        self::assertArrayHasKey('property-hooks', $page->sections);
        self::assertStringContainsString('Property hooks', $page->sections['property-hooks']);
        self::assertStringContainsString('Hook body.', $page->sections['property-hooks']);
    }
}

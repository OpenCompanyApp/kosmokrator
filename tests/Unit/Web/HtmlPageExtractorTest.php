<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Web;

use Kosmokrator\Web\Extract\HtmlPageExtractor;
use PHPUnit\Framework\TestCase;

final class HtmlPageExtractorTest extends TestCase
{
    public function test_extracts_title_metadata_and_outline(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <title>Example Docs</title>
    <meta name="description" content="Reference docs">
    <link rel="canonical" href="https://example.com/docs">
  </head>
  <body>
    <main>
      <h1>Authentication</h1>
      <p>Use bearer tokens.</p>
      <h2>Errors</h2>
      <p>Errors are returned as JSON.</p>
    </main>
  </body>
</html>
HTML;

        $result = (new HtmlPageExtractor)->extract($html, 'https://example.com/docs');

        $this->assertSame('Example Docs', $result->title);
        $this->assertSame('Reference docs', $result->metadata['description']);
        $this->assertCount(2, $result->outline);
        $this->assertSame('Authentication', $result->outline[0]['title']);
        $this->assertArrayHasKey('authentication', $result->sections);
        $this->assertStringContainsString('Use bearer tokens.', $result->sections['authentication']);
    }

    public function test_outline_ids_round_trip_to_section_keys(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html>
  <head><title>Docs</title></head>
  <body>
    <main>
      <h1>PHP 8.4: Here’s what’s new and improved</h1>
      <p>Intro.</p>
      <h2>New features and improvements in PHP 8.4</h2>
      <p>Body.</p>
      <h3>Property hooks</h3>
      <p>Hook body.</p>
    </main>
  </body>
</html>
HTML;

        $result = (new HtmlPageExtractor)->extract($html, 'https://example.com/docs');

        $this->assertSame([
            'php-8-4-here-s-what-s-new-and-improved',
            'new-features-and-improvements-in-php-8-4',
            'property-hooks',
        ], array_column($result->outline, 'id'));
        $this->assertArrayHasKey('property-hooks', $result->sections);
        $this->assertStringContainsString('Property hooks', $result->sections['property-hooks']);
        $this->assertStringContainsString('Hook body.', $result->sections['property-hooks']);
    }
}

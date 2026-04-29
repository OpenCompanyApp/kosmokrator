<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Web;

use Illuminate\Config\Repository;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use Kosmokrator\Tool\Web\WebFetchTool;
use Kosmokrator\Web\Cache\WebTransientCache;
use Kosmokrator\Web\Contracts\WebFetchProvider;
use Kosmokrator\Web\Provider\WebFetchProviderManager;
use Kosmokrator\Web\Value\WebFetchRequest;
use Kosmokrator\Web\Value\WebFetchResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebFetchToolTest extends TestCase
{
    public function test_metadata_mode_returns_page_metadata(): void
    {
        $tool = $this->makeTool($this->provider());

        $result = $tool->execute([
            'url' => 'https://example.com/docs',
            'mode' => 'metadata',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Metadata:', $result->output);
        $this->assertStringContainsString('title: Example Docs', $result->output);
        $this->assertStringNotContainsString('Token auth details', $result->output);
    }

    public function test_section_mode_returns_selected_section(): void
    {
        $tool = $this->makeTool($this->provider());

        $result = $tool->execute([
            'url' => 'https://example.com/docs',
            'mode' => 'section',
            'section_id' => 'authentication',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Token auth details', $result->output);
        $this->assertStringNotContainsString('Error model details', $result->output);
    }

    public function test_chunk_mode_continues_from_next_chunk_token(): void
    {
        $tool = $this->makeTool($this->provider(str_repeat('A', 8000)));

        $first = $tool->execute([
            'url' => 'https://example.com/docs',
            'mode' => 'main',
            'max_chars' => 3000,
        ]);

        $this->assertTrue($first->success);
        preg_match('/Next chunk token: ([A-Za-z0-9\-_]+)/', $first->output, $matches);
        $this->assertArrayHasKey(1, $matches);

        $second = $tool->execute([
            'url' => 'https://example.com/docs',
            'mode' => 'chunk',
            'chunk_token' => $matches[1],
            'max_chars' => 3000,
        ]);

        $this->assertTrue($second->success);
        $this->assertStringContainsString('Content:', $second->output);
    }

    public function test_provider_parameter_only_lists_available_fetch_providers(): void
    {
        $available = $this->provider();
        $unavailable = new class implements WebFetchProvider
        {
            public function id(): string
            {
                return 'firecrawl';
            }

            public function isAvailable(): bool
            {
                return false;
            }

            public function fetch(WebFetchRequest $request): WebFetchResponse
            {
                throw new \RuntimeException('not used');
            }
        };

        $tool = $this->makeToolSet([$available, $unavailable]);

        $providerParameter = $tool->parameters()['provider'];

        $this->assertArrayHasKey('options', $providerParameter);
        /** @var array{type: string, description: string, options: list<string>} $providerParameter */
        $this->assertSame(['direct'], $providerParameter['options']);
    }

    public function test_text_format_returns_plain_text_not_markdown(): void
    {
        $tool = $this->makeTool($this->provider("## Authentication\n\nUse [docs](https://example.com/docs) and `TOKEN` auth."));

        $result = $tool->execute([
            'url' => 'https://example.com/docs',
            'mode' => 'main',
            'format' => 'text',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Use docs and TOKEN auth.', $result->output);
        $this->assertStringNotContainsString('[docs](https://example.com/docs)', $result->output);
        $this->assertStringNotContainsString('## Authentication', $result->output);
    }

    public function test_max_chars_respects_small_values(): void
    {
        $tool = $this->makeTool($this->provider(str_repeat('A', 500)));

        $result = $tool->execute([
            'url' => 'https://example.com/docs',
            'mode' => 'main',
            'max_chars' => 50,
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Next chunk token:', $result->output);
        $this->assertSame(50, strlen((string) ($result->metadata['content'] ?? '')));
    }

    private function makeTool(WebFetchProvider $provider): WebFetchTool
    {
        return $this->makeToolSet([$provider]);
    }

    /**
     * @param  list<WebFetchProvider>  $providers
     */
    private function makeToolSet(array $providers): WebFetchTool
    {
        $settings = $this->makeSettingsManager();

        return new WebFetchTool(
            new WebFetchProviderManager($providers, $settings, new WebTransientCache),
            $settings,
        );
    }

    private function provider(string $content = "## Authentication\n\nToken auth details\n\n## Errors\n\nError model details"): WebFetchProvider
    {
        return new class($content) implements WebFetchProvider
        {
            public function __construct(private readonly string $content) {}

            public function id(): string
            {
                return 'direct';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function fetch(WebFetchRequest $request): WebFetchResponse
            {
                return new WebFetchResponse(
                    provider: 'direct',
                    url: $request->url,
                    finalUrl: $request->url,
                    statusCode: 200,
                    contentType: 'text/html',
                    format: 'markdown',
                    title: 'Example Docs',
                    metadata: ['title' => 'Example Docs', 'description' => 'Reference'],
                    outline: [
                        ['id' => 'authentication', 'title' => 'Authentication', 'level' => 1],
                        ['id' => 'errors', 'title' => 'Errors', 'level' => 1],
                    ],
                    sections: [
                        'authentication' => "## Authentication\n\nToken auth details",
                        'errors' => "## Errors\n\nError model details",
                    ],
                    content: $this->content,
                );
            }
        };
    }

    private function makeSettingsManager(): SettingsManager
    {
        $dir = sys_get_temp_dir().'/kosmo-web-fetch-tool-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);

        return new SettingsManager(
            new Repository([
                'kosmokrator' => [
                    'web' => [
                        'search' => ['default_provider' => 'tavily', 'fallback_providers' => [], 'max_results' => 5],
                        'fetch' => ['default_provider' => 'direct', 'fallback_providers' => [], 'max_chars' => 12000],
                    ],
                ],
            ]),
            new SettingsSchema,
            new YamlConfigStore(new NullLogger),
            $dir,
        );
    }
}

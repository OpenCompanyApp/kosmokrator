<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Illuminate\Config\Repository;
use Kosmokrator\LLM\RelayProviderRegistry;
use PHPUnit\Framework\TestCase;

final class RelayProviderRegistryTest extends TestCase
{
    public function test_builds_providers_from_repo_config(): void
    {
        $registry = new RelayProviderRegistry(new Repository([
            'prism' => [
                'providers' => [
                    'openai' => ['url' => 'https://api.openai.com/v1'],
                    'codex' => ['url' => 'https://chatgpt.com/backend-api/codex'],
                ],
            ],
            'models' => [
                'models' => [
                    'openai/gpt-test' => [
                        'id' => 'gpt-test',
                        'provider' => 'openai',
                        'context' => 128000,
                        'max_output' => 4096,
                    ],
                ],
            ],
        ]));

        $this->assertTrue($registry->hasProvider('openai'));
        $this->assertTrue($registry->hasProvider('codex'));
        $this->assertSame('openai', $registry->driver('openai'));
        $this->assertSame('oauth', $registry->authMode('codex'));
        $this->assertSame('https://api.openai.com/v1', $registry->url('openai'));
        $this->assertArrayHasKey('gpt-test', $registry->provider('openai')['models']);
    }

    public function test_accepts_plain_custom_provider_map_for_tests_and_overrides(): void
    {
        $registry = new RelayProviderRegistry([
            'custom-provider' => [
                'driver' => 'openai-compatible',
                'auth' => 'api_key',
                'url' => 'https://example.test/v1',
                'models' => [
                    'custom-model' => ['context' => 1000],
                ],
            ],
        ]);

        $this->assertTrue($registry->hasProvider('custom-provider'));
        $this->assertSame('custom', $registry->source('custom-provider'));
        $this->assertSame('openai-compatible', $registry->driver('custom-provider'));
        $this->assertTrue($registry->supportsAsync('custom-provider'));
    }

    public function test_canonicalizes_known_aliases(): void
    {
        $registry = new RelayProviderRegistry(new Repository([
            'prism' => [
                'providers' => [
                    'z-api' => ['url' => 'https://open.bigmodel.cn/api/paas/v4'],
                    'kimi-coding' => ['url' => 'https://api.moonshot.ai/v1'],
                ],
            ],
        ]));

        $this->assertSame('z-api', $registry->canonicalProvider('glm'));
        $this->assertSame('kimi-coding', $registry->canonicalProvider('kimi-for-coding'));
    }
}

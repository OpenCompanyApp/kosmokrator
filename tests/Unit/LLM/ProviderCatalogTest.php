<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Illuminate\Config\Repository;
use Kosmokrator\LLM\Codex\SettingsCodexTokenStore;
use Kosmokrator\LLM\ModelDiscovery\DiscoveredModel;
use Kosmokrator\LLM\ModelDiscovery\ModelDiscoveryCacheRepository;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SettingsRepository;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderCatalogTest extends TestCase
{
    public function test_provider_catalog_uses_shared_models_and_status_sources(): void
    {
        $meta = new ProviderMeta([
            'codex' => [
                'default_model' => 'gpt-5.3-codex',
                'url' => 'https://chatgpt.com/backend-api/codex',
                'models' => [
                    'gpt-5.3-codex' => ['display_name' => 'GPT-5.3 Codex', 'context' => 128000, 'max_output' => 16384],
                    'gpt-5-codex-mini' => ['display_name' => 'GPT-5 Codex Mini', 'context' => 128000, 'max_output' => 16384],
                ],
            ],
            'z' => [
                'default_model' => 'glm-5.1',
                'url' => 'https://api.z.ai/api/coding/paas/v4',
                'models' => [
                    'glm-5.1' => ['display_name' => 'GLM 5.1', 'context' => 204800, 'max_output' => 131072, 'thinking' => true],
                    'glm-5-turbo' => ['display_name' => 'GLM 5 Turbo', 'context' => 204800, 'max_output' => 16384],
                ],
            ],
            'ollama' => [
                'default_model' => 'llama3.2',
                'url' => 'http://localhost:11434/v1',
                'models' => [
                    'llama3.2' => ['display_name' => 'Llama 3.2', 'context' => 128000, 'max_output' => 4096],
                ],
            ],
        ]);

        $config = new Repository([
            'prism' => [
                'providers' => [
                    'codex' => ['url' => 'https://chatgpt.com/backend-api/codex'],
                    'z' => ['api_key' => 'zai-secret-1234', 'url' => 'https://api.z.ai/api/coding/paas/v4'],
                    'ollama' => ['url' => 'http://localhost:11434/v1'],
                ],
            ],
        ]);

        $settings = new SettingsRepository(new Database(':memory:'));
        $tokens = new SettingsCodexTokenStore($settings);
        $tokens->save(new CodexToken(
            accessToken: 'access',
            refreshToken: 'refresh',
            expiresAt: new \DateTimeImmutable('+1 hour'),
            email: 'dev@example.com',
        ));

        $catalog = new ProviderCatalog($meta, new RelayRegistry([
            'codex' => ['url' => 'https://chatgpt.com/backend-api/codex', 'auth' => 'oauth'],
            'z' => ['url' => 'https://api.z.ai/api/coding/paas/v4', 'auth' => 'api_key', 'driver' => 'glm-coding'],
            'ollama' => ['url' => 'http://localhost:11434/v1', 'auth' => 'none', 'driver' => 'ollama'],
        ]), $config, $settings, $tokens);

        $this->assertSame(['gpt-5.3-codex', 'gpt-5-codex-mini'], $catalog->modelIds('codex'));
        $this->assertSame(['glm-5.1', 'glm-5-turbo'], $catalog->modelIds('z'));
        $this->assertSame('Authenticated · dev@example.com', $catalog->authStatus('codex'));
        $this->assertSame('Configured · zai-secr...1234', $catalog->authStatus('z'));
        $this->assertSame('No authentication required', $catalog->authStatus('ollama'));
    }

    public function test_provider_catalog_surfaces_custom_provider_source_and_modalities(): void
    {
        $meta = new ProviderMeta([
            'mimo' => [
                'default_model' => 'mimo-v2-pro',
                'url' => 'https://token-plan-sgp.xiaomimimo.com/v1',
                'models' => [
                    'mimo-v2-pro' => ['display_name' => 'MiMo V2 Pro', 'context' => 1048576, 'max_output' => 131072],
                ],
            ],
        ]);

        $config = new Repository([
            'relay' => [
                'providers' => [
                    'mimo' => [
                        'label' => 'Xiaomi MiMo',
                        'driver' => 'openai-compatible',
                        'auth' => 'api_key',
                        'modalities' => [
                            'input' => ['text', 'image'],
                            'output' => ['text'],
                        ],
                        'models' => [
                            'mimo-v2-pro' => [
                                'modalities' => [
                                    'input' => ['text', 'image'],
                                    'output' => ['text'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'prism' => [
                'providers' => [
                    'mimo' => ['url' => 'https://token-plan-sgp.xiaomimimo.com/v1'],
                ],
            ],
        ]);

        $settings = new SettingsRepository(new Database(':memory:'));
        $catalog = new ProviderCatalog($meta, new RelayRegistry([
            'mimo' => [
                'source' => 'custom',
                'label' => 'Xiaomi MiMo',
                'driver' => 'openai-compatible',
                'auth' => 'api_key',
                'url' => 'https://token-plan-sgp.xiaomimimo.com/v1',
                'modalities' => [
                    'input' => ['text', 'image'],
                    'output' => ['text'],
                ],
                'models' => [
                    'mimo-v2-pro' => [
                        'modalities' => [
                            'input' => ['text', 'image'],
                            'output' => ['text'],
                        ],
                    ],
                ],
            ],
        ]), $config, $settings, new SettingsCodexTokenStore($settings));

        $provider = $catalog->provider('mimo');

        $this->assertNotNull($provider);
        $this->assertSame('custom', $provider->source);
        $this->assertSame('openai-compatible', $provider->driver);
        $this->assertSame(['text', 'image'], $provider->inputModalities);
        $this->assertSame(['text', 'image'], $provider->models[0]->inputModalities);
    }

    public function test_provider_catalog_formats_free_and_coding_plan_models(): void
    {
        $meta = new ProviderMeta([
            'z' => [
                'default_model' => 'glm-5',
                'url' => 'https://api.z.ai/api/coding/paas/v4',
                'models' => [
                    'glm-5' => [
                        'display_name' => 'GLM-5',
                        'context' => 204800,
                        'max_output' => 131072,
                        'input' => 0.0,
                        'output' => 0.0,
                        'pricing_kind' => 'coding_plan',
                        'reference_input' => 1.0,
                        'reference_output' => 3.2,
                    ],
                    'glm-5-free' => [
                        'display_name' => 'GLM-5 Free',
                        'context' => 204800,
                        'max_output' => 131072,
                        'input' => 0.0,
                        'output' => 0.0,
                        'pricing_kind' => 'public_free',
                    ],
                ],
            ],
            'mimo' => [
                'default_model' => 'mimo-v2-pro',
                'url' => 'https://token-plan-sgp.xiaomimimo.com/v1',
                'models' => [
                    'mimo-v2-pro' => [
                        'display_name' => 'MiMo V2 Pro',
                        'context' => 1048576,
                        'max_output' => 131072,
                        'input' => 0.0,
                        'output' => 0.0,
                        'pricing_kind' => 'token_plan',
                    ],
                ],
            ],
        ]);

        $config = new Repository([
            'prism' => [
                'providers' => [
                    'z' => ['url' => 'https://api.z.ai/api/coding/paas/v4'],
                    'mimo' => ['url' => 'https://token-plan-sgp.xiaomimimo.com/v1'],
                ],
            ],
        ]);

        $settings = new SettingsRepository(new Database(':memory:'));
        $catalog = new ProviderCatalog($meta, new RelayRegistry([
            'z' => ['url' => 'https://api.z.ai/api/coding/paas/v4', 'auth' => 'api_key', 'driver' => 'glm-coding'],
            'mimo' => ['url' => 'https://token-plan-sgp.xiaomimimo.com/v1', 'auth' => 'api_key', 'driver' => 'openai-compatible'],
        ]), $config, $settings, new SettingsCodexTokenStore($settings));

        $providerOptions = $catalog->providerOptions();
        $options = $catalog->modelOptionsByProvider();

        $providerMap = [];
        foreach ($providerOptions as $option) {
            $providerMap[$option['value']] = $option;
        }

        $this->assertSame('Z.AI', $providerMap['z']['label']);
        $this->assertStringContainsString('2 models', $providerMap['z']['description']);
        $this->assertSame('GLM-5', $options['z'][0]['label']);
        $this->assertStringContainsString('glm-5', $options['z'][0]['description']);
        $this->assertStringContainsString('Coding Plan', $options['z'][0]['description']);
        $this->assertStringContainsString('$1/$3.2 per 1M', $options['z'][0]['description']);
        $this->assertStringContainsString('Free', $options['z'][1]['description']);
        $this->assertSame('Xiaomi MiMo Token Plan', $providerMap['mimo']['label']);
        $this->assertStringContainsString('Token Plan', $options['mimo'][0]['description']);
    }

    public function test_provider_catalog_hides_raw_source_providers_when_curated_alias_exists(): void
    {
        $meta = new ProviderMeta([
            'z-api' => [
                'default_model' => 'glm-5',
                'url' => 'https://open.bigmodel.cn/api/paas/v4',
                'models' => [
                    'glm-5' => ['display_name' => 'GLM-5', 'context' => 204800, 'max_output' => 131072],
                ],
            ],
            'zai' => [
                'default_model' => 'glm-5',
                'url' => 'https://open.bigmodel.cn/api/paas/v4',
                'models' => [
                    'glm-5' => ['display_name' => 'GLM-5', 'context' => 204800, 'max_output' => 131072],
                ],
            ],
            'zhipuai' => [
                'default_model' => 'glm-5',
                'url' => 'https://open.bigmodel.cn/api/paas/v4',
                'models' => [
                    'glm-5' => ['display_name' => 'GLM-5', 'context' => 204800, 'max_output' => 131072],
                ],
            ],
        ]);

        $config = new Repository([
            'prism' => [
                'providers' => [
                    'z-api' => ['url' => 'https://open.bigmodel.cn/api/paas/v4'],
                ],
            ],
        ]);

        $settings = new SettingsRepository(new Database(':memory:'));
        $catalog = new ProviderCatalog($meta, new RelayRegistry([
            'z-api' => [
                'url' => 'https://open.bigmodel.cn/api/paas/v4',
                'auth' => 'api_key',
                'driver' => 'openai-compatible',
                'source' => 'custom',
                'models_dev_provider' => 'zai',
            ],
            'zai' => [
                'url' => 'https://open.bigmodel.cn/api/paas/v4',
                'auth' => 'api_key',
                'driver' => 'openai-compatible',
                'source' => 'built_in',
            ],
            'zhipuai' => [
                'url' => 'https://open.bigmodel.cn/api/paas/v4',
                'auth' => 'api_key',
                'driver' => 'openai-compatible',
                'source' => 'built_in',
            ],
        ]), $config, $settings, new SettingsCodexTokenStore($settings));

        $providerIds = array_map(static fn (array $option): string => $option['value'], $catalog->providerOptions());

        $this->assertContains('z-api', $providerIds);
        $this->assertNotContains('zai', $providerIds);
        $this->assertNotContains('zhipuai', $providerIds);
    }

    public function test_provider_catalog_overlays_cached_live_models(): void
    {
        $meta = new ProviderMeta([
            'openai' => [
                'default_model' => 'gpt-4o',
                'url' => 'https://api.openai.com/v1',
                'models' => [
                    'gpt-4o' => ['display_name' => 'GPT-4o', 'context' => 128000, 'max_output' => 16384],
                ],
            ],
        ]);
        $config = new Repository([
            'prism' => ['providers' => ['openai' => ['url' => 'https://api.openai.com/v1']]],
        ]);
        $settings = new SettingsRepository(new Database(':memory:'));
        $cache = new ModelDiscoveryCacheRepository(new Database(':memory:'));
        $cache->putSuccess('openai', [
            new DiscoveredModel(id: 'gpt-4o', displayName: 'GPT-4o Live'),
            new DiscoveredModel(id: 'gpt-new', displayName: 'GPT New', contextWindow: 400000, maxOutput: 128000),
        ], 'provider_live', 3600);

        $catalog = new ProviderCatalog($meta, new RelayRegistry([
            'openai' => ['url' => 'https://api.openai.com/v1', 'auth' => 'api_key', 'driver' => 'openai'],
        ]), $config, $settings, new SettingsCodexTokenStore($settings), $cache);

        $provider = $catalog->provider('openai');

        $this->assertNotNull($provider);
        $this->assertSame('provider_live', $provider->modelSource);
        $this->assertTrue($provider->modelInventoryFresh);
        $this->assertSame(['gpt-4o', 'gpt-new'], $catalog->modelIds('openai'));
        $this->assertSame(128000, $provider->models[0]->contextWindow);
        $this->assertSame('GPT-4o Live', $provider->models[0]->displayName);
        $this->assertSame('provider_live', $provider->models[1]->source);
    }

    public function test_custom_openai_compatible_provider_allows_unlisted_models(): void
    {
        $meta = new ProviderMeta([
            'local-ai' => [
                'default_model' => 'known-model',
                'url' => 'http://127.0.0.1:11434/v1',
                'models' => [
                    'known-model' => ['display_name' => 'Known', 'context' => 128000, 'max_output' => 4096],
                ],
            ],
        ]);
        $config = new Repository([
            'relay' => [
                'providers' => [
                    'local-ai' => ['driver' => 'openai-compatible'],
                ],
            ],
            'prism' => ['providers' => ['local-ai' => ['url' => 'http://127.0.0.1:11434/v1']]],
        ]);
        $settings = new SettingsRepository(new Database(':memory:'));

        $catalog = new ProviderCatalog($meta, new RelayRegistry([
            'local-ai' => [
                'url' => 'http://127.0.0.1:11434/v1',
                'auth' => 'none',
                'driver' => 'openai-compatible',
                'source' => 'custom',
            ],
        ]), $config, $settings, new SettingsCodexTokenStore($settings));

        $provider = $catalog->provider('local-ai');

        $this->assertNotNull($provider);
        $this->assertTrue($provider->freeTextModel);
        $this->assertTrue($catalog->supportsModel('local-ai', 'brand-new-model'));
    }
}

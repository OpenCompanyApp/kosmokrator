<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Illuminate\Config\Repository;
use Kosmokrator\LLM\Codex\SettingsCodexTokenStore;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\RelayProviderRegistry;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SettingsRepository;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
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

        $catalog = new ProviderCatalog($meta, new RelayProviderRegistry($config), $config, $settings, $tokens);

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
        $catalog = new ProviderCatalog($meta, new RelayProviderRegistry($config), $config, $settings, new SettingsCodexTokenStore($settings));

        $provider = $catalog->provider('mimo');

        $this->assertNotNull($provider);
        $this->assertSame('custom', $provider->source);
        $this->assertSame('openai-compatible', $provider->driver);
        $this->assertSame(['text', 'image'], $provider->inputModalities);
        $this->assertSame(['text', 'image'], $provider->models[0]->inputModalities);
    }
}

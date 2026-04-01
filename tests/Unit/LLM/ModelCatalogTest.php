<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\ModelCatalog;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase
{
    public function test_models_for_provider_returns_provider_models(): void
    {
        $catalog = new ModelCatalog([
            'models' => [
                'glm-5.1' => ['provider' => 'z', 'context' => 1, 'input_price' => 0.0, 'output_price' => 0.0],
                'glm-5-turbo' => ['provider' => 'z', 'context' => 1, 'input_price' => 0.0, 'output_price' => 0.0],
                'kimi-k2.5' => ['provider' => 'kimi', 'context' => 1, 'input_price' => 0.0, 'output_price' => 0.0],
            ],
        ]);

        $this->assertSame(['glm-5.1', 'glm-5-turbo'], $catalog->modelsForProvider('z'));
    }

    public function test_models_for_alias_provider_uses_canonical_provider(): void
    {
        $catalog = new ModelCatalog([
            'models' => [
                'glm-5.1' => ['provider' => 'z', 'context' => 1, 'input_price' => 0.0, 'output_price' => 0.0],
                'glm-5-turbo' => ['provider' => 'z', 'context' => 1, 'input_price' => 0.0, 'output_price' => 0.0],
            ],
        ]);

        $this->assertSame(['glm-5.1', 'glm-5-turbo'], $catalog->modelsForProvider('z-api'));
    }

    public function test_models_by_provider_includes_alias_entries(): void
    {
        $catalog = new ModelCatalog([
            'models' => [
                'glm-5.1' => ['provider' => 'z', 'context' => 1, 'input_price' => 0.0, 'output_price' => 0.0],
                'kimi-k2.5' => ['provider' => 'kimi', 'context' => 1, 'input_price' => 0.0, 'output_price' => 0.0],
                'minimax-m1' => ['provider' => 'minimax', 'context' => 1, 'input_price' => 0.0, 'output_price' => 0.0],
            ],
        ]);

        $byProvider = $catalog->modelsByProvider();

        $this->assertSame(['glm-5.1'], $byProvider['z-api']);
        $this->assertSame(['kimi-k2.5'], $byProvider['kimi-coding']);
        $this->assertSame(['minimax-m1'], $byProvider['minimax-cn']);
    }

    public function test_shared_provider_metadata_populates_models_and_context(): void
    {
        $meta = new ProviderMeta([
            'openai' => [
                'default_model' => 'gpt-4o',
                'url' => 'https://api.openai.com/v1',
                'models' => [
                    'gpt-4o' => ['display_name' => 'GPT-4o', 'context' => 128000, 'max_output' => 16384, 'input' => 2.5, 'output' => 10.0],
                    'o3' => ['display_name' => 'o3', 'context' => 200000, 'max_output' => 100000, 'input' => 10.0, 'output' => 40.0, 'thinking' => true],
                ],
            ],
        ]);

        $catalog = new ModelCatalog(['models' => []], $meta);

        $this->assertSame(['gpt-4o', 'o3'], $catalog->modelsForProvider('openai'));
        $this->assertSame(200000, $catalog->contextWindow('o3'));
        $this->assertTrue($catalog->supportsThinking('o3'));
    }
}

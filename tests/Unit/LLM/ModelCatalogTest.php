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

    public function test_coding_plan_models_use_reference_price_for_display_cost_only(): void
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
                ],
            ],
        ]);

        $catalog = new ModelCatalog(['models' => []], $meta);

        $this->assertSame(0.0, $catalog->estimateActualCost('glm-5', 1_000_000, 1_000_000));
        $this->assertSame(4.2, $catalog->estimateDisplayCost('glm-5', 1_000_000, 1_000_000));
    }

    public function test_token_plan_models_have_zero_actual_and_display_cost_even_when_paid_variant_shares_id(): void
    {
        $meta = new ProviderMeta([
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
            'mimo-api' => [
                'default_model' => 'mimo-v2-pro',
                'url' => 'https://api.xiaomimimo.com/v1',
                'models' => [
                    'mimo-v2-pro' => [
                        'display_name' => 'MiMo V2 Pro',
                        'context' => 1048576,
                        'max_output' => 131072,
                        'input' => 2.5,
                        'output' => 10.0,
                        'pricing_kind' => 'paid',
                    ],
                ],
            ],
        ]);

        $catalog = new ModelCatalog(['models' => []], $meta);

        $this->assertSame(0.0, $catalog->estimateActualCost('mimo-v2-pro', 1_000_000, 1_000_000, 0, 0, 'mimo'));
        $this->assertSame(0.0, $catalog->estimateDisplayCost('mimo-v2-pro', 1_000_000, 1_000_000, 0, 0, 'mimo'));
        $this->assertSame(0.0, $catalog->estimateCacheSavings('mimo-v2-pro', 1_000_000, 500_000, 0, 'mimo'));
        $this->assertSame(12.5, $catalog->estimateActualCost('mimo-v2-pro', 1_000_000, 1_000_000, 0, 0, 'mimo-api'));
    }
}

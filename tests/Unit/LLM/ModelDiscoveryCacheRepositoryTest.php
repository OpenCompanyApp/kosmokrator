<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\ModelDiscovery\DiscoveredModel;
use Kosmokrator\LLM\ModelDiscovery\ModelDiscoveryCacheRepository;
use Kosmokrator\Session\Database;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryCacheRepositoryTest extends TestCase
{
    public function test_round_trips_successful_model_inventory(): void
    {
        $cache = new ModelDiscoveryCacheRepository(new Database(':memory:'));
        $cache->putSuccess('openai', [
            new DiscoveredModel(
                id: 'gpt-next',
                displayName: 'GPT Next',
                contextWindow: 400000,
                maxOutput: 128000,
                thinking: true,
                inputModalities: ['text', 'image'],
                outputModalities: ['text'],
            ),
        ], 'provider_live', 3600);

        $result = $cache->get('openai');

        $this->assertNotNull($result);
        $this->assertSame('provider_live', $result->source);
        $this->assertTrue($result->isFresh());
        $this->assertSame('gpt-next', $result->models[0]->id);
        $this->assertSame(['text', 'image'], $result->models[0]->inputModalities);
    }

    public function test_fresh_only_ignores_expired_inventory(): void
    {
        $cache = new ModelDiscoveryCacheRepository(new Database(':memory:'));
        $cache->putSuccess('openai', [new DiscoveredModel('gpt-old')], 'provider_live', -1);

        $this->assertNull($cache->get('openai', freshOnly: true));
        $this->assertNotNull($cache->get('openai'));
    }
}

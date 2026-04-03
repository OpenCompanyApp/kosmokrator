<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\ModelDefinition;
use Kosmokrator\LLM\ProviderDefinition;
use PHPUnit\Framework\TestCase;

final class ProviderDefinitionTest extends TestCase
{
    private function createDefinition(
        array $overrides = [],
        array $models = [],
        ?array $inputModalities = null,
        ?array $outputModalities = null,
    ): ProviderDefinition {
        $defaults = [
            'id' => 'test-provider',
            'label' => 'Test Provider',
            'description' => 'A test provider',
            'authMode' => 'api-key',
            'source' => 'builtin',
            'driver' => 'openai',
            'url' => 'https://api.test.com',
            'defaultModel' => 'test-model',
        ];

        $args = array_merge($defaults, $overrides);

        return new ProviderDefinition(
            id: $args['id'],
            label: $args['label'],
            description: $args['description'],
            authMode: $args['authMode'],
            source: $args['source'],
            driver: $args['driver'],
            url: $args['url'],
            defaultModel: $args['defaultModel'],
            models: $models,
            inputModalities: $inputModalities ?? ['text'],
            outputModalities: $outputModalities ?? ['text'],
        );
    }

    public function test_constructor_sets_all_required_properties(): void
    {
        $def = $this->createDefinition();

        $this->assertSame('test-provider', $def->id);
        $this->assertSame('Test Provider', $def->label);
        $this->assertSame('A test provider', $def->description);
        $this->assertSame('api-key', $def->authMode);
        $this->assertSame('builtin', $def->source);
        $this->assertSame('openai', $def->driver);
        $this->assertSame('https://api.test.com', $def->url);
        $this->assertSame('test-model', $def->defaultModel);
    }

    public function test_default_modalities_are_text(): void
    {
        $def = new ProviderDefinition(
            id: 'p',
            label: 'P',
            description: 'D',
            authMode: 'key',
            source: 's',
            driver: 'd',
            url: 'https://example.com',
            defaultModel: 'm',
            models: [],
        );

        $this->assertSame(['text'], $def->inputModalities);
        $this->assertSame(['text'], $def->outputModalities);
    }

    public function test_custom_modalities(): void
    {
        $def = $this->createDefinition(
            inputModalities: ['text', 'image'],
            outputModalities: ['text', 'audio'],
        );

        $this->assertSame(['text', 'image'], $def->inputModalities);
        $this->assertSame(['text', 'audio'], $def->outputModalities);
    }

    public function test_empty_models_array(): void
    {
        $def = $this->createDefinition(models: []);

        $this->assertSame([], $def->models);
    }

    public function test_models_with_model_definitions(): void
    {
        $modelA = new ModelDefinition(
            id: 'model-a',
            displayName: 'Model A',
            contextWindow: 128000,
            maxOutput: 4096,
        );
        $modelB = new ModelDefinition(
            id: 'model-b',
            displayName: 'Model B',
            contextWindow: 200000,
            maxOutput: 8192,
            thinking: true,
        );

        $def = $this->createDefinition(models: [$modelA, $modelB]);

        $this->assertCount(2, $def->models);
        $this->assertSame($modelA, $def->models[0]);
        $this->assertSame($modelB, $def->models[1]);
        $this->assertSame('model-a', $def->models[0]->id);
        $this->assertSame('model-b', $def->models[1]->id);
        $this->assertTrue($def->models[1]->thinking);
    }

    public function test_properties_are_readonly(): void
    {
        $def = $this->createDefinition();

        $this->expectException(\Error::class);
        $def->id = 'mutated';
    }
}

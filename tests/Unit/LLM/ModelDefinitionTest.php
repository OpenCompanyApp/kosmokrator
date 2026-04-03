<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\ModelDefinition;
use PHPUnit\Framework\TestCase;

class ModelDefinitionTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        $model = new ModelDefinition(
            id: 'claude-4-sonnet',
            displayName: 'Claude 4 Sonnet',
            contextWindow: 200_000,
            maxOutput: 16_384,
            thinking: true,
            inputPricePerMillion: 3.0,
            outputPricePerMillion: 15.0,
            pricingKind: 'paid',
            referenceInputPricePerMillion: 2.5,
            referenceOutputPricePerMillion: 12.0,
            status: 'active',
            inputModalities: ['text', 'image'],
            outputModalities: ['text'],
        );

        $this->assertSame('claude-4-sonnet', $model->id);
        $this->assertSame('Claude 4 Sonnet', $model->displayName);
        $this->assertSame(200_000, $model->contextWindow);
        $this->assertSame(16_384, $model->maxOutput);
        $this->assertTrue($model->thinking);
        $this->assertSame(3.0, $model->inputPricePerMillion);
        $this->assertSame(15.0, $model->outputPricePerMillion);
        $this->assertSame('paid', $model->pricingKind);
        $this->assertSame(2.5, $model->referenceInputPricePerMillion);
        $this->assertSame(12.0, $model->referenceOutputPricePerMillion);
        $this->assertSame('active', $model->status);
        $this->assertSame(['text', 'image'], $model->inputModalities);
        $this->assertSame(['text'], $model->outputModalities);
    }

    public function test_default_values(): void
    {
        $model = new ModelDefinition(
            id: 'gpt-4o',
            displayName: 'GPT-4o',
            contextWindow: 128_000,
            maxOutput: 4_096,
        );

        $this->assertFalse($model->thinking);
        $this->assertNull($model->inputPricePerMillion);
        $this->assertNull($model->outputPricePerMillion);
        $this->assertSame('paid', $model->pricingKind);
        $this->assertNull($model->referenceInputPricePerMillion);
        $this->assertNull($model->referenceOutputPricePerMillion);
        $this->assertNull($model->status);
        $this->assertSame(['text'], $model->inputModalities);
        $this->assertSame(['text'], $model->outputModalities);
    }

    public function test_label_returns_display_name_with_id_when_different(): void
    {
        $model = new ModelDefinition(
            id: 'claude-4-sonnet',
            displayName: 'Claude 4 Sonnet',
            contextWindow: 200_000,
            maxOutput: 16_384,
        );

        $this->assertSame('Claude 4 Sonnet (claude-4-sonnet)', $model->label());
    }

    public function test_label_returns_just_id_when_display_name_equals_id(): void
    {
        $model = new ModelDefinition(
            id: 'gpt-4o',
            displayName: 'gpt-4o',
            contextWindow: 128_000,
            maxOutput: 4_096,
        );

        $this->assertSame('gpt-4o', $model->label());
    }

    public function test_label_returns_just_id_when_display_name_is_empty(): void
    {
        $model = new ModelDefinition(
            id: 'gpt-4o',
            displayName: '',
            contextWindow: 128_000,
            maxOutput: 4_096,
        );

        $this->assertSame('gpt-4o', $model->label());
    }
}

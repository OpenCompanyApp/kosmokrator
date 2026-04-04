<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Settings;

use Kosmokrator\Settings\EffectiveSetting;
use Kosmokrator\Settings\SettingDefinition;
use PHPUnit\Framework\TestCase;

final class EffectiveSettingTest extends TestCase
{
    private SettingDefinition $definition;

    protected function setUp(): void
    {
        $this->definition = new SettingDefinition(
            id: 'test.setting',
            path: 'test.setting',
            label: 'Test Setting',
            description: 'A setting for testing',
            category: 'general',
        );
    }

    public function test_construction_with_string_value(): void
    {
        $setting = new EffectiveSetting(
            id: 'test.setting',
            value: 'hello',
            source: 'config.yaml',
            scope: 'project',
            definition: $this->definition,
        );

        $this->assertSame('test.setting', $setting->id);
        $this->assertSame('hello', $setting->value);
        $this->assertSame('config.yaml', $setting->source);
        $this->assertSame('project', $setting->scope);
        $this->assertSame($this->definition, $setting->definition);
    }

    public function test_construction_with_integer_value(): void
    {
        $setting = new EffectiveSetting(
            id: 'test.setting',
            value: 42,
            source: 'global.yaml',
            scope: 'global',
            definition: $this->definition,
        );

        $this->assertSame(42, $setting->value);
    }

    public function test_construction_with_boolean_value(): void
    {
        $setting = new EffectiveSetting(
            id: 'test.setting',
            value: true,
            source: 'config.yaml',
            scope: 'project',
            definition: $this->definition,
        );

        $this->assertTrue($setting->value);
    }

    public function test_construction_with_false_boolean_value(): void
    {
        $setting = new EffectiveSetting(
            id: 'test.setting',
            value: false,
            source: 'config.yaml',
            scope: 'project',
            definition: $this->definition,
        );

        $this->assertFalse($setting->value);
    }

    public function test_construction_with_array_value(): void
    {
        $value = ['foo' => 'bar', 'baz' => [1, 2, 3]];

        $setting = new EffectiveSetting(
            id: 'test.setting',
            value: $value,
            source: 'config.yaml',
            scope: 'project',
            definition: $this->definition,
        );

        $this->assertSame($value, $setting->value);
    }

    public function test_construction_with_null_value(): void
    {
        $setting = new EffectiveSetting(
            id: 'test.setting',
            value: null,
            source: 'config.yaml',
            scope: 'project',
            definition: $this->definition,
        );

        $this->assertNull($setting->value);
    }

    public function test_construction_with_empty_array_value(): void
    {
        $setting = new EffectiveSetting(
            id: 'test.setting',
            value: [],
            source: 'config.yaml',
            scope: 'project',
            definition: $this->definition,
        );

        $this->assertSame([], $setting->value);
    }

    public function test_properties_are_readonly(): void
    {
        $setting = new EffectiveSetting(
            id: 'test.setting',
            value: 'value',
            source: 'config.yaml',
            scope: 'project',
            definition: $this->definition,
        );

        $reflection = new \ReflectionClass($setting);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property \${$property->getName()} should be readonly",
            );
        }
    }

    public function test_class_is_readonly(): void
    {
        $reflection = new \ReflectionClass(EffectiveSetting::class);

        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_construction_with_different_definition(): void
    {
        $differentDefinition = new SettingDefinition(
            id: 'other.setting',
            path: 'other.setting',
            label: 'Other Setting',
            description: 'Another setting',
            category: 'advanced',
            type: 'boolean',
        );

        $setting = new EffectiveSetting(
            id: 'other.setting',
            value: true,
            source: 'overrides.yaml',
            scope: 'global',
            definition: $differentDefinition,
        );

        $this->assertSame($differentDefinition, $setting->definition);
        $this->assertSame('other.setting', $setting->id);
        $this->assertTrue($setting->value);
        $this->assertSame('overrides.yaml', $setting->source);
        $this->assertSame('global', $setting->scope);
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Settings;

use Kosmokrator\Settings\SettingDefinition;
use Kosmokrator\Settings\SettingsSchema;
use PHPUnit\Framework\TestCase;

final class SettingsSchemaTest extends TestCase
{
    private SettingsSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new SettingsSchema;
    }

    public function test_definitions_returns_non_empty_array(): void
    {
        $definitions = $this->schema->definitions();

        $this->assertIsArray($definitions);
        $this->assertNotEmpty($definitions);
    }

    public function test_definitions_returns_setting_definition_instances(): void
    {
        $definitions = $this->schema->definitions();

        foreach ($definitions as $key => $definition) {
            $this->assertInstanceOf(SettingDefinition::class, $definition);
            $this->assertSame($key, $definition->id);
        }
    }

    public function test_definition_with_valid_id_returns_definition(): void
    {
        $definition = $this->schema->definition('agent.mode');

        $this->assertInstanceOf(SettingDefinition::class, $definition);
        $this->assertSame('agent.mode', $definition->id);
    }

    public function test_definition_with_unknown_id_returns_null(): void
    {
        $this->assertNull($this->schema->definition('nonexistent.setting'));
    }

    public function test_canonical_id_resolves_known_aliases(): void
    {
        $this->assertSame('agent.mode', $this->schema->canonicalId('mode'));
        $this->assertSame('tools.default_permission_mode', $this->schema->canonicalId('permission_mode'));
        $this->assertSame('context.memories', $this->schema->canonicalId('memories'));
        $this->assertSame('context.auto_compact', $this->schema->canonicalId('auto_compact'));
        $this->assertSame('agent.temperature', $this->schema->canonicalId('temperature'));
        $this->assertSame('agent.max_tokens', $this->schema->canonicalId('max_tokens'));
    }

    public function test_canonical_id_passes_through_unknown_ids_unchanged(): void
    {
        $this->assertSame('agent.mode', $this->schema->canonicalId('agent.mode'));
        $this->assertSame('totally.unknown', $this->schema->canonicalId('totally.unknown'));
        $this->assertSame('', $this->schema->canonicalId(''));
    }

    public function test_categories_returns_expected_list(): void
    {
        $categories = $this->schema->categories();

        $this->assertSame([
            'general',
            'models',
            'provider_setup',
            'auth',
            'context_memory',
            'agent',
            'permissions',
            'integrations',
            'subagents',
            'advanced',
            'audio',
        ], $categories);
    }

    public function test_category_labels_maps_all_categories(): void
    {
        $labels = $this->schema->categoryLabels();
        $categories = $this->schema->categories();

        foreach ($categories as $category) {
            $this->assertArrayHasKey($category, $labels, "Category '{$category}' missing from categoryLabels()");
            $this->assertIsString($labels[$category]);
            $this->assertNotEmpty($labels[$category]);
        }
    }

    public function test_definitions_for_category_returns_correct_definitions(): void
    {
        $contextMemoryDefs = $this->schema->definitionsForCategory('context_memory');

        $this->assertNotEmpty($contextMemoryDefs);
        foreach ($contextMemoryDefs as $definition) {
            $this->assertInstanceOf(SettingDefinition::class, $definition);
            $this->assertSame('context_memory', $definition->category);
        }
    }

    public function test_definitions_for_category_with_unknown_category_returns_empty(): void
    {
        $this->assertSame([], $this->schema->definitionsForCategory('nonexistent'));
    }
}

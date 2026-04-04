<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\SettingsWorkspaceWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Tests for SettingsWorkspaceWidget focusing on constructor, state management,
 * field navigation, picker logic, buildResult, and other pure-logic methods.
 */
final class SettingsWorkspaceWidgetTest extends TestCase
{
    // ── Constructor ──────────────────────────────────────────────────────

    public function test_constructor_extracts_scope_from_view(): void
    {
        $widget = $this->createWidget(['scope' => 'global']);
        $scope = $this->getProperty($widget, 'scope');
        $this->assertSame('global', $scope);
    }

    public function test_constructor_defaults_scope_to_project(): void
    {
        $widget = $this->createWidget([]);
        $scope = $this->getProperty($widget, 'scope');
        $this->assertSame('project', $scope);
    }

    public function test_constructor_extracts_field_values(): void
    {
        $widget = $this->createWidget([
            'categories' => [
                [
                    'id' => 'general',
                    'label' => 'General',
                    'fields' => [
                        ['id' => 'agent.default_provider', 'label' => 'Provider', 'value' => 'openai'],
                        ['id' => 'agent.default_model', 'label' => 'Model', 'value' => 'gpt-4'],
                    ],
                ],
            ],
        ]);

        $values = $this->getProperty($widget, 'values');
        $this->assertSame('openai', $values['agent.default_provider']);
        $this->assertSame('gpt-4', $values['agent.default_model']);
    }

    public function test_constructor_stores_original_values(): void
    {
        $widget = $this->createWidget([
            'categories' => [
                [
                    'id' => 'general',
                    'label' => 'General',
                    'fields' => [
                        ['id' => 'foo', 'label' => 'Foo', 'value' => 'bar'],
                    ],
                ],
            ],
        ]);

        $original = $this->getProperty($widget, 'originalValues');
        $this->assertSame('bar', $original['foo']);
    }

    public function test_constructor_skips_fields_without_id(): void
    {
        $widget = $this->createWidget([
            'categories' => [
                [
                    'id' => 'general',
                    'label' => 'General',
                    'fields' => [
                        ['label' => 'No ID', 'value' => 'x'],
                        ['id' => 'valid', 'label' => 'Valid', 'value' => 'y'],
                    ],
                ],
            ],
        ]);

        $values = $this->getProperty($widget, 'values');
        $this->assertArrayNotHasKey('', $values);
        $this->assertSame('y', $values['valid']);
    }

    public function test_constructor_handles_empty_categories(): void
    {
        $widget = $this->createWidget(['categories' => []]);
        $values = $this->getProperty($widget, 'values');
        $this->assertSame([], $values);
    }

    // ── buildResult ──────────────────────────────────────────────────────

    public function test_build_result_detects_no_changes(): void
    {
        $widget = $this->createWidget([
            'scope' => 'project',
            'categories' => [
                [
                    'id' => 'general',
                    'label' => 'General',
                    'fields' => [
                        ['id' => 'foo', 'label' => 'Foo', 'value' => 'bar'],
                    ],
                ],
            ],
        ]);

        $result = $this->invoke($widget, 'buildResult');
        $this->assertSame('project', $result['scope']);
        $this->assertSame([], $result['changes']);
        $this->assertArrayHasKey('custom_provider', $result);
        $this->assertArrayHasKey('delete_custom_provider', $result);
    }

    public function test_build_result_detects_changed_values(): void
    {
        $widget = $this->createWidget([
            'scope' => 'global',
            'categories' => [
                [
                    'id' => 'general',
                    'label' => 'General',
                    'fields' => [
                        ['id' => 'foo', 'label' => 'Foo', 'value' => 'original'],
                    ],
                ],
            ],
        ]);

        // Modify a value
        $values = $this->getProperty($widget, 'values');
        $values['foo'] = 'changed';
        $this->setProperty($widget, 'values', $values);

        $result = $this->invoke($widget, 'buildResult');
        $this->assertSame('global', $result['scope']);
        $this->assertSame(['foo' => 'changed'], $result['changes']);
    }

    public function test_build_result_includes_delete_custom_provider(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'deleteCustomProviderId', 'my_custom');
        $result = $this->invoke($widget, 'buildResult');
        $this->assertSame('my_custom', $result['delete_custom_provider']);
    }

    // ── buildCustomProvider ──────────────────────────────────────────────

    public function test_build_custom_provider_returns_null_when_no_provider_id(): void
    {
        $widget = $this->createWidget([]);
        $values = ['custom_provider.id' => '', 'custom_provider.model_id' => 'model1'];
        $this->setProperty($widget, 'values', $values);
        $this->assertNull($this->invoke($widget, 'buildCustomProvider'));
    }

    public function test_build_custom_provider_returns_null_when_no_model_id(): void
    {
        $widget = $this->createWidget([]);
        $values = ['custom_provider.id' => 'custom_1', 'custom_provider.model_id' => ''];
        $this->setProperty($widget, 'values', $values);
        $this->assertNull($this->invoke($widget, 'buildCustomProvider'));
    }

    public function test_build_custom_provider_assembles_definition(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'values', [
            'custom_provider.id' => 'my_provider',
            'custom_provider.model_id' => 'model-1',
            'custom_provider.label' => 'My Provider',
            'custom_provider.driver' => 'openai-compatible',
            'custom_provider.auth' => 'api_key',
            'custom_provider.url' => 'https://api.example.com/v1',
            'custom_provider.default_model' => 'model-1',
            'custom_provider.context' => '128000',
            'custom_provider.max_output' => '4096',
            'custom_provider.input_modalities' => 'text, image',
            'custom_provider.output_modalities' => 'text',
        ]);

        $result = $this->invoke($widget, 'buildCustomProvider');
        $this->assertNotNull($result);
        $this->assertSame('my_provider', $result['id']);

        $def = $result['definition'];
        $this->assertSame('My Provider', $def['label']);
        $this->assertSame('openai-compatible', $def['driver']);
        $this->assertSame('api_key', $def['auth']);
        $this->assertSame('https://api.example.com/v1', $def['url']);
        $this->assertSame('model-1', $def['default_model']);
        $this->assertSame(['text', 'image'], $def['modalities']['input']);
        $this->assertSame(['text'], $def['modalities']['output']);

        $this->assertSame(128000, $def['models']['model-1']['context']);
        $this->assertSame(4096, $def['models']['model-1']['max_output']);
    }

    // ── csvValues ────────────────────────────────────────────────────────

    public function test_csv_values_splits_and_trims(): void
    {
        $widget = $this->createWidget([]);
        $result = $this->invoke($widget, 'csvValues', 'text, image , video');
        $this->assertSame(['text', 'image', 'video'], $result);
    }

    public function test_csv_values_filters_empty(): void
    {
        $widget = $this->createWidget([]);
        $result = $this->invoke($widget, 'csvValues', 'text,,image,');
        $this->assertSame(['text', 'image'], $result);
    }

    public function test_csv_values_empty_string(): void
    {
        $widget = $this->createWidget([]);
        $result = $this->invoke($widget, 'csvValues', '');
        $this->assertSame([], $result);
    }

    public function test_csv_values_single_value(): void
    {
        $widget = $this->createWidget([]);
        $result = $this->invoke($widget, 'csvValues', 'text');
        $this->assertSame(['text'], $result);
    }

    // ── wrap ─────────────────────────────────────────────────────────────

    public function test_wrap_returns_empty_for_empty_text(): void
    {
        $widget = $this->createWidget([]);
        $this->assertSame([], $this->invoke($widget, 'wrap', '', 40));
    }

    public function test_wrap_returns_empty_for_whitespace_only(): void
    {
        $widget = $this->createWidget([]);
        $this->assertSame([], $this->invoke($widget, 'wrap', '   ', 40));
    }

    public function test_wrap_single_short_word(): void
    {
        $widget = $this->createWidget([]);
        $result = $this->invoke($widget, 'wrap', 'hello', 40);
        $this->assertSame(['hello'], $result);
    }

    public function test_wrap_breaks_long_text(): void
    {
        $widget = $this->createWidget([]);
        $text = 'one two three four five six seven eight nine ten';
        $result = $this->invoke($widget, 'wrap', $text, 20);
        // Each line should fit within the width
        foreach ($result as $line) {
            $this->assertLessThanOrEqual(20, mb_strwidth($line));
        }
        $this->assertGreaterThan(1, count($result));
    }

    public function test_wrap_preserves_words(): void
    {
        $widget = $this->createWidget([]);
        $result = $this->invoke($widget, 'wrap', 'hello world', 5);
        // "hello" is 5 chars, "world" is 5 — they can't fit together
        $this->assertSame(['hello', 'world'], $result);
    }

    // ── categories / selectedCategory / selectedField ────────────────────

    public function test_categories_returns_from_view(): void
    {
        $categories = [
            ['id' => 'general', 'label' => 'General', 'fields' => []],
            ['id' => 'models', 'label' => 'Models', 'fields' => []],
        ];
        $widget = $this->createWidget(['categories' => $categories]);
        $this->assertSame($categories, $this->invoke($widget, 'categories'));
    }

    public function test_categories_returns_empty_when_missing(): void
    {
        $widget = $this->createWidget([]);
        $this->assertSame([], $this->invoke($widget, 'categories'));
    }

    public function test_selected_category_returns_by_index(): void
    {
        $categories = [
            ['id' => 'general', 'label' => 'General', 'fields' => []],
            ['id' => 'models', 'label' => 'Models', 'fields' => []],
        ];
        $widget = $this->createWidget(['categories' => $categories]);
        $this->setProperty($widget, 'categoryIndex', 1);
        $cat = $this->invoke($widget, 'selectedCategory');
        $this->assertSame('models', $cat['id']);
    }

    public function test_selected_category_returns_default_for_invalid_index(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'categoryIndex', 99);
        $cat = $this->invoke($widget, 'selectedCategory');
        $this->assertSame('general', $cat['id']);
    }

    public function test_selected_field_returns_null_when_empty(): void
    {
        $widget = $this->createWidget([
            'categories' => [['id' => 'general', 'label' => 'General', 'fields' => []]],
        ]);
        $this->assertNull($this->invoke($widget, 'selectedField'));
    }

    public function test_selected_field_returns_field_by_index(): void
    {
        $fields = [
            ['id' => 'f1', 'label' => 'Field 1', 'type' => 'text'],
            ['id' => 'f2', 'label' => 'Field 2', 'type' => 'text'],
        ];
        $widget = $this->createWidget([
            'categories' => [['id' => 'general', 'label' => 'General', 'fields' => $fields]],
        ]);
        $this->setProperty($widget, 'fieldIndex', 1);
        $field = $this->invoke($widget, 'selectedField');
        $this->assertNotNull($field);
        $this->assertSame('f2', $field['id']);
    }

    // ── isModelsCategory / isProviderSetupCategory ───────────────────────

    public function test_is_models_category_true(): void
    {
        $widget = $this->createWidget([
            'categories' => [['id' => 'models', 'label' => 'Models', 'fields' => []]],
        ]);
        $this->setProperty($widget, 'categoryIndex', 0);
        $this->assertTrue($this->invoke($widget, 'isModelsCategory'));
    }

    public function test_is_models_category_false(): void
    {
        $widget = $this->createWidget([
            'categories' => [['id' => 'general', 'label' => 'General', 'fields' => []]],
        ]);
        $this->setProperty($widget, 'categoryIndex', 0);
        $this->assertFalse($this->invoke($widget, 'isModelsCategory'));
    }

    public function test_is_provider_setup_category_true(): void
    {
        $widget = $this->createWidget([
            'categories' => [['id' => 'provider_setup', 'label' => 'Provider Setup', 'fields' => []]],
        ]);
        $this->setProperty($widget, 'categoryIndex', 0);
        $this->assertTrue($this->invoke($widget, 'isProviderSetupCategory'));
    }

    // ── fieldSupportsPicker ──────────────────────────────────────────────

    public function test_field_supports_picker_for_choice_type(): void
    {
        $widget = $this->createWidget([]);
        $field = ['id' => 'test', 'type' => 'choice', 'options' => []];
        $this->assertTrue($this->invoke($widget, 'fieldSupportsPicker', $field));
    }

    public function test_field_supports_picker_for_toggle_type(): void
    {
        $widget = $this->createWidget([]);
        $field = ['id' => 'test', 'type' => 'toggle'];
        $this->assertTrue($this->invoke($widget, 'fieldSupportsPicker', $field));
    }

    public function test_field_supports_picker_for_text_type_without_options(): void
    {
        $widget = $this->createWidget([]);
        $field = ['id' => 'test', 'type' => 'text'];
        $this->assertFalse($this->invoke($widget, 'fieldSupportsPicker', $field));
    }

    public function test_field_supports_picker_for_text_type_with_options(): void
    {
        $widget = $this->createWidget([]);
        $field = ['id' => 'test', 'type' => 'text', 'options' => ['a', 'b']];
        $this->assertTrue($this->invoke($widget, 'fieldSupportsPicker', $field));
    }

    // ── optionsForField ──────────────────────────────────────────────────

    public function test_options_for_field_falls_back_to_field_options(): void
    {
        $widget = $this->createWidget([]);
        $field = ['id' => 'some_field', 'options' => ['alpha', 'beta']];
        $result = $this->invoke($widget, 'optionsForField', $field);
        $this->assertCount(2, $result);
        $this->assertSame('alpha', $result[0]['value']);
        $this->assertSame('beta', $result[1]['value']);
    }

    public function test_options_for_field_provider_setup(): void
    {
        $widget = $this->createWidget([
            'setup_provider_options' => [
                ['value' => 'openai', 'label' => 'OpenAI', 'description' => 'GPT models'],
            ],
        ]);
        $field = ['id' => 'provider.setup_provider'];
        $result = $this->invoke($widget, 'optionsForField', $field);
        $this->assertCount(1, $result);
        $this->assertSame('openai', $result[0]['value']);
        $this->assertSame('OpenAI', $result[0]['label']);
    }

    public function test_options_for_field_default_provider(): void
    {
        $widget = $this->createWidget([
            'provider_options' => [
                ['value' => 'openai', 'label' => 'OpenAI', 'description' => ''],
                ['value' => 'anthropic', 'label' => 'Anthropic', 'description' => ''],
            ],
        ]);
        $field = ['id' => 'agent.default_provider'];
        $result = $this->invoke($widget, 'optionsForField', $field);
        $this->assertCount(2, $result);
    }

    public function test_options_for_field_default_model_by_provider(): void
    {
        $widget = $this->createWidget([
            'model_options_by_provider' => [
                'openai' => [
                    ['value' => 'gpt-4', 'label' => 'GPT-4', 'description' => ''],
                ],
            ],
        ]);
        $values = ['agent.default_provider' => 'openai'];
        $this->setProperty($widget, 'values', $values);
        $field = ['id' => 'agent.default_model'];
        $result = $this->invoke($widget, 'optionsForField', $field);
        $this->assertCount(1, $result);
        $this->assertSame('gpt-4', $result[0]['value']);
    }

    public function test_options_for_field_no_matching_provider(): void
    {
        $widget = $this->createWidget([
            'model_options_by_provider' => [
                'openai' => [['value' => 'gpt-4', 'label' => 'GPT-4', 'description' => '']],
            ],
        ]);
        $values = ['agent.default_provider' => 'unknown'];
        $this->setProperty($widget, 'values', $values);
        $field = ['id' => 'agent.default_model'];
        $result = $this->invoke($widget, 'optionsForField', $field);
        $this->assertSame([], $result);
    }

    // ── displayLabelForFieldValue ────────────────────────────────────────

    public function test_display_label_returns_empty_for_empty_value(): void
    {
        $widget = $this->createWidget([]);
        $this->assertSame('', $this->invoke($widget, 'displayLabelForFieldValue', 'any_field', ''));
    }

    public function test_display_label_returns_raw_value_when_no_options(): void
    {
        $widget = $this->createWidget([]);
        $this->assertSame('raw', $this->invoke($widget, 'displayLabelForFieldValue', 'unknown.field', 'raw'));
    }

    public function test_display_label_resolves_option_label(): void
    {
        $widget = $this->createWidget([
            'provider_options' => [
                ['value' => 'openai', 'label' => 'OpenAI', 'description' => ''],
            ],
        ]);
        $this->assertSame('OpenAI (openai)', $this->invoke($widget, 'displayLabelForFieldValue', 'agent.default_provider', 'openai'));
    }

    // ── modelBrowserItems ────────────────────────────────────────────────

    public function test_model_browser_items_builds_tree(): void
    {
        $widget = $this->createWidget([
            'provider_options' => [
                ['value' => 'openai', 'label' => 'OpenAI'],
            ],
            'model_options_by_provider' => [
                'openai' => [
                    ['value' => 'gpt-4', 'label' => 'GPT-4', 'description' => 'Large model'],
                    ['value' => 'gpt-4o', 'label' => 'GPT-4o', 'description' => 'Fast model'],
                ],
            ],
        ]);

        $items = $this->invoke($widget, 'modelBrowserItems');
        // 1 provider header + 2 models = 3 items
        $this->assertCount(3, $items);
        $this->assertSame('provider', $items[0]['type']);
        $this->assertSame('openai', $items[0]['provider']);
        $this->assertSame('model', $items[1]['type']);
        $this->assertSame('gpt-4', $items[1]['model']);
        $this->assertSame('model', $items[2]['type']);
        $this->assertSame('gpt-4o', $items[2]['model']);
    }

    public function test_model_browser_items_empty_when_no_providers(): void
    {
        $widget = $this->createWidget([]);
        $this->assertSame([], $this->invoke($widget, 'modelBrowserItems'));
    }

    // ── providerSetupItems ───────────────────────────────────────────────

    public function test_provider_setup_items_builds_list(): void
    {
        $widget = $this->createWidget([
            'setup_provider_options' => [
                ['value' => 'openai', 'label' => 'OpenAI', 'description' => 'GPT'],
                ['value' => '__custom__', 'label' => '+ New Custom', 'description' => ''],
            ],
            'providers_by_id' => [
                'openai' => ['source' => 'built_in', 'auth_status' => 'Configured'],
            ],
        ]);

        $items = $this->invoke($widget, 'providerSetupItems');
        $this->assertCount(2, $items);
        $this->assertSame('openai', $items[0]['value']);
        $this->assertSame('built_in', $items[0]['source']);
        $this->assertSame('Configured', $items[0]['auth_status']);
        $this->assertSame('__custom__', $items[1]['value']);
        $this->assertSame('custom', $items[1]['source']);
        $this->assertSame('Not configured', $items[1]['auth_status']);
    }

    // ── nextCustomProviderId ─────────────────────────────────────────────

    public function test_next_custom_provider_id_first(): void
    {
        $widget = $this->createWidget(['custom_provider_definitions' => []]);
        $this->assertSame('custom_1', $this->invoke($widget, 'nextCustomProviderId'));
    }

    public function test_next_custom_provider_id_skips_existing(): void
    {
        $widget = $this->createWidget([
            'custom_provider_definitions' => ['custom_1' => [], 'custom_2' => []],
        ]);
        $this->assertSame('custom_3', $this->invoke($widget, 'nextCustomProviderId'));
    }

    public function test_next_custom_provider_id_fills_gap(): void
    {
        $widget = $this->createWidget([
            'custom_provider_definitions' => ['custom_1' => [], 'custom_3' => []],
        ]);
        $this->assertSame('custom_2', $this->invoke($widget, 'nextCustomProviderId'));
    }

    // ── onSave / onCancel callbacks ──────────────────────────────────────

    public function test_on_save_registers_callback(): void
    {
        $widget = $this->createWidget([]);
        $called = false;
        $widget->onSave(static function () use (&$called): void {
            $called = true;
        });

        // Trigger save via handleInput
        $widget->handleInput('s');
        // The callback uses buildResult internally; we verify it was invoked
        $this->assertTrue($called, 'onSave callback should have been invoked');
    }

    public function test_on_cancel_registers_callback(): void
    {
        $widget = $this->createWidget([]);
        $called = false;
        $widget->onCancel(static function () use (&$called): void {
            $called = true;
        });

        $widget->handleInput('q');
        $this->assertTrue($called, 'onCancel callback should have been invoked');
    }

    public function test_on_save_returns_self(): void
    {
        $widget = $this->createWidget([]);
        $result = $widget->onSave(static function (): void {});
        $this->assertSame($widget, $result);
    }

    public function test_on_cancel_returns_self(): void
    {
        $widget = $this->createWidget([]);
        $result = $widget->onCancel(static function (): void {});
        $this->assertSame($widget, $result);
    }

    // ── handleInput: scope switching ─────────────────────────────────────

    public function test_handle_input_g_sets_global_scope(): void
    {
        $widget = $this->createWidget([]);
        $widget->handleInput('g');
        $this->assertSame('global', $this->getProperty($widget, 'scope'));
    }

    public function test_handle_input_p_sets_project_scope(): void
    {
        $widget = $this->createWidget(['scope' => 'global']);
        $widget->handleInput('p');
        $this->assertSame('project', $this->getProperty($widget, 'scope'));
    }

    // ── handleInput: editing mode ────────────────────────────────────────

    public function test_handle_input_editing_typing_and_confirm(): void
    {
        $widget = $this->createWidget([
            'categories' => [
                ['id' => 'general', 'label' => 'General', 'fields' => [
                    ['id' => 'test', 'label' => 'Test', 'type' => 'text', 'value' => ''],
                ]],
            ],
        ]);

        // Set editing mode directly and populate buffer
        $this->setProperty($widget, 'editing', true);
        $this->setProperty($widget, 'editBuffer', '');

        // Type some text while editing
        $widget->handleInput('H');
        $widget->handleInput('i');
        $this->assertSame('Hi', $this->getProperty($widget, 'editBuffer'));
        $this->assertTrue($this->getProperty($widget, 'editing'));
    }

    // ── handleInput: reset field ─────────────────────────────────────────

    public function test_handle_input_r_resets_field_to_original(): void
    {
        $widget = $this->createWidget([
            'categories' => [
                ['id' => 'general', 'label' => 'General', 'fields' => [
                    ['id' => 'foo', 'label' => 'Foo', 'type' => 'text', 'value' => 'original'],
                ]],
            ],
        ]);

        // Modify the value
        $values = $this->getProperty($widget, 'values');
        $values['foo'] = 'changed';
        $this->setProperty($widget, 'values', $values);

        // Press 'r' to reset
        $widget->handleInput('r');
        $this->assertSame('original', $this->getProperty($widget, 'values')['foo']);
    }

    // ── render output sanity ─────────────────────────────────────────────

    public function test_render_returns_lines_array(): void
    {
        $widget = $this->createWidget([
            'categories' => [
                ['id' => 'general', 'label' => 'General', 'fields' => []],
            ],
        ]);
        $context = new RenderContext(120, 30);
        $lines = $widget->render($context);
        $this->assertIsArray($lines);
        $this->assertNotEmpty($lines);
    }

    public function test_render_output_truncated_to_terminal_width(): void
    {
        $widget = $this->createWidget([
            'categories' => [
                ['id' => 'general', 'label' => 'General', 'fields' => []],
            ],
        ]);
        $context = new RenderContext(80, 24);
        $lines = $widget->render($context);

        foreach ($lines as $i => $line) {
            $visible = preg_replace('/\033\[[0-9;]*m/', '', $line);
            $this->assertLessThanOrEqual(
                80,
                mb_strwidth($visible),
                "Line {$i} exceeds terminal width",
            );
        }
    }

    public function test_render_includes_settings_header(): void
    {
        $widget = $this->createWidget([
            'scope' => 'project',
            'categories' => [
                ['id' => 'general', 'label' => 'General', 'fields' => []],
            ],
        ]);
        $context = new RenderContext(120, 30);
        $lines = $widget->render($context);
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('Settings', $joined);
    }

    // ── handleFieldSideEffects ───────────────────────────────────────────

    public function test_handle_field_side_effects_resets_model_on_provider_change(): void
    {
        $widget = $this->createWidget([
            'categories' => [],
            'provider_options' => [
                ['value' => 'openai', 'label' => 'OpenAI', 'description' => ''],
                ['value' => 'anthropic', 'label' => 'Anthropic', 'description' => ''],
            ],
            'model_options_by_provider' => [
                'openai' => [['value' => 'gpt-4', 'label' => 'GPT-4', 'description' => '']],
                'anthropic' => [['value' => 'claude-3', 'label' => 'Claude 3', 'description' => '']],
            ],
            'provider_statuses' => ['openai' => 'Configured'],
            'provider_api_key_display' => ['openai' => 'sk-***'],
            'providers_by_id' => [
                'openai' => ['auth_status' => 'Configured'],
            ],
        ]);

        $values = [
            'agent.default_provider' => 'openai',
            'agent.default_model' => 'gpt-4',
        ];
        $this->setProperty($widget, 'values', $values);

        // Change to a provider where the model doesn't exist
        $values['agent.default_provider'] = 'anthropic';
        $this->setProperty($widget, 'values', $values);
        $this->invoke($widget, 'handleFieldSideEffects', 'agent.default_provider');

        $updated = $this->getProperty($widget, 'values');
        $this->assertSame('claude-3', $updated['agent.default_model']);
    }

    public function test_handle_field_side_effects_updates_auth_status(): void
    {
        $widget = $this->createWidget([
            'categories' => [],
            'provider_statuses' => ['anthropic' => 'Not configured'],
            'provider_api_key_display' => ['anthropic' => ''],
            'providers_by_id' => ['anthropic' => ['auth_status' => 'Not configured']],
        ]);

        $values = ['agent.default_provider' => 'anthropic'];
        $this->setProperty($widget, 'values', $values);
        $this->invoke($widget, 'handleFieldSideEffects', 'agent.default_provider');

        $updated = $this->getProperty($widget, 'values');
        $this->assertSame('Not configured', $updated['provider.auth_status']);
    }

    public function test_handle_field_side_effects_custom_provider_setup(): void
    {
        $widget = $this->createWidget([
            'categories' => [],
            'setup_provider_options' => [],
            'providers_by_id' => [],
        ]);

        $values = ['provider.setup_provider' => '__custom__'];
        $this->setProperty($widget, 'values', $values);
        $this->invoke($widget, 'handleFieldSideEffects', 'provider.setup_provider');

        $updated = $this->getProperty($widget, 'values');
        $this->assertSame('Not configured', $updated['provider.setup_status']);
        $this->assertSame('openai-compatible', $updated['custom_provider.driver']);
        $this->assertSame('api_key', $updated['custom_provider.auth']);
    }

    // ── visiblePickerOptions ─────────────────────────────────────────────

    public function test_visible_picker_options_returns_all_when_no_query(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'pickerQuery', '');
        $options = [
            ['value' => 'a', 'label' => 'Alpha', 'description' => ''],
            ['value' => 'b', 'label' => 'Beta', 'description' => ''],
        ];
        $this->setProperty($widget, 'pickerOptions', $options);
        $result = $this->invoke($widget, 'visiblePickerOptions');
        $this->assertCount(2, $result);
    }

    public function test_visible_picker_options_filters_by_query(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'pickerQuery', 'alpha');
        $options = [
            ['value' => 'a', 'label' => 'Alpha', 'description' => 'First letter'],
            ['value' => 'b', 'label' => 'Beta', 'description' => 'Second letter'],
        ];
        $this->setProperty($widget, 'pickerOptions', $options);
        $result = $this->invoke($widget, 'visiblePickerOptions');
        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]['value']);
    }

    public function test_visible_picker_options_filters_case_insensitive(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'pickerQuery', 'BETA');
        $options = [
            ['value' => 'a', 'label' => 'Alpha', 'description' => ''],
            ['value' => 'b', 'label' => 'Beta', 'description' => ''],
        ];
        $this->setProperty($widget, 'pickerOptions', $options);
        $result = $this->invoke($widget, 'visiblePickerOptions');
        $this->assertCount(1, $result);
        $this->assertSame('b', $result[0]['value']);
    }

    public function test_visible_picker_options_searches_description_too(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'pickerQuery', 'second');
        $options = [
            ['value' => 'a', 'label' => 'Alpha', 'description' => 'First'],
            ['value' => 'b', 'label' => 'Beta', 'description' => 'Second letter'],
        ];
        $this->setProperty($widget, 'pickerOptions', $options);
        $result = $this->invoke($widget, 'visiblePickerOptions');
        $this->assertCount(1, $result);
        $this->assertSame('b', $result[0]['value']);
    }

    public function test_visible_picker_options_empty_result(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'pickerQuery', 'xyz');
        $options = [
            ['value' => 'a', 'label' => 'Alpha', 'description' => ''],
        ];
        $this->setProperty($widget, 'pickerOptions', $options);
        $result = $this->invoke($widget, 'visiblePickerOptions');
        $this->assertSame([], $result);
    }

    // ── resetPickerIndex ─────────────────────────────────────────────────

    public function test_reset_picker_index_selects_current_value(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'pickerFieldId', 'test');
        $this->setProperty($widget, 'pickerQuery', '');
        $values = ['test' => 'b'];
        $this->setProperty($widget, 'values', $values);
        $options = [
            ['value' => 'a', 'label' => 'Alpha', 'description' => ''],
            ['value' => 'b', 'label' => 'Beta', 'description' => ''],
            ['value' => 'c', 'label' => 'Gamma', 'description' => ''],
        ];
        $this->setProperty($widget, 'pickerOptions', $options);
        $this->invoke($widget, 'resetPickerIndex');
        $this->assertSame(1, $this->getProperty($widget, 'pickerIndex'));
    }

    public function test_reset_picker_index_defaults_to_zero(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'pickerFieldId', 'test');
        $this->setProperty($widget, 'pickerQuery', '');
        $values = ['test' => 'missing'];
        $this->setProperty($widget, 'values', $values);
        $options = [
            ['value' => 'a', 'label' => 'Alpha', 'description' => ''],
        ];
        $this->setProperty($widget, 'pickerOptions', $options);
        $this->invoke($widget, 'resetPickerIndex');
        $this->assertSame(0, $this->getProperty($widget, 'pickerIndex'));
    }

    // ── closePicker ──────────────────────────────────────────────────────

    public function test_close_picker_resets_state(): void
    {
        $widget = $this->createWidget([]);
        $this->setProperty($widget, 'pickerOpen', true);
        $this->setProperty($widget, 'pickerFieldId', 'test');
        $this->setProperty($widget, 'pickerOptions', [['value' => 'x', 'label' => 'X', 'description' => '']]);
        $this->setProperty($widget, 'pickerIndex', 2);
        $this->setProperty($widget, 'pickerQuery', 'filter');

        $this->invoke($widget, 'closePicker');

        $this->assertFalse($this->getProperty($widget, 'pickerOpen'));
        $this->assertSame('', $this->getProperty($widget, 'pickerFieldId'));
        $this->assertSame([], $this->getProperty($widget, 'pickerOptions'));
        $this->assertSame(0, $this->getProperty($widget, 'pickerIndex'));
        $this->assertSame('', $this->getProperty($widget, 'pickerQuery'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createWidget(array $view): SettingsWorkspaceWidget
    {
        return new SettingsWorkspaceWidget($view);
    }

    private function invoke(SettingsWorkspaceWidget $widget, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($widget, $method);

        return $ref->invoke($widget, ...$args);
    }

    private function getProperty(SettingsWorkspaceWidget $widget, string $property): mixed
    {
        $ref = new \ReflectionProperty($widget, $property);

        return $ref->getValue($widget);
    }

    private function setProperty(SettingsWorkspaceWidget $widget, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($widget, $property);
        $ref->setValue($widget, $value);
    }
}

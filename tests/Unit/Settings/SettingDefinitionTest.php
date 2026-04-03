<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Settings;

use Kosmokrator\Settings\SettingDefinition;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionTest extends TestCase
{
    public function testConstructorWithRequiredArgsOnly(): void
    {
        $def = new SettingDefinition(
            id: 'app.name',
            path: 'app.name',
            label: 'Application Name',
            description: 'The name of the application',
            category: 'general',
        );

        $this->assertSame('app.name', $def->id);
        $this->assertSame('app.name', $def->path);
        $this->assertSame('Application Name', $def->label);
        $this->assertSame('The name of the application', $def->description);
        $this->assertSame('general', $def->category);
    }

    public function testDefaultValues(): void
    {
        $def = new SettingDefinition(
            id: 'test',
            path: 'test',
            label: 'Test',
            description: 'Test',
            category: 'general',
        );

        $this->assertSame('text', $def->type);
        $this->assertSame([], $def->options);
        $this->assertSame('applies_now', $def->effect);
        $this->assertSame(['global', 'project'], $def->scopes);
        $this->assertNull($def->default);
    }

    public function testCustomValuesForAllProperties(): void
    {
        $def = new SettingDefinition(
            id: 'theme.mode',
            path: 'ui.theme.mode',
            label: 'Theme Mode',
            description: 'Select light or dark theme',
            category: 'ui',
            type: 'select',
            options: ['light', 'dark', 'auto'],
            effect: 'requires_restart',
            scopes: ['global'],
            default: 'auto',
        );

        $this->assertSame('theme.mode', $def->id);
        $this->assertSame('ui.theme.mode', $def->path);
        $this->assertSame('Theme Mode', $def->label);
        $this->assertSame('Select light or dark theme', $def->description);
        $this->assertSame('ui', $def->category);
        $this->assertSame('select', $def->type);
        $this->assertSame(['light', 'dark', 'auto'], $def->options);
        $this->assertSame('requires_restart', $def->effect);
        $this->assertSame(['global'], $def->scopes);
        $this->assertSame('auto', $def->default);
    }

    public function testTypeText(): void
    {
        $def = new SettingDefinition(
            id: 'user.name',
            path: 'user.name',
            label: 'User Name',
            description: 'Display name',
            category: 'user',
            type: 'text',
        );

        $this->assertSame('text', $def->type);
    }

    public function testTypeSelectWithOptions(): void
    {
        $options = ['option_a', 'option_b', 'option_c'];

        $def = new SettingDefinition(
            id: 'output.format',
            path: 'output.format',
            label: 'Output Format',
            description: 'Choose the output format',
            category: 'output',
            type: 'select',
            options: $options,
        );

        $this->assertSame('select', $def->type);
        $this->assertSame($options, $def->options);
    }

    public function testTypeBoolean(): void
    {
        $def = new SettingDefinition(
            id: 'debug.enabled',
            path: 'debug.enabled',
            label: 'Debug Mode',
            description: 'Enable debug output',
            category: 'developer',
            type: 'boolean',
            default: false,
        );

        $this->assertSame('boolean', $def->type);
        $this->assertFalse($def->default);
    }

    public function testScopeGlobalOnly(): void
    {
        $def = new SettingDefinition(
            id: 'api.key',
            path: 'api.key',
            label: 'API Key',
            description: 'Global API key',
            category: 'api',
            scopes: ['global'],
        );

        $this->assertSame(['global'], $def->scopes);
    }

    public function testScopeProjectOnly(): void
    {
        $def = new SettingDefinition(
            id: 'project.root',
            path: 'project.root',
            label: 'Project Root',
            description: 'Project root directory',
            category: 'project',
            scopes: ['project'],
        );

        $this->assertSame(['project'], $def->scopes);
    }

    public function testScopeBothGlobalAndProject(): void
    {
        $def = new SettingDefinition(
            id: 'editor.tab_size',
            path: 'editor.tab_size',
            label: 'Tab Size',
            description: 'Number of spaces per tab',
            category: 'editor',
            scopes: ['global', 'project'],
        );

        $this->assertSame(['global', 'project'], $def->scopes);
    }

    public function testClassIsReadonly(): void
    {
        $reflection = new \ReflectionClass(SettingDefinition::class);

        $this->assertTrue($reflection->isReadOnly());
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(SettingDefinition::class);

        $this->assertTrue($reflection->isFinal());
    }
}

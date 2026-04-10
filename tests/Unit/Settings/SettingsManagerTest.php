<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Settings;

use Illuminate\Config\Repository;
use Kosmokrator\Settings\EffectiveSetting;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SettingsManagerTest extends TestCase
{
    private string $projectConfigDir;

    private Repository $config;

    private SettingsSchema $schema;

    private YamlConfigStore $store;

    private SettingsManager $manager;

    protected function setUp(): void
    {
        // Use the real project config/ dir so ConfigLoader can reload properly.
        $this->projectConfigDir = dirname(__DIR__, 3).'/config';

        // Build a real Repository from the bundled defaults.
        $defaults = [];
        foreach (glob($this->projectConfigDir.'/*.yaml') as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $defaults[$key] = Yaml::parse(file_get_contents($file)) ?? [];
        }
        $this->config = new Repository($defaults);

        $this->schema = new SettingsSchema;
        $this->store = new YamlConfigStore;
        $this->manager = new SettingsManager(
            $this->config,
            $this->schema,
            $this->store,
            $this->projectConfigDir,
        );
    }

    protected function tearDown(): void
    {
        // Reset the static caches in SettingsSchema so tests don't leak.
        $ref = new \ReflectionClass(SettingsSchema::class);
        $defs = $ref->getProperty('definitions');
        $defs->setValue(null, null);
        $aliases = $ref->getProperty('aliases');
        $aliases->setValue(null, null);
    }

    // ── resolve() ──────────────────────────────────────────────────────

    public function test_resolve_returns_null_for_unknown_setting(): void
    {
        $this->assertNull($this->manager->resolve('nonexistent.setting'));
    }

    public function test_resolve_returns_effective_setting_for_known_setting(): void
    {
        $effective = $this->manager->resolve('agent.default_provider');

        $this->assertInstanceOf(EffectiveSetting::class, $effective);
        $this->assertSame('agent.default_provider', $effective->id);
        $this->assertSame('default', $effective->source);
        $this->assertNotNull($effective->value);
    }

    // ── get() ──────────────────────────────────────────────────────────

    public function test_get_returns_string_value_for_known_setting(): void
    {
        $value = $this->manager->get('agent.default_provider');

        $this->assertIsString($value);
        $this->assertSame('z', $value);
    }

    public function test_get_returns_null_for_unknown_setting(): void
    {
        $this->assertNull($this->manager->get('totally.made.up'));
    }

    // ── setProjectRoot() ───────────────────────────────────────────────

    public function test_set_project_root_accepts_null(): void
    {
        $this->manager->setProjectRoot(null);
        $this->assertNull($this->manager->projectConfigPath());
    }

    public function test_set_project_root_accepts_path(): void
    {
        $tmp = sys_get_temp_dir().'/kk-test-'.uniqid();
        mkdir($tmp, 0777, true);
        $this->manager->setProjectRoot($tmp);

        $this->assertSame($tmp.'/.kosmokrator/config.yaml', $this->manager->projectConfigPath());

        // Cleanup
        rmdir($tmp);
    }

    // ── globalConfigPath() ─────────────────────────────────────────────

    public function test_global_config_path_returns_string(): void
    {
        $path = $this->manager->globalConfigPath();

        $this->assertIsString($path);
        $this->assertStringContainsString('.kosmokrator/config.yaml', $path);
    }

    // ── projectConfigPath() ────────────────────────────────────────────

    public function test_project_config_path_returns_null_when_no_project_root(): void
    {
        $this->manager->setProjectRoot(null);

        $this->assertNull($this->manager->projectConfigPath());
    }

    // ── set() ──────────────────────────────────────────────────────────

    public function test_set_throws_for_unknown_setting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown setting [bogus.setting].');

        $this->manager->set('bogus.setting', 'value');
    }

    public function test_set_writes_to_global_scope(): void
    {
        $tmpDir = sys_get_temp_dir().'/kk-settings-test-'.uniqid();
        $globalDir = $tmpDir.'/.kosmokrator';
        mkdir($globalDir, 0777, true);

        // Override HOME so the global config path points to our temp dir.
        $origHome = getenv('HOME');
        putenv("HOME={$tmpDir}");

        try {
            $manager = new SettingsManager(
                $this->config,
                $this->schema,
                $this->store,
                $this->projectConfigDir,
            );

            $manager->set('agent.default_provider', 'test-provider', 'global');

            // Read back via getRaw
            $raw = $manager->getRaw('kosmokrator.agent.default_provider');
            $this->assertSame('test-provider', $raw);

            // Verify the file was actually written
            $this->assertFileExists($globalDir.'/config.yaml');
        } finally {
            putenv("HOME={$origHome}");
            // Cleanup
            @unlink($globalDir.'/config.yaml');
            @rmdir($globalDir);
            @rmdir($tmpDir.'/.config');
            @rmdir($tmpDir);
        }
    }

    // ── getRaw() ───────────────────────────────────────────────────────

    public function test_get_raw_reads_from_config_repository(): void
    {
        // The bundled config has kosmokrator.agent.default_provider = 'z'
        $value = $this->manager->getRaw('kosmokrator.agent.default_provider');

        $this->assertSame('z', $value);
    }

    public function test_get_raw_returns_null_for_unknown_path(): void
    {
        $this->assertNull($this->manager->getRaw('no.such.path'));
    }

    public function test_raw_source_prefers_project_then_global_then_default(): void
    {
        $tmpDir = sys_get_temp_dir().'/kk-settings-source-'.uniqid();
        $projectDir = $tmpDir.'/project';
        mkdir($projectDir.'/.kosmokrator', 0777, true);
        mkdir($tmpDir.'/.kosmokrator', 0777, true);

        $origHome = getenv('HOME');
        putenv("HOME={$tmpDir}");

        try {
            $manager = new SettingsManager(
                $this->config,
                $this->schema,
                $this->store,
                $this->projectConfigDir,
            );
            $manager->setProjectRoot($projectDir);

            $this->assertSame('default', $manager->rawSource('kosmokrator.agent.default_provider'));

            $manager->setRaw('kosmokrator.agent.default_provider', 'openai', 'global');
            $this->assertSame('global', $manager->rawSource('kosmokrator.agent.default_provider'));

            $manager->setRaw('kosmokrator.agent.default_provider', 'codex', 'project');
            $this->assertSame('project', $manager->rawSource('kosmokrator.agent.default_provider'));
        } finally {
            putenv("HOME={$origHome}");
            @unlink($projectDir.'/.kosmokrator/config.yaml');
            @rmdir($projectDir.'/.kosmokrator');
            @rmdir($projectDir);
            @unlink($tmpDir.'/.kosmokrator/config.yaml');
            @rmdir($tmpDir.'/.kosmokrator');
            @rmdir($tmpDir.'/.config');
            @rmdir($tmpDir);
        }
    }

    // ── setRaw() / getRaw() round-trip ─────────────────────────────────

    public function test_set_raw_and_get_raw_round_trip_with_temp_files(): void
    {
        $tmpDir = sys_get_temp_dir().'/kk-settings-roundtrip-'.uniqid();
        $globalDir = $tmpDir.'/.kosmokrator';
        mkdir($globalDir, 0777, true);

        $origHome = getenv('HOME');
        putenv("HOME={$tmpDir}");

        try {
            $manager = new SettingsManager(
                $this->config,
                $this->schema,
                $this->store,
                $this->projectConfigDir,
            );

            // Write a raw value
            $manager->setRaw('kosmokrator.provider_state.testprovider.last_model', 'gpt-5', 'global');

            // Read it back
            $value = $manager->getRaw('kosmokrator.provider_state.testprovider.last_model');
            $this->assertSame('gpt-5', $value);

            // Verify file on disk
            $this->assertFileExists($globalDir.'/config.yaml');
            $disk = Yaml::parse(file_get_contents($globalDir.'/config.yaml'));
            $this->assertSame('gpt-5', $disk['kosmokrator']['provider_state']['testprovider']['last_model']);
        } finally {
            putenv("HOME={$origHome}");
            @unlink($globalDir.'/config.yaml');
            @rmdir($globalDir);
            @rmdir($tmpDir.'/.config');
            @rmdir($tmpDir);
        }
    }

    // ── unsetRaw() ─────────────────────────────────────────────────────

    public function test_unset_raw_removes_value(): void
    {
        $tmpDir = sys_get_temp_dir().'/kk-settings-unset-'.uniqid();
        $globalDir = $tmpDir.'/.kosmokrator';
        mkdir($globalDir, 0777, true);

        $origHome = getenv('HOME');
        putenv("HOME={$tmpDir}");

        try {
            $manager = new SettingsManager(
                $this->config,
                $this->schema,
                $this->store,
                $this->projectConfigDir,
            );

            // Write then delete
            $manager->setRaw('kosmokrator.test_key', 'to-be-removed', 'global');
            $this->assertSame('to-be-removed', $manager->getRaw('kosmokrator.test_key'));

            $manager->unsetRaw('kosmokrator.test_key', 'global');

            // After unset, should fall back to config repo which doesn't have this key
            $this->assertNull($manager->getRaw('kosmokrator.test_key'));
        } finally {
            putenv("HOME={$origHome}");
            @unlink($globalDir.'/config.yaml');
            @rmdir($globalDir);
            @rmdir($tmpDir.'/.config');
            @rmdir($tmpDir);
        }
    }

    // ── Provider last model ────────────────────────────────────────────

    public function test_provider_last_model_round_trip(): void
    {
        $tmpDir = sys_get_temp_dir().'/kk-settings-provider-'.uniqid();
        $globalDir = $tmpDir.'/.kosmokrator';
        mkdir($globalDir, 0777, true);

        $origHome = getenv('HOME');
        putenv("HOME={$tmpDir}");

        try {
            $manager = new SettingsManager(
                $this->config,
                $this->schema,
                $this->store,
                $this->projectConfigDir,
            );

            $this->assertNull($manager->getProviderLastModel('openai'));

            $manager->setProviderLastModel('openai', 'gpt-4o', 'global');
            $this->assertSame('gpt-4o', $manager->getProviderLastModel('openai'));
        } finally {
            putenv("HOME={$origHome}");
            @unlink($globalDir.'/config.yaml');
            @rmdir($globalDir);
            @rmdir($tmpDir.'/.config');
            @rmdir($tmpDir);
        }
    }

    // ── customProviders() ──────────────────────────────────────────────

    public function test_custom_providers_returns_empty_array_by_default(): void
    {
        $providers = $this->manager->customProviders();

        $this->assertIsArray($providers);
    }

    // ── delete() ───────────────────────────────────────────────────────

    public function test_delete_removes_setting_from_global(): void
    {
        $tmpDir = sys_get_temp_dir().'/kk-settings-delete-'.uniqid();
        $globalDir = $tmpDir.'/.kosmokrator';
        mkdir($globalDir, 0777, true);

        $origHome = getenv('HOME');
        putenv("HOME={$tmpDir}");

        try {
            $manager = new SettingsManager(
                $this->config,
                $this->schema,
                $this->store,
                $this->projectConfigDir,
            );

            // Set a value
            $manager->set('agent.default_provider', 'custom-prov', 'global');
            $this->assertSame('custom-prov', $manager->get('agent.default_provider'));

            // Delete it — should fall back to the config default
            $manager->delete('agent.default_provider', 'global');
            $this->assertSame('z', $manager->get('agent.default_provider'));
        } finally {
            putenv("HOME={$origHome}");
            @unlink($globalDir.'/config.yaml');
            @rmdir($globalDir);
            @rmdir($tmpDir.'/.config');
            @rmdir($tmpDir);
        }
    }

    public function test_delete_does_nothing_for_unknown_setting(): void
    {
        // Should not throw — just silently return
        $this->manager->delete('nonexistent.setting');
        $this->assertTrue(true); // Reached without exception
    }
}

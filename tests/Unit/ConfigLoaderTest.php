<?php

namespace Kosmokrator\Tests\Unit;

use Kosmokrator\ConfigLoader;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
    private string $tempDir;

    private string $configDir;

    private string $originalHome;

    private string $originalCwd;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/kosmokrator_config_test_'.uniqid();
        $this->configDir = $this->tempDir.'/config';
        mkdir($this->configDir, 0755, true);

        $this->originalHome = getenv('HOME') ?: '';
        $this->originalCwd = getcwd();
    }

    protected function tearDown(): void
    {
        // Restore environment
        if ($this->originalHome !== '') {
            putenv("HOME={$this->originalHome}");
            $_ENV['HOME'] = $this->originalHome;
        }

        chdir($this->originalCwd);
        $this->removeDir($this->tempDir);
    }

    public function test_loads_yaml_files_from_config_path(): void
    {
        file_put_contents($this->configDir.'/app.yaml', "name: KosmoKrator\nversion: '1.0'");

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertSame('KosmoKrator', $config->get('app.name'));
        $this->assertSame('1.0', $config->get('app.version'));
    }

    public function test_multiple_yaml_files_each_becomes_top_level_key(): void
    {
        file_put_contents($this->configDir.'/app.yaml', 'name: Test');
        file_put_contents($this->configDir.'/db.yaml', 'host: localhost');

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertSame('Test', $config->get('app.name'));
        $this->assertSame('localhost', $config->get('db.host'));
    }

    public function test_environment_variable_interpolation(): void
    {
        $_ENV['KOSMO_TEST_VAR'] = 'resolved_value';
        putenv('KOSMO_TEST_VAR=resolved_value');

        file_put_contents($this->configDir.'/app.yaml', 'key: ${KOSMO_TEST_VAR}');

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertSame('resolved_value', $config->get('app.key'));

        unset($_ENV['KOSMO_TEST_VAR']);
        putenv('KOSMO_TEST_VAR');
    }

    public function test_env_var_fallback_to_empty_string(): void
    {
        file_put_contents($this->configDir.'/app.yaml', 'key: "${KOSMO_NONEXISTENT_VAR_XYZ}"');

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        // YAML parses unquoted empty string as null; quoted preserves empty string
        $this->assertSame('', $config->get('app.key'));
    }

    public function test_user_config_merged(): void
    {
        file_put_contents($this->configDir.'/kosmokrator.yaml', "agent:\n  max_tokens: 1000");

        // Set up fake user config
        $fakeHome = $this->tempDir.'/home';
        mkdir($fakeHome.'/.kosmokrator', 0755, true);
        file_put_contents($fakeHome.'/.kosmokrator/config.yaml', "agent:\n  max_tokens: 5000");
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        // User config overrides base
        $this->assertSame(5000, $config->get('kosmokrator.agent.max_tokens'));
    }

    public function test_project_config_merged(): void
    {
        file_put_contents($this->configDir.'/kosmokrator.yaml', "agent:\n  temperature: 0.0");

        // Set up project config in cwd
        $projectDir = $this->tempDir.'/project';
        mkdir($projectDir, 0755, true);
        file_put_contents($projectDir.'/.kosmokrator.yaml', "agent:\n  temperature: 0.5");
        chdir($projectDir);

        // Prevent user config from interfering
        $fakeHome = $this->tempDir.'/empty_home';
        mkdir($fakeHome, 0755, true);
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertEquals(0.5, $config->get('kosmokrator.agent.temperature'));
    }

    public function test_canonical_user_and_project_paths_are_loaded(): void
    {
        file_put_contents($this->configDir.'/kosmokrator.yaml', "agent:\n  temperature: 0.0");

        $fakeHome = $this->tempDir.'/home_xdg';
        mkdir($fakeHome.'/.config/kosmokrator', 0755, true);
        file_put_contents($fakeHome.'/.config/kosmokrator/config.yaml', "kosmokrator:\n  agent:\n    temperature: 0.4");
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $projectDir = $this->tempDir.'/project_xdg';
        mkdir($projectDir.'/.kosmokrator', 0755, true);
        file_put_contents($projectDir.'/.kosmokrator/config.yaml', "kosmokrator:\n  agent:\n    temperature: 0.8");
        chdir($projectDir);

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertEquals(0.8, $config->get('kosmokrator.agent.temperature'));
    }

    public function test_merge_priority_project_over_user_over_base(): void
    {
        file_put_contents($this->configDir.'/kosmokrator.yaml', "agent:\n  model: base-model");

        // User config
        $fakeHome = $this->tempDir.'/home';
        mkdir($fakeHome.'/.kosmokrator', 0755, true);
        file_put_contents($fakeHome.'/.kosmokrator/config.yaml', "agent:\n  model: user-model");
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        // Project config
        $projectDir = $this->tempDir.'/project';
        mkdir($projectDir, 0755, true);
        file_put_contents($projectDir.'/.kosmokrator.yaml', "agent:\n  model: project-model");
        chdir($projectDir);

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertSame('project-model', $config->get('kosmokrator.agent.model'));
    }

    public function test_deep_merge_preserves_non_overlapping_keys(): void
    {
        file_put_contents($this->configDir.'/kosmokrator.yaml', "agent:\n  model: base\n  max_tokens: 1000");

        $fakeHome = $this->tempDir.'/home';
        mkdir($fakeHome.'/.kosmokrator', 0755, true);
        file_put_contents($fakeHome.'/.kosmokrator/config.yaml', "agent:\n  model: override");
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        // model overridden, max_tokens preserved
        $this->assertSame('override', $config->get('kosmokrator.agent.model'));
        $this->assertSame(1000, $config->get('kosmokrator.agent.max_tokens'));
    }

    public function test_user_config_providers_mapped_to_prism_providers(): void
    {
        file_put_contents($this->configDir.'/prism.yaml', "providers:\n  anthropic:\n    api_key: base");

        $fakeHome = $this->tempDir.'/home';
        mkdir($fakeHome.'/.kosmokrator', 0755, true);
        file_put_contents($fakeHome.'/.kosmokrator/config.yaml', "providers:\n  anthropic:\n    api_key: user-key");
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertSame('user-key', $config->get('prism.providers.anthropic.api_key'));
    }

    public function test_relay_provider_blocks_are_loaded_from_external_config(): void
    {
        $fakeHome = $this->tempDir.'/relay_home';
        mkdir($fakeHome.'/.config/kosmokrator', 0755, true);
        file_put_contents($fakeHome.'/.config/kosmokrator/config.yaml', <<<YAML
relay:
  providers:
    mimo:
      driver: openai-compatible
      url: https://token-plan-sgp.xiaomimimo.com/v1
YAML);
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertSame('openai-compatible', $config->get('relay.providers.mimo.driver'));
        $this->assertSame('https://token-plan-sgp.xiaomimimo.com/v1', $config->get('relay.providers.mimo.url'));
    }

    public function test_no_user_config_file(): void
    {
        file_put_contents($this->configDir.'/app.yaml', 'name: Test');

        $fakeHome = $this->tempDir.'/empty_home';
        mkdir($fakeHome, 0755, true);
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        // Should not error
        $this->assertSame('Test', $config->get('app.name'));
    }

    public function test_no_project_config_file(): void
    {
        file_put_contents($this->configDir.'/app.yaml', 'name: Test');

        $projectDir = $this->tempDir.'/no_project_config';
        mkdir($projectDir, 0755, true);
        chdir($projectDir);

        $fakeHome = $this->tempDir.'/empty_home2';
        mkdir($fakeHome, 0755, true);
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        $this->assertSame('Test', $config->get('app.name'));
    }

    public function test_empty_yaml_file(): void
    {
        file_put_contents($this->configDir.'/empty.yaml', '');

        $fakeHome = $this->tempDir.'/empty_home3';
        mkdir($fakeHome, 0755, true);
        putenv("HOME={$fakeHome}");
        $_ENV['HOME'] = $fakeHome;

        $loader = new ConfigLoader($this->configDir);
        $config = $loader->load();

        // Should not crash, returns empty array for that key
        $this->assertIsArray($config->get('empty'));
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}

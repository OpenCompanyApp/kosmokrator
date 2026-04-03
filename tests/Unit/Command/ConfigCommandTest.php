<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Kosmokrator\Command\ConfigCommand;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigCommandTest extends TestCase
{
    private Container $container;

    private SettingsManager $manager;

    private SettingsSchema $schema;

    private CommandTester $tester;

    /** @var string Temp directory for config file writes during tests */
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kosmokrator_test_config_' . uniqid();
        mkdir($this->tmpDir . '/.kosmokrator', 0777, true);

        $this->schema = new SettingsSchema;
        $store = new YamlConfigStore;
        $config = new Repository;

        // Use the project's config directory as the base config path
        $baseConfigPath = dirname(__DIR__, 3) . '/config';
        $this->manager = new SettingsManager($config, $this->schema, $store, $baseConfigPath);

        $this->container = new Container;
        $manager = $this->manager;
        $schema = $this->schema;

        $this->container->singleton(SettingsManager::class, static fn () => $manager);
        $this->container->singleton(SettingsSchema::class, static fn () => $schema);

        $command = new ConfigCommand($this->container);

        $app = new Application;
        $app->addCommand($command);
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->rmDir($this->tmpDir);
    }

    private function rmDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ── Show action ───────────────────────────────────────────────────

    public function test_show_lists_all_settings(): void
    {
        $exit = $this->tester->execute(['action' => 'show']);

        $this->assertSame(0, $exit);
        $display = $this->tester->getDisplay();
        // Should show the table headers
        $this->assertStringContainsString('Key', $display);
        $this->assertStringContainsString('Value', $display);
        $this->assertStringContainsString('Source', $display);
    }

    public function test_show_with_known_filter_key(): void
    {
        // Use a real setting ID from the schema
        $ids = array_keys($this->schema->definitions());
        $firstId = $ids[0];

        $exit = $this->tester->execute(['action' => 'show', 'key' => $firstId]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString($firstId, $this->tester->getDisplay());
    }

    public function test_show_with_unknown_filter_key_returns_failure(): void
    {
        $exit = $this->tester->execute(['action' => 'show', 'key' => 'nonexistent.setting']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown setting [nonexistent.setting]', $this->tester->getDisplay());
    }

    public function test_show_is_default_action(): void
    {
        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
    }

    // ── Get action ────────────────────────────────────────────────────

    public function test_get_without_key_returns_invalid(): void
    {
        $exit = $this->tester->execute(['action' => 'get']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Provide a setting key', $this->tester->getDisplay());
    }

    public function test_get_with_unknown_key_returns_failure(): void
    {
        $exit = $this->tester->execute(['action' => 'get', 'key' => 'bogus']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown setting [bogus]', $this->tester->getDisplay());
    }

    public function test_get_with_known_key_prints_value(): void
    {
        $ids = array_keys($this->schema->definitions());
        $firstId = $ids[0];
        $effective = $this->manager->resolve($firstId);

        // If the setting has a default, get should succeed
        if ($effective === null) {
            $this->markTestSkipped("No effective value for {$firstId}");
        }

        $exit = $this->tester->execute(['action' => 'get', 'key' => $firstId]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString((string) $effective->value, $this->tester->getDisplay());
    }

    // ── Set action ────────────────────────────────────────────────────

    public function test_set_without_key_returns_invalid(): void
    {
        $exit = $this->tester->execute(['action' => 'set']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Provide a setting key', $this->tester->getDisplay());
    }

    public function test_set_with_unknown_key_returns_failure(): void
    {
        $exit = $this->tester->execute(['action' => 'set', 'key' => 'unknown', 'value' => 'x']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown setting [unknown]', $this->tester->getDisplay());
    }

    // ── Unset action ──────────────────────────────────────────────────

    public function test_unset_without_key_returns_invalid(): void
    {
        $exit = $this->tester->execute(['action' => 'unset']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Provide a setting key', $this->tester->getDisplay());
    }

    public function test_unset_with_unknown_key_returns_failure(): void
    {
        $exit = $this->tester->execute(['action' => 'unset', 'key' => 'unknown']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown setting [unknown]', $this->tester->getDisplay());
    }

    // ── Invalid action ────────────────────────────────────────────────

    public function test_invalid_action_returns_invalid(): void
    {
        $exit = $this->tester->execute(['action' => 'nonsense']);

        $this->assertSame(2, $exit);
    }
}

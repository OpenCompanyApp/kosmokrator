<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Settings;

use Kosmokrator\Settings\YamlConfigStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigStoreTest extends TestCase
{
    private YamlConfigStore $store;

    /** @var list<string> Files/dirs to clean up after each test */
    private array $tmpPaths = [];

    protected function setUp(): void
    {
        $this->store = new YamlConfigStore;
    }

    protected function tearDown(): void
    {
        // Clean up in reverse order so children are removed before parents
        foreach (array_reverse($this->tmpPaths) as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->tmpPaths = [];
    }

    /** Helper: create a temp file path and register it for cleanup. */
    private function tmpFile(string $suffix = '.yaml'): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('yaml_test_').$suffix;
        $this->tmpPaths[] = $path;

        return $path;
    }

    /** Helper: create a temp directory path and register it for cleanup. */
    private function tmpDir(string $prefix = 'yaml_test_dir_'): string
    {
        $path = sys_get_temp_dir().'/'.uniqid($prefix);
        $this->tmpPaths[] = $path;

        return $path;
    }

    // ---------------------------------------------------------------
    // load()
    // ---------------------------------------------------------------

    public function test_load_null_path_returns_empty_array(): void
    {
        $result = $this->store->load(null);

        $this->assertSame([], $result);
    }

    public function test_load_non_existent_file_returns_empty_array(): void
    {
        $result = $this->store->load('/no/such/file.yaml');

        $this->assertSame([], $result);
    }

    public function test_load_valid_yaml_file_returns_parsed_data(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, "kosmo:\n  agent:\n    mode: autonomous\n");

        $result = $this->store->load($path);

        $this->assertSame([
            'kosmo' => [
                'agent' => [
                    'mode' => 'autonomous',
                ],
            ],
        ], $result);
    }

    public function test_load_legacy_kosmokrator_root_normalizes_to_kosmo(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, "kosmokrator:\n  agent:\n    mode: autonomous\n");

        $result = $this->store->load($path);

        $this->assertSame('autonomous', $result['kosmo']['agent']['mode'] ?? null);
        $this->assertArrayNotHasKey('kosmokrator', $result);
    }

    public function test_load_flat_runtime_sections_normalize_under_kosmo(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, "agent:\n  mode: plan\nmcp:\n  permissions_default: deny\n");

        $result = $this->store->load($path);

        $this->assertSame('plan', $result['kosmo']['agent']['mode'] ?? null);
        $this->assertSame('deny', $result['kosmo']['mcp']['permissions_default'] ?? null);
        $this->assertArrayNotHasKey('agent', $result);
        $this->assertArrayNotHasKey('mcp', $result);
    }

    public function test_load_empty_file_returns_empty_array(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, '');

        $result = $this->store->load($path);

        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // save()
    // ---------------------------------------------------------------

    public function test_save_writes_yaml_to_file(): void
    {
        $path = $this->tmpFile();
        $data = ['kosmo' => ['debug' => true]];

        $this->store->save($path, $data);

        $this->assertFileExists($path);
        $this->assertSame($data, Yaml::parse(file_get_contents($path)));
    }

    public function test_save_empty_array_deletes_file(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, "key: value\n");

        $this->assertFileExists($path);

        $this->store->save($path, []);

        $this->assertFileDoesNotExist($path);
    }

    public function test_save_creates_parent_directory(): void
    {
        $dir = $this->tmpDir();
        $path = $dir.'/nested/config.yaml';
        $this->tmpPaths[] = $dir.'/nested';
        $this->tmpPaths[] = $dir;

        $data = ['setting' => 'value'];

        $this->store->save($path, $data);

        $this->assertFileExists($path);
        $this->assertSame($data, Yaml::parse(file_get_contents($path)));
    }

    // ---------------------------------------------------------------
    // get()
    // ---------------------------------------------------------------

    public function test_get_retrieves_nested_value_by_dot_path(): void
    {
        $data = [
            'kosmo' => [
                'agent' => [
                    'mode' => 'autonomous',
                ],
            ],
        ];

        $this->assertSame('autonomous', $this->store->get($data, 'kosmo.agent.mode'));
    }

    public function test_get_returns_null_for_missing_path(): void
    {
        $data = ['kosmo' => ['agent' => ['mode' => 'autonomous']]];

        $this->assertNull($this->store->get($data, 'kosmo.agent.nonexistent'));
        $this->assertNull($this->store->get($data, 'missing.entirely'));
    }

    // ---------------------------------------------------------------
    // set()
    // ---------------------------------------------------------------

    public function test_set_creates_nested_structure(): void
    {
        $data = [];

        $this->store->set($data, 'kosmo.agent.mode', 'autonomous');

        $this->assertSame([
            'kosmo' => [
                'agent' => [
                    'mode' => 'autonomous',
                ],
            ],
        ], $data);
    }

    public function test_set_overwrites_existing_value(): void
    {
        $data = [
            'kosmo' => [
                'agent' => [
                    'mode' => 'manual',
                ],
            ],
        ];

        $this->store->set($data, 'kosmo.agent.mode', 'autonomous');

        $this->assertSame('autonomous', $data['kosmo']['agent']['mode']);
    }

    public function test_set_rejects_non_array_intermediate_values(): void
    {
        $data = [
            'kosmo' => [
                'agent' => 'manual',
            ],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot set nested config path kosmo.agent.mode: kosmo.agent is not a map.');

        try {
            $this->store->set($data, 'kosmo.agent.mode', 'autonomous');
        } finally {
            $this->assertSame(['kosmo' => ['agent' => 'manual']], $data);
        }
    }

    // ---------------------------------------------------------------
    // unset()
    // ---------------------------------------------------------------

    public function test_unset_removes_value_and_cleans_up_empty_parents(): void
    {
        $data = [
            'kosmo' => [
                'agent' => [
                    'mode' => 'autonomous',
                ],
            ],
        ];

        $this->store->unset($data, 'kosmo.agent.mode');

        $this->assertSame([], $data);
    }

    public function test_unset_non_existent_path_does_nothing(): void
    {
        $data = ['kosmo' => ['agent' => ['mode' => 'autonomous']]];

        $this->store->unset($data, 'kosmo.agent.nonexistent');

        $this->assertSame(['kosmo' => ['agent' => ['mode' => 'autonomous']]], $data);
    }
}

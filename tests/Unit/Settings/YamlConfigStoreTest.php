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
        $this->store = new YamlConfigStore();
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
        $path = sys_get_temp_dir() . '/' . uniqid('yaml_test_') . $suffix;
        $this->tmpPaths[] = $path;

        return $path;
    }

    /** Helper: create a temp directory path and register it for cleanup. */
    private function tmpDir(string $prefix = 'yaml_test_dir_'): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid($prefix);
        $this->tmpPaths[] = $path;

        return $path;
    }

    // ---------------------------------------------------------------
    // load()
    // ---------------------------------------------------------------

    public function testLoadNullPathReturnsEmptyArray(): void
    {
        $result = $this->store->load(null);

        $this->assertSame([], $result);
    }

    public function testLoadNonExistentFileReturnsEmptyArray(): void
    {
        $result = $this->store->load('/no/such/file.yaml');

        $this->assertSame([], $result);
    }

    public function testLoadValidYamlFileReturnsParsedData(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, "kosmokrator:\n  agent:\n    mode: autonomous\n");

        $result = $this->store->load($path);

        $this->assertSame([
            'kosmokrator' => [
                'agent' => [
                    'mode' => 'autonomous',
                ],
            ],
        ], $result);
    }

    public function testLoadEmptyFileReturnsEmptyArray(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, '');

        $result = $this->store->load($path);

        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // save()
    // ---------------------------------------------------------------

    public function testSaveWritesYamlToFile(): void
    {
        $path = $this->tmpFile();
        $data = ['kosmokrator' => ['debug' => true]];

        $this->store->save($path, $data);

        $this->assertFileExists($path);
        $this->assertSame($data, Yaml::parse(file_get_contents($path)));
    }

    public function testSaveEmptyArrayDeletesFile(): void
    {
        $path = $this->tmpFile();
        file_put_contents($path, "key: value\n");

        $this->assertFileExists($path);

        $this->store->save($path, []);

        $this->assertFileDoesNotExist($path);
    }

    public function testSaveCreatesParentDirectory(): void
    {
        $dir = $this->tmpDir();
        $path = $dir . '/nested/config.yaml';
        $this->tmpPaths[] = $dir . '/nested';
        $this->tmpPaths[] = $dir;

        $data = ['setting' => 'value'];

        $this->store->save($path, $data);

        $this->assertFileExists($path);
        $this->assertSame($data, Yaml::parse(file_get_contents($path)));
    }

    // ---------------------------------------------------------------
    // get()
    // ---------------------------------------------------------------

    public function testGetRetrievesNestedValueByDotPath(): void
    {
        $data = [
            'kosmokrator' => [
                'agent' => [
                    'mode' => 'autonomous',
                ],
            ],
        ];

        $this->assertSame('autonomous', $this->store->get($data, 'kosmokrator.agent.mode'));
    }

    public function testGetReturnsNullForMissingPath(): void
    {
        $data = ['kosmokrator' => ['agent' => ['mode' => 'autonomous']]];

        $this->assertNull($this->store->get($data, 'kosmokrator.agent.nonexistent'));
        $this->assertNull($this->store->get($data, 'missing.entirely'));
    }

    // ---------------------------------------------------------------
    // set()
    // ---------------------------------------------------------------

    public function testSetCreatesNestedStructure(): void
    {
        $data = [];

        $this->store->set($data, 'kosmokrator.agent.mode', 'autonomous');

        $this->assertSame([
            'kosmokrator' => [
                'agent' => [
                    'mode' => 'autonomous',
                ],
            ],
        ], $data);
    }

    public function testSetOverwritesExistingValue(): void
    {
        $data = [
            'kosmokrator' => [
                'agent' => [
                    'mode' => 'manual',
                ],
            ],
        ];

        $this->store->set($data, 'kosmokrator.agent.mode', 'autonomous');

        $this->assertSame('autonomous', $data['kosmokrator']['agent']['mode']);
    }

    // ---------------------------------------------------------------
    // unset()
    // ---------------------------------------------------------------

    public function testUnsetRemovesValueAndCleansUpEmptyParents(): void
    {
        $data = [
            'kosmokrator' => [
                'agent' => [
                    'mode' => 'autonomous',
                ],
            ],
        ];

        $this->store->unset($data, 'kosmokrator.agent.mode');

        $this->assertSame([], $data);
    }

    public function testUnsetNonExistentPathDoesNothing(): void
    {
        $data = ['kosmokrator' => ['agent' => ['mode' => 'autonomous']]];

        $this->store->unset($data, 'kosmokrator.agent.nonexistent');

        $this->assertSame(['kosmokrator' => ['agent' => ['mode' => 'autonomous']]], $data);
    }
}

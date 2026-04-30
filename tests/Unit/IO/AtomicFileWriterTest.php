<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\IO;

use Kosmokrator\IO\AtomicFileWriter;
use PHPUnit\Framework\TestCase;

final class AtomicFileWriterTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
    }

    public function test_writes_file_and_creates_parent_directory(): void
    {
        $dir = sys_get_temp_dir().'/kosmo-atomic-'.bin2hex(random_bytes(4));
        $path = $dir.'/nested/config.json';
        $this->paths = [$dir, $dir.'/nested', $path];

        AtomicFileWriter::write($path, '{"ok":true}', 0700);

        $this->assertSame('{"ok":true}', file_get_contents($path));
        $this->assertDirectoryExists(dirname($path));
    }

    public function test_overwrites_existing_file_atomically(): void
    {
        $dir = sys_get_temp_dir().'/kosmo-atomic-'.bin2hex(random_bytes(4));
        $path = $dir.'/config.yaml';
        $this->paths = [$dir, $path];
        mkdir($dir, 0700, true);
        file_put_contents($path, 'old');

        AtomicFileWriter::write($path, 'new', 0700);

        $this->assertSame('new', file_get_contents($path));
    }
}

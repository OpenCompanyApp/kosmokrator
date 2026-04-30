<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\PathResolver;
use PHPUnit\Framework\TestCase;

class PathResolverTest extends TestCase
{
    public function test_empty_string_returns_null(): void
    {
        $this->assertNull(PathResolver::resolve(''));
    }

    public function test_dot_returns_null(): void
    {
        $this->assertNull(PathResolver::resolve('.'));
    }

    public function test_existing_file_returns_realpath(): void
    {
        $result = PathResolver::resolve(__FILE__);

        $this->assertSame(realpath(__FILE__), $result);
    }

    public function test_existing_directory_returns_realpath(): void
    {
        $result = PathResolver::resolve(__DIR__);

        $this->assertSame(realpath(__DIR__), $result);
    }

    public function test_absolute_path_to_existing_directory(): void
    {
        $result = PathResolver::resolve('/tmp');

        $this->assertSame(realpath('/tmp'), $result);
    }

    public function test_non_existent_file_in_existing_dir_resolves(): void
    {
        $path = __DIR__.'/this-file-does-not-exist.txt';
        $result = PathResolver::resolve($path);

        $this->assertNotNull($result);
        $this->assertSame(realpath(__DIR__).'/this-file-does-not-exist.txt', $result);
    }

    public function test_non_existent_file_in_non_existent_dir_returns_null(): void
    {
        $path = '/this/dir/does/not/exist/file.txt';
        $result = PathResolver::resolve($path);

        $this->assertNull($result);
    }

    public function test_relative_path_resolution(): void
    {
        $result = PathResolver::resolve('src');

        $this->assertNotNull($result);
        $this->assertSame(realpath('src'), $result);
    }

    public function test_detects_symlink_component(): void
    {
        $tmpDir = sys_get_temp_dir().'/path_resolver_'.uniqid();
        $target = $tmpDir.'/target';
        $link = $tmpDir.'/link';

        try {
            mkdir($target, 0755, true);
            symlink($target, $link);

            $this->assertTrue(PathResolver::containsSymlinkComponent($link.'/new-file.txt'));
            $this->assertNull(PathResolver::resolveForMutation($link.'/new-file.txt'));
        } finally {
            @unlink($link);
            @rmdir($target);
            @rmdir($tmpDir);
        }
    }

    public function test_resolves_via_existing_ancestor(): void
    {
        $path = __DIR__.'/missing/deep/file.txt';

        $this->assertSame(
            realpath(__DIR__).'/missing/deep/file.txt',
            PathResolver::resolveViaExistingAncestor($path),
        );
    }
}

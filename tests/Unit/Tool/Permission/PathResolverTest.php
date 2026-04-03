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
}

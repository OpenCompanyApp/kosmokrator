<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\PathValidator;
use PHPUnit\Framework\TestCase;

final class PathValidatorTest extends TestCase
{
    private string $projectRoot;

    private string $siblingRoot;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/path_validator_'.uniqid();
        $this->projectRoot = $base.'/project';
        $this->siblingRoot = $base.'/project-secret';
        mkdir($this->projectRoot, 0755, true);
        mkdir($this->siblingRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir(dirname($this->projectRoot));
    }

    public function test_rejects_sibling_path_with_same_prefix_as_project_root(): void
    {
        $outsideFile = $this->siblingRoot.'/secret.txt';
        file_put_contents($outsideFile, 'secret');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Path escapes project root');

        PathValidator::resolveAndValidatePath($outsideFile, $this->projectRoot);
    }

    public function test_allows_path_inside_project_root(): void
    {
        $insideFile = $this->projectRoot.'/src/file.txt';
        mkdir(dirname($insideFile), 0755, true);
        file_put_contents($insideFile, 'ok');

        self::assertSame(
            realpath($insideFile),
            PathValidator::resolveAndValidatePath($insideFile, $this->projectRoot),
        );
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
            $item->isDir() && ! $item->isLink()
                ? rmdir($item->getRealPath())
                : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}

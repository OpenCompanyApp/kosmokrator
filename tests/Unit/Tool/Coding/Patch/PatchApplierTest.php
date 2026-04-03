<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding\Patch;

use Kosmokrator\Tool\Coding\Patch\PatchApplier;
use Kosmokrator\Tool\Coding\Patch\PatchOperation;
use PHPUnit\Framework\TestCase;

final class PatchApplierTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/patch_applier_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Recursive delete of temp directory
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->tmpDir);
    }

    private function path(string $relative): string
    {
        return $this->tmpDir . '/' . $relative;
    }

    // --- Add operation ---

    public function testAddCreatesNewFile(): void
    {
        $path = $this->path('new.txt');
        $applier = new PatchApplier();
        $result = $applier->apply([
            new PatchOperation('add', $path, ['Hello, world!']),
        ]);

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame(0, $result['moved']);
        $this->assertFileExists($path);
        $this->assertSame('Hello, world!', file_get_contents($path));
    }

    public function testAddFailsIfFileAlreadyExists(): void
    {
        $path = $this->path('existing.txt');
        file_put_contents($path, 'old');

        $applier = new PatchApplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $applier->apply([
            new PatchOperation('add', $path, ['new content']),
        ]);
    }

    public function testAddCreatesParentDirectories(): void
    {
        $path = $this->path('deep/nested/dir/file.txt');
        $applier = new PatchApplier();
        $applier->apply([
            new PatchOperation('add', $path, ['deep content']),
        ]);

        $this->assertFileExists($path);
        $this->assertSame('deep content', file_get_contents($path));
    }

    // --- Update operation ---

    public function testUpdateAppliesHunksToExistingFile(): void
    {
        $path = $this->path('edit.txt');
        file_put_contents($path, "line1\nline2\nline3\n");

        $applier = new PatchApplier();
        $result = $applier->apply([
            new PatchOperation('update', $path, [
                ' line1',
                '-line2',
                '+line2-updated',
                ' line3',
            ]),
        ]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame("line1\nline2-updated\nline3\n", file_get_contents($path));
    }

    public function testUpdateFailsForNonExistentFile(): void
    {
        $path = $this->path('missing.txt');
        $applier = new PatchApplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing file');

        $applier->apply([
            new PatchOperation('update', $path, [
                ' some line',
            ]),
        ]);
    }

    public function testUpdateWithMoveToRenamesFile(): void
    {
        $srcPath = $this->path('original.txt');
        $dstPath = $this->path('renamed.txt');
        file_put_contents($srcPath, "content\n");

        $applier = new PatchApplier();
        $result = $applier->apply([
            new PatchOperation('update', $srcPath, [
                '-content',
                '+updated-content',
            ], $dstPath),
        ]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['moved']);
        $this->assertFileDoesNotExist($srcPath);
        $this->assertFileExists($dstPath);
        $this->assertSame("updated-content\n", file_get_contents($dstPath));
    }

    public function testUpdateWithMoveToFailsIfDestinationExists(): void
    {
        $srcPath = $this->path('src.txt');
        $dstPath = $this->path('dst.txt');
        file_put_contents($srcPath, "source\n");
        file_put_contents($dstPath, 'destination');

        $applier = new PatchApplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $applier->apply([
            new PatchOperation('update', $srcPath, [], $dstPath),
        ]);
    }

    // --- Delete operation ---

    public function testDeleteRemovesFile(): void
    {
        $path = $this->path('doomed.txt');
        file_put_contents($path, 'bye');

        $applier = new PatchApplier();
        $result = $applier->apply([
            new PatchOperation('delete', $path),
        ]);

        $this->assertSame(1, $result['deleted']);
        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteFailsForNonExistentFile(): void
    {
        $path = $this->path('ghost.txt');
        $applier = new PatchApplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing file');

        $applier->apply([
            new PatchOperation('delete', $path),
        ]);
    }

    public function testDeleteFailsForDirectories(): void
    {
        $path = $this->path('a-dir');
        mkdir($path, 0755);

        $applier = new PatchApplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete directory');

        $applier->apply([
            new PatchOperation('delete', $path),
        ]);
    }

    // --- Blocked paths ---

    public function testBlockedPathsAreRejected(): void
    {
        $path = $this->path('secret.key');
        file_put_contents($path, 'secret');

        $applier = new PatchApplier(['*.key']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked pattern');

        $applier->apply([
            new PatchOperation('delete', $path),
        ]);
    }

    // --- Invalid paths ---

    public function testEmptyPathIsRejected(): void
    {
        $applier = new PatchApplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid file path');

        $applier->apply([
            new PatchOperation('add', '', ['content']),
        ]);
    }

    public function testDotPathIsRejected(): void
    {
        $applier = new PatchApplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid file path');

        $applier->apply([
            new PatchOperation('add', '.', ['content']),
        ]);
    }

    // --- Summary counts ---

    public function testSummaryCountsAreCorrectForMixedOperations(): void
    {
        $addPath = $this->path('added.txt');
        $updatePath = $this->path('updated.txt');
        $deletePath = $this->path('deleted.txt');

        file_put_contents($updatePath, "original\n");
        file_put_contents($deletePath, 'bye');

        $applier = new PatchApplier();
        $result = $applier->apply([
            new PatchOperation('add', $addPath, ['new file']),
            new PatchOperation('update', $updatePath, [
                '-original',
                '+changed',
            ]),
            new PatchOperation('delete', $deletePath),
        ]);

        $this->assertSame(['added' => 1, 'updated' => 1, 'deleted' => 1, 'moved' => 0], $result);
    }

    // --- Unsupported operation kind ---

    public function testUnsupportedOperationKindThrows(): void
    {
        $applier = new PatchApplier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unsupported patch operation 'copy'");

        $applier->apply([
            new PatchOperation('copy', $this->path('any.txt'), ['data']),
        ]);
    }
}

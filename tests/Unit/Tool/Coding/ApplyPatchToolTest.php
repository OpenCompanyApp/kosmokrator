<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\ApplyPatchTool;
use Kosmokrator\Tool\Coding\Patch\PatchApplier;
use Kosmokrator\Tool\Coding\Patch\PatchParser;
use PHPUnit\Framework\TestCase;

class ApplyPatchToolTest extends TestCase
{
    private string $tempDir;

    private ApplyPatchTool $tool;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/kosmokrator_patch_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->tool = new ApplyPatchTool(new PatchParser, new PatchApplier([], $this->tempDir));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_applies_update_and_add_operations(): void
    {
        $existing = $this->tempDir.'/existing.txt';
        file_put_contents($existing, "before\nmiddle\nafter");

        $newFile = $this->tempDir.'/new.txt';

        $result = $this->tool->execute([
            'patch' => <<<PATCH
*** Begin Patch
*** Update File: {$existing}
@@
-middle
+patched
*** Add File: {$newFile}
+hello
+world
*** End Patch
PATCH,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame("before\npatched\nafter", file_get_contents($existing));
        $this->assertSame("hello\nworld", file_get_contents($newFile));
    }

    public function test_applies_move_and_delete_operations(): void
    {
        $oldFile = $this->tempDir.'/old.txt';
        $deleteFile = $this->tempDir.'/delete.txt';
        file_put_contents($oldFile, 'before');
        file_put_contents($deleteFile, 'trash');

        $movedFile = $this->tempDir.'/moved.txt';

        $result = $this->tool->execute([
            'patch' => <<<PATCH
*** Begin Patch
*** Update File: {$oldFile}
*** Move to: {$movedFile}
@@
-before
+after
*** Delete File: {$deleteFile}
*** End Patch
PATCH,
        ]);

        $this->assertTrue($result->success);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertSame('after', file_get_contents($movedFile));
        $this->assertFileDoesNotExist($deleteFile);
    }

    public function test_rejects_blocked_paths(): void
    {
        $tool = new ApplyPatchTool(new PatchParser, new PatchApplier(['*.env'], $this->tempDir));

        $result = $tool->execute([
            'patch' => <<<PATCH
*** Begin Patch
*** Add File: {$this->tempDir}/.env
+APP_KEY=secret
*** End Patch
PATCH,
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('blocked pattern', $result->output);
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

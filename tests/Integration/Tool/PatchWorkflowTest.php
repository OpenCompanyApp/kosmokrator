<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\Tool;

use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\Tool\Coding\ApplyPatchTool;
use Kosmokrator\Tool\Coding\Patch\PatchApplier;
use Kosmokrator\Tool\Coding\Patch\PatchParser;

/**
 * Integration tests for multi-file patch workflows.
 * Uses ApplyPatchTool against real files in an isolated temp directory.
 */
class PatchWorkflowTest extends IntegrationTestCase
{
    private PatchParser $parser;

    private PatchApplier $applier;

    private ApplyPatchTool $patchTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PatchParser;
        $this->applier = new PatchApplier;
        $this->patchTool = new ApplyPatchTool($this->parser, $this->applier);
    }

    public function test_patch_updates_single_file(): void
    {
        $this->createFile('hello.txt', "Hello World\nSecond line");

        $result = $this->patchTool->execute([
            'patch' => implode("\n", [
                '*** Begin Patch',
                '*** Update File: '.$this->tmpDir.'/hello.txt',
                '-Hello World',
                '+Hello Universe',
                '*** End Patch',
            ]),
        ]);

        $this->assertTrue($result->success, 'Patch should succeed: '.$result->output);
        $this->assertSame("Hello Universe\nSecond line", $this->readFile('hello.txt'));
    }

    public function test_patch_adds_new_file(): void
    {
        $result = $this->patchTool->execute([
            'patch' => implode("\n", [
                '*** Begin Patch',
                '*** Add File: '.$this->tmpDir.'/new.txt',
                '+brand new content',
                '*** End Patch',
            ]),
        ]);

        $this->assertTrue($result->success, 'Add should succeed: '.$result->output);
        $this->assertFileExistsInTmp('new.txt');
        $this->assertSame('brand new content', $this->readFile('new.txt'));
    }

    public function test_patch_deletes_file(): void
    {
        $this->createFile('to-delete.txt', 'will be removed');

        $result = $this->patchTool->execute([
            'patch' => implode("\n", [
                '*** Begin Patch',
                '*** Delete File: '.$this->tmpDir.'/to-delete.txt',
                '*** End Patch',
            ]),
        ]);

        $this->assertTrue($result->success, 'Delete should succeed: '.$result->output);
        $this->assertFileDoesNotExist($this->tmpDir.'/to-delete.txt');
    }

    public function test_multi_file_patch(): void
    {
        $this->createFile('file1.txt', "original line 1\noriginal line 2");
        $this->createFile('file2.txt', "keep this\nchange this");

        $result = $this->patchTool->execute([
            'patch' => implode("\n", [
                '*** Begin Patch',
                '*** Update File: '.$this->tmpDir.'/file1.txt',
                '-original line 1',
                '+updated line 1',
                '*** Add File: '.$this->tmpDir.'/file3.txt',
                '+new file content',
                '*** Update File: '.$this->tmpDir.'/file2.txt',
                '-change this',
                '+changed that',
                '*** End Patch',
            ]),
        ]);

        $this->assertTrue($result->success, 'Multi-file patch should succeed: '.$result->output);

        $this->assertSame("updated line 1\noriginal line 2", $this->readFile('file1.txt'));
        $this->assertSame("keep this\nchanged that", $this->readFile('file2.txt'));
        $this->assertFileExistsInTmp('file3.txt');
        $this->assertSame('new file content', $this->readFile('file3.txt'));
    }

    public function test_patch_creates_parent_directories(): void
    {
        $result = $this->patchTool->execute([
            'patch' => implode("\n", [
                '*** Begin Patch',
                '*** Add File: '.$this->tmpDir.'/deep/nested/path/file.txt',
                '+deeply nested content',
                '*** End Patch',
            ]),
        ]);

        $this->assertTrue($result->success, 'Nested add should succeed: '.$result->output);
        $this->assertFileExistsInTmp('deep/nested/path/file.txt');
        $this->assertSame('deeply nested content', $this->readFile('deep/nested/path/file.txt'));
    }

    public function test_invalid_patch_returns_error(): void
    {
        $result = $this->patchTool->execute([
            'patch' => 'this is not a valid patch',
        ]);

        $this->assertFalse($result->success);
    }

    public function test_patch_report_counts_operations(): void
    {
        $this->createFile('update.txt', 'old');

        $result = $this->patchTool->execute([
            'patch' => implode("\n", [
                '*** Begin Patch',
                '*** Add File: '.$this->tmpDir.'/added.txt',
                '+new',
                '*** Update File: '.$this->tmpDir.'/update.txt',
                '-old',
                '+new',
                '*** End Patch',
            ]),
        ]);

        $this->assertTrue($result->success, 'Patch should succeed: '.$result->output);
        $this->assertStringContainsString('added', $result->output);
        $this->assertStringContainsString('updated', $result->output);
    }
}

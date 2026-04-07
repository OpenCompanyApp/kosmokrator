<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\Tool;

use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\Tool\Coding\FileEditTool;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;

/**
 * Integration tests for file tool workflows: write → read → edit → verify.
 * Uses real filesystem operations against an isolated temp directory.
 */
class FileWorkflowTest extends IntegrationTestCase
{
    private FileWriteTool $writeTool;

    private FileReadTool $readTool;

    private FileEditTool $editTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = new FileWriteTool($this->tmpDir);
        $this->readTool = new FileReadTool($this->tmpDir);
        $this->editTool = new FileEditTool($this->tmpDir);
    }

    public function test_write_then_read_round_trip(): void
    {
        $content = "Hello World\nLine 2\nLine 3";
        $writeResult = $this->writeTool->execute([
            'path' => $this->tmpDir.'/test.txt',
            'content' => $content,
        ]);

        $this->assertTrue($writeResult->success);

        $readResult = $this->readTool->execute([
            'path' => $this->tmpDir.'/test.txt',
        ]);

        $this->assertTrue($readResult->success);
        $this->assertStringContainsString('Hello World', $readResult->output);
        $this->assertStringContainsString('Line 2', $readResult->output);
        $this->assertStringContainsString('Line 3', $readResult->output);
    }

    public function test_write_creates_subdirectories(): void
    {
        $result = $this->writeTool->execute([
            'path' => $this->tmpDir.'/deep/nested/dir/file.txt',
            'content' => 'nested content',
        ]);

        $this->assertTrue($result->success);
        $this->assertFileExistsInTmp('deep/nested/dir/file.txt');
        $this->assertSame('nested content', $this->readFile('deep/nested/dir/file.txt'));
    }

    public function test_write_overwrites_existing_file(): void
    {
        $this->createFile('overwrite.txt', 'original');

        $result = $this->writeTool->execute([
            'path' => $this->tmpDir.'/overwrite.txt',
            'content' => 'updated',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('updated', $this->readFile('overwrite.txt'));
    }

    public function test_write_then_edit_then_read(): void
    {
        $this->createFile('edit.txt', "Hello World\nSecond line\nThird line");

        $editResult = $this->editTool->execute([
            'path' => $this->tmpDir.'/edit.txt',
            'old_string' => 'Hello World',
            'new_string' => 'Goodbye World',
        ]);

        $this->assertTrue($editResult->success);

        $content = $this->readFile('edit.txt');
        $this->assertStringContainsString('Goodbye World', $content);
        $this->assertStringNotContainsString('Hello World', $content);
        $this->assertStringContainsString('Second line', $content);
        $this->assertStringContainsString('Third line', $content);
    }

    public function test_multiple_edits_on_same_file(): void
    {
        $this->createFile('multi.txt', "apple\nbanana\ncherry");

        $result1 = $this->editTool->execute([
            'path' => $this->tmpDir.'/multi.txt',
            'old_string' => 'apple',
            'new_string' => 'avocado',
        ]);
        $this->assertTrue($result1->success);

        $result2 = $this->editTool->execute([
            'path' => $this->tmpDir.'/multi.txt',
            'old_string' => 'cherry',
            'new_string' => 'citrus',
        ]);
        $this->assertTrue($result2->success);

        $content = $this->readFile('multi.txt');
        $this->assertSame("avocado\nbanana\ncitrus", $content);
    }

    public function test_edit_reports_line_changes(): void
    {
        $this->createFile('lines.txt', "line 1\nline 2\nline 3");

        $result = $this->editTool->execute([
            'path' => $this->tmpDir.'/lines.txt',
            'old_string' => "line 1\nline 2",
            'new_string' => 'replaced',
        ]);

        $this->assertTrue($result->success);
        // The edit replaced 2 lines with 1 line — report shows the diff
        $this->assertMatchesRegularExpression('/-[12]/', $result->output);
    }

    public function test_read_with_offset_and_limit(): void
    {
        $this->createFile('numbered.txt', "line 1\nline 2\nline 3\nline 4\nline 5");

        $result = $this->readTool->execute([
            'path' => $this->tmpDir.'/numbered.txt',
            'offset' => 2,
            'limit' => 2,
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('line 2', $result->output);
        $this->assertStringContainsString('line 3', $result->output);
        // Should NOT contain lines outside the range
        $this->assertStringNotContainsString('line 1', $result->output);
        $this->assertStringNotContainsString('line 5', $result->output);
    }

    public function test_read_nonexistent_file_returns_error(): void
    {
        $result = $this->readTool->execute([
            'path' => $this->tmpDir.'/nonexistent.txt',
        ]);

        $this->assertFalse($result->success);
    }

    public function test_edit_nonexistent_string_returns_error(): void
    {
        $this->createFile('exists.txt', 'content');

        $result = $this->editTool->execute([
            'path' => $this->tmpDir.'/exists.txt',
            'old_string' => 'not found',
            'new_string' => 'replacement',
        ]);

        $this->assertFalse($result->success);
        // Original content should be unchanged
        $this->assertSame('content', $this->readFile('exists.txt'));
    }

    public function test_write_then_read_multiple_files(): void
    {
        $files = [
            'a.txt' => 'Content A',
            'b.php' => '<?php echo "hello";',
            'subdir/c.json' => '{"key": "value"}',
        ];

        foreach ($files as $path => $content) {
            $this->createFile($path, $content);
        }

        foreach ($files as $path => $expected) {
            $result = $this->readTool->execute([
                'path' => $this->tmpDir.'/'.$path,
            ]);
            $this->assertTrue($result->success, "Failed to read {$path}");
            $this->assertStringContainsString($expected, $result->output, "Wrong content in {$path}");
        }
    }
}

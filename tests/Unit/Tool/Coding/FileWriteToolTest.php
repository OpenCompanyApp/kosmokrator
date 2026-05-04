<?php

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\FileWriteTool;
use PHPUnit\Framework\TestCase;

class FileWriteToolTest extends TestCase
{
    private FileWriteTool $tool;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/kosmokrator_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->tool = new FileWriteTool($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_name_returns_file_write(): void
    {
        $this->assertSame('file_write', $this->tool->name());
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['path', 'content'], $this->tool->requiredParameters());
    }

    public function test_writes_content_to_new_file(): void
    {
        $path = $this->tempDir.'/new.txt';
        $result = $this->tool->execute(['path' => $path, 'content' => 'hello world']);

        $this->assertTrue($result->success);
        $this->assertFileExists($path);
        $this->assertSame('hello world', file_get_contents($path));
    }

    public function test_creates_parent_directories(): void
    {
        $path = $this->tempDir.'/a/b/c/deep.txt';
        $result = $this->tool->execute(['path' => $path, 'content' => 'deep content']);

        $this->assertTrue($result->success);
        $this->assertDirectoryExists($this->tempDir.'/a/b/c');
        $this->assertSame('deep content', file_get_contents($path));
    }

    public function test_overwrites_existing_file(): void
    {
        $path = $this->tempDir.'/existing.txt';
        file_put_contents($path, 'old content');

        $this->tool->execute(['path' => $path, 'content' => 'new content']);

        $this->assertSame('new content', file_get_contents($path));
    }

    public function test_reports_correct_line_count_single_line(): void
    {
        $path = $this->tempDir.'/single.txt';
        $result = $this->tool->execute(['path' => $path, 'content' => 'hello']);

        $this->assertStringContainsString('Wrote 1 lines', $result->output);
    }

    public function test_reports_correct_line_count_multi_line(): void
    {
        $path = $this->tempDir.'/multi.txt';
        $result = $this->tool->execute(['path' => $path, 'content' => "a\nb\nc"]);

        $this->assertStringContainsString('Wrote 3 lines', $result->output);
    }

    public function test_reports_correct_line_count_trailing_newline(): void
    {
        $path = $this->tempDir.'/trailing.txt';
        $result = $this->tool->execute(['path' => $path, 'content' => "a\nb\n"]);

        $this->assertStringContainsString('Wrote 3 lines', $result->output);
    }

    public function test_empty_content_writes_empty_file(): void
    {
        $path = $this->tempDir.'/empty.txt';
        $result = $this->tool->execute(['path' => $path, 'content' => '']);

        $this->assertTrue($result->success);
        $this->assertFileExists($path);
        $this->assertSame('', file_get_contents($path));
        $this->assertStringContainsString('Wrote 1 lines', $result->output);
    }

    public function test_rejects_write_through_symlink_directory(): void
    {
        $target = $this->tempDir.'/target';
        $link = $this->tempDir.'/link';
        mkdir($target);
        symlink($target, $link);

        $result = $this->tool->execute(['path' => $link.'/file.txt', 'content' => 'x']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('symlink component', $result->output);
        $this->assertFileDoesNotExist($target.'/file.txt');
    }

    public function test_rejects_overwrite_of_symlink_file(): void
    {
        $target = $this->tempDir.'/target.txt';
        $link = $this->tempDir.'/link.txt';
        file_put_contents($target, 'target');
        symlink($target, $link);

        $result = $this->tool->execute(['path' => $link, 'content' => 'new']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('symlink component', $result->output);
        $this->assertSame('target', file_get_contents($target));
    }

    public function test_output_includes_path(): void
    {
        $path = $this->tempDir.'/output.txt';
        $result = $this->tool->execute(['path' => $path, 'content' => 'x']);

        $this->assertStringContainsString($path, $result->output);
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
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}

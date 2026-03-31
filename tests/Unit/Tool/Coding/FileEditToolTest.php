<?php

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\FileEditTool;
use PHPUnit\Framework\TestCase;

class FileEditToolTest extends TestCase
{
    private FileEditTool $tool;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new FileEditTool;
        $this->tempDir = sys_get_temp_dir().'/kosmokrator_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_name_returns_file_edit(): void
    {
        $this->assertSame('file_edit', $this->tool->name());
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['path', 'old_string', 'new_string'], $this->tool->requiredParameters());
    }

    public function test_replaces_unique_string(): void
    {
        $path = $this->createFile('Hello foo world');
        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => 'foo',
            'new_string' => 'bar',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Hello bar world', file_get_contents($path));
    }

    public function test_error_when_file_not_found(): void
    {
        $result = $this->tool->execute([
            'path' => $this->tempDir.'/nope.txt',
            'old_string' => 'a',
            'new_string' => 'b',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('File not found', $result->output);
    }

    public function test_error_when_old_string_not_found(): void
    {
        $path = $this->createFile('Hello world');
        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => 'nonexistent',
            'new_string' => 'replacement',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_error_when_old_string_found_multiple_times(): void
    {
        $path = $this->createFile('foo bar foo baz foo');
        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => 'foo',
            'new_string' => 'qux',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('multiple times', $result->output);
    }

    public function test_reports_removed_and_added_line_counts(): void
    {
        $path = $this->createFile("before\na\nb\nafter");
        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => "a\nb",
            'new_string' => "x\ny\nz",
        ]);

        $this->assertTrue($result->success);
        // old has 1 newline (-1), new has 2 newlines (+2)
        $this->assertStringContainsString('-1', $result->output);
        $this->assertStringContainsString('+2', $result->output);
    }

    public function test_replace_with_empty_string_deletes_content(): void
    {
        $path = $this->createFile('keep delete me keep');
        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => ' delete me',
            'new_string' => '',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('keep keep', file_get_contents($path));
    }

    public function test_preserves_rest_of_file(): void
    {
        $path = $this->createFile("header\ntarget\nfooter");
        $this->tool->execute([
            'path' => $path,
            'old_string' => 'target',
            'new_string' => 'replaced',
        ]);

        $content = file_get_contents($path);
        $this->assertStringContainsString('header', $content);
        $this->assertStringContainsString('replaced', $content);
        $this->assertStringContainsString('footer', $content);
    }

    public function test_multiline_old_string_match(): void
    {
        $path = $this->createFile("line1\n    indented\nline3");
        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => "line1\n    indented",
            'new_string' => 'single line',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame("single line\nline3", file_get_contents($path));
    }

    public function test_whitespace_sensitive_matching(): void
    {
        $path = $this->createFile('    indented line');
        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => 'indented line',  // without leading spaces
            'new_string' => 'replaced',
        ]);

        // Should succeed — 'indented line' IS in '    indented line'
        $this->assertTrue($result->success);
    }

    public function test_large_file_edit(): void
    {
        // Create a ~1MB file with a unique marker in the middle
        $before = str_repeat('a', 500_000);
        $marker = '<<UNIQUE_MARKER>>';
        $after = str_repeat('b', 500_000);
        $path = $this->createFile($before.$marker.$after);

        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => $marker,
            'new_string' => 'REPLACED',
        ]);

        $this->assertTrue($result->success);
        $content = file_get_contents($path);
        $this->assertSame($before.'REPLACED'.$after, $content);
    }

    public function test_match_at_file_start(): void
    {
        $path = $this->createFile('HEADER'.str_repeat('x', 1000));

        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => 'HEADER',
            'new_string' => 'NEWHEADER',
        ]);

        $this->assertTrue($result->success);
        $content = file_get_contents($path);
        $this->assertSame('NEWHEADER'.str_repeat('x', 1000), $content);
    }

    public function test_match_at_file_end(): void
    {
        $path = $this->createFile(str_repeat('x', 1000).'FOOTER');

        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => 'FOOTER',
            'new_string' => 'NEWFOOTER',
        ]);

        $this->assertTrue($result->success);
        $content = file_get_contents($path);
        $this->assertSame(str_repeat('x', 1000).'NEWFOOTER', $content);
    }

    public function test_match_spanning_chunk_boundary(): void
    {
        // Create a file where the match straddles the 65536-byte chunk boundary
        $chunkSize = 65536;
        $needle = 'BOUNDARY_MATCH';
        $needleLen = strlen($needle);
        // Place needle so it starts 5 bytes before the chunk boundary
        $beforeChunk = str_repeat('a', $chunkSize - 5);
        $afterChunk = str_repeat('b', $chunkSize);
        $path = $this->createFile($beforeChunk.$needle.$afterChunk);

        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => $needle,
            'new_string' => 'REPLACED',
        ]);

        $this->assertTrue($result->success);
        $content = file_get_contents($path);
        $this->assertSame($beforeChunk.'REPLACED'.$afterChunk, $content);
    }

    public function test_deletion_with_empty_new_string_on_large_file(): void
    {
        $before = str_repeat('a', 100_000);
        $target = 'DELETE_ME';
        $after = str_repeat('b', 100_000);
        $path = $this->createFile($before.$target.$after);

        $result = $this->tool->execute([
            'path' => $path,
            'old_string' => $target,
            'new_string' => '',
        ]);

        $this->assertTrue($result->success);
        $content = file_get_contents($path);
        $this->assertSame($before.$after, $content);
    }

    private function createFile(string $content, string $name = 'test.txt'): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
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

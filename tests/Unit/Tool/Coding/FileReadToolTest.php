<?php

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\FileReadTool;
use PHPUnit\Framework\TestCase;

class FileReadToolTest extends TestCase
{
    private FileReadTool $tool;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new FileReadTool;
        $this->tempDir = sys_get_temp_dir().'/kosmokrator_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_name_returns_file_read(): void
    {
        $this->assertSame('file_read', $this->tool->name());
    }

    public function test_parameters_schema(): void
    {
        $params = $this->tool->parameters();

        $this->assertArrayHasKey('path', $params);
        $this->assertArrayHasKey('offset', $params);
        $this->assertArrayHasKey('limit', $params);
        $this->assertSame('string', $params['path']['type']);
        $this->assertSame('integer', $params['offset']['type']);
        $this->assertSame('integer', $params['limit']['type']);
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['path'], $this->tool->requiredParameters());
    }

    public function test_reads_file_with_line_numbers(): void
    {
        $path = $this->createFile("line1\nline2\nline3\nline4\nline5");
        $result = $this->tool->execute(['path' => $path]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString("1\tline1", $result->output);
        $this->assertStringContainsString("5\tline5", $result->output);
    }

    public function test_reads_file_with_default_offset_and_limit(): void
    {
        $lines = implode("\n", array_map(fn ($i) => "line{$i}", range(1, 10)));
        $path = $this->createFile($lines);
        $result = $this->tool->execute(['path' => $path]);

        $this->assertTrue($result->success);
        for ($i = 1; $i <= 10; $i++) {
            $this->assertStringContainsString("line{$i}", $result->output);
        }
    }

    public function test_offset_starts_reading_from_given_line(): void
    {
        $lines = implode("\n", array_map(fn ($i) => "line{$i}", range(1, 10)));
        $path = $this->createFile($lines);
        $result = $this->tool->execute(['path' => $path, 'offset' => 5]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('line5', $result->output);
        $this->assertStringContainsString('line10', $result->output);
        $this->assertStringNotContainsString('line4', $result->output);
    }

    public function test_limit_truncates_output(): void
    {
        $lines = implode("\n", array_map(fn ($i) => "line{$i}", range(1, 10)));
        $path = $this->createFile($lines);
        $result = $this->tool->execute(['path' => $path, 'limit' => 3]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('line1', $result->output);
        $this->assertStringContainsString('line3', $result->output);
        $this->assertStringNotContainsString('line4', $result->output);
        $this->assertStringContainsString('7 more lines', $result->output);
    }

    public function test_offset_and_limit_combined(): void
    {
        $lines = implode("\n", array_map(fn ($i) => "line{$i}", range(1, 20)));
        $path = $this->createFile($lines);
        $result = $this->tool->execute(['path' => $path, 'offset' => 5, 'limit' => 3]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('line5', $result->output);
        $this->assertStringContainsString('line7', $result->output);
        $this->assertStringNotContainsString('line4', $result->output);
        $this->assertStringNotContainsString('line8', $result->output);
        $this->assertStringContainsString('13 more lines', $result->output);
    }

    public function test_limit_clamped_to_max_5000(): void
    {
        $path = $this->createFile('short file');
        $result = $this->tool->execute(['path' => $path, 'limit' => 10000]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('short file', $result->output);
    }

    public function test_offset_minimum_is_one(): void
    {
        $path = $this->createFile("line1\nline2");
        $result = $this->tool->execute(['path' => $path, 'offset' => 0]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('line1', $result->output);

        $result2 = $this->tool->execute(['path' => $path, 'offset' => -5]);
        $this->assertTrue($result2->success);
        $this->assertStringContainsString('line1', $result2->output);
    }

    public function test_file_not_found_returns_error(): void
    {
        $result = $this->tool->execute(['path' => $this->tempDir.'/nonexistent.txt']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('File not found', $result->output);
    }

    public function test_directory_path_returns_error(): void
    {
        $result = $this->tool->execute(['path' => $this->tempDir]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('directory', $result->output);
    }

    public function test_empty_file(): void
    {
        $path = $this->createFile('');
        $result = $this->tool->execute(['path' => $path]);

        $this->assertTrue($result->success);
    }

    public function test_line_numbers_are_right_padded(): void
    {
        $lines = implode("\n", array_map(fn ($i) => "line{$i}", range(1, 100)));
        $path = $this->createFile($lines);
        $result = $this->tool->execute(['path' => $path]);

        $this->assertTrue($result->success);
        // Line 1 should be padded to match width of "100" (3 chars)
        $this->assertStringContainsString("  1\t", $result->output);
    }

    public function test_truncation_message_shows_remaining_count(): void
    {
        $lines = implode("\n", array_map(fn ($i) => "line{$i}", range(1, 20)));
        $path = $this->createFile($lines);
        $result = $this->tool->execute(['path' => $path, 'limit' => 5]);

        $this->assertStringContainsString('15 more lines', $result->output);
    }

    public function test_no_truncation_message_when_all_lines_read(): void
    {
        $path = $this->createFile("line1\nline2\nline3");
        $result = $this->tool->execute(['path' => $path]);

        $this->assertTrue($result->success);
        $this->assertStringNotContainsString('more lines', $result->output);
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

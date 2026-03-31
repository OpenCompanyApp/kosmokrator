<?php

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\GlobTool;
use PHPUnit\Framework\TestCase;

class GlobToolTest extends TestCase
{
    private GlobTool $tool;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new GlobTool;
        $this->tempDir = sys_get_temp_dir().'/kosmokrator_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_name_returns_glob(): void
    {
        $this->assertSame('glob', $this->tool->name());
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['pattern'], $this->tool->requiredParameters());
    }

    public function test_finds_files_matching_pattern(): void
    {
        $this->createFile('a.php');
        $this->createFile('b.php');
        $this->createFile('c.txt');

        $result = $this->tool->execute(['pattern' => '*.php', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('a.php', $result->output);
        $this->assertStringContainsString('b.php', $result->output);
        $this->assertStringNotContainsString('c.txt', $result->output);
    }

    public function test_no_matches_returns_success_with_message(): void
    {
        $this->createFile('a.txt');

        $result = $this->tool->execute(['pattern' => '*.xyz', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('No files matching', $result->output);
    }

    public function test_error_when_path_is_not_directory(): void
    {
        $filePath = $this->createFile('test.txt');

        $result = $this->tool->execute(['pattern' => '*', 'path' => $filePath]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Directory not found', $result->output);
    }

    public function test_max_200_results(): void
    {
        for ($i = 0; $i < 210; $i++) {
            $this->createFile(sprintf('file_%03d.txt', $i));
        }

        $result = $this->tool->execute(['pattern' => '*.txt', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $lines = array_filter(explode("\n", $result->output));
        // 200 files + 1 truncation message
        $this->assertLessThanOrEqual(201, count($lines));
    }

    public function test_nested_directory_pattern(): void
    {
        mkdir($this->tempDir.'/src/sub', 0755, true);
        $this->createFile('root.php');
        file_put_contents($this->tempDir.'/src/foo.php', 'content');
        file_put_contents($this->tempDir.'/src/sub/bar.php', 'content');

        $result = $this->tool->execute(['pattern' => 'src/**/*.php', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('foo.php', $result->output);
        $this->assertStringContainsString('bar.php', $result->output);
    }

    public function test_returns_relative_paths(): void
    {
        mkdir($this->tempDir.'/subdir', 0755, true);
        file_put_contents($this->tempDir.'/subdir/file.txt', 'content');

        $result = $this->tool->execute(['pattern' => '**/*.txt', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        // Should not contain the full absolute tempDir path in each line
        $lines = explode("\n", trim($result->output));
        foreach ($lines as $line) {
            $this->assertStringNotContainsString($this->tempDir, $line);
        }
    }

    public function test_defaults_to_cwd_when_no_path(): void
    {
        // Just verify it doesn't error out when path is omitted
        $result = $this->tool->execute(['pattern' => '*.php']);

        $this->assertTrue($result->success);
    }

    private function createFile(string $name, string $content = 'content'): string
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

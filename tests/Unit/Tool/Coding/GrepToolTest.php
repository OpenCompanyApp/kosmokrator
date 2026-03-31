<?php

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\GrepTool;
use PHPUnit\Framework\TestCase;

class GrepToolTest extends TestCase
{
    private GrepTool $tool;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new GrepTool;
        $this->tempDir = sys_get_temp_dir().'/kosmokrator_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_name_returns_grep(): void
    {
        $this->assertSame('grep', $this->tool->name());
    }

    public function test_required_parameters(): void
    {
        $this->assertSame(['pattern'], $this->tool->requiredParameters());
    }

    public function test_finds_matching_lines(): void
    {
        $this->createFile('file.txt', "hello world\ngoodbye world\nhello again");
        $result = $this->tool->execute(['pattern' => 'hello', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('hello', $result->output);
    }

    public function test_no_matches_returns_no_matches_message(): void
    {
        $this->createFile('file.txt', 'nothing relevant here');
        $result = $this->tool->execute(['pattern' => 'zzzzz_nonexistent', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('No matches found', $result->output);
    }

    public function test_respects_glob_filter(): void
    {
        $this->createFile('a.php', 'match here');
        $this->createFile('b.txt', 'match here too');

        $result = $this->tool->execute([
            'pattern' => 'match',
            'path' => $this->tempDir,
            'glob' => '*.php',
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('a.php', $result->output);
        $this->assertStringNotContainsString('b.txt', $result->output);
    }

    public function test_regex_pattern(): void
    {
        $this->createFile('data.txt', "foo123\nfoobar\nfoo456");
        $result = $this->tool->execute(['pattern' => 'foo[0-9]+', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('foo123', $result->output);
        $this->assertStringContainsString('foo456', $result->output);
    }

    public function test_output_limited_to_100_lines(): void
    {
        // Create a file with 200 matching lines
        $lines = array_map(fn ($i) => "match_line_{$i}", range(1, 200));
        $this->createFile('big.txt', implode("\n", $lines));
        $result = $this->tool->execute(['pattern' => 'match_line_', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $outputLines = explode("\n", trim($result->output));
        $this->assertLessThanOrEqual(100, count($outputLines));
    }

    public function test_searches_specific_file(): void
    {
        $file = $this->createFile('target.txt', "find this line\nnot this");
        $result = $this->tool->execute(['pattern' => 'find this', 'path' => $file]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('find this', $result->output);
    }

    public function test_searches_directory_recursively(): void
    {
        mkdir($this->tempDir.'/sub', 0755, true);
        $this->createFile('root.txt', 'match_root');
        file_put_contents($this->tempDir.'/sub/deep.txt', 'match_deep');

        $result = $this->tool->execute(['pattern' => 'match_', 'path' => $this->tempDir]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('match_root', $result->output);
        $this->assertStringContainsString('match_deep', $result->output);
    }

    private function createFile(string $name, string $content = ''): string
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

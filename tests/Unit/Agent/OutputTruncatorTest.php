<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\OutputTruncator;
use PHPUnit\Framework\TestCase;

class OutputTruncatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/kosmokrator_truncator_test_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tmpDir.'/*');
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function test_no_truncation_under_limits(): void
    {
        $truncator = new OutputTruncator(maxLines: 100, maxBytes: 10_000, storagePath: $this->tmpDir);
        $output = "line 1\nline 2\nline 3";

        $result = $truncator->truncate($output, 'tc1');

        $this->assertSame($output, $result);
        $this->assertEmpty(glob($this->tmpDir.'/*'));
    }

    public function test_truncate_by_lines(): void
    {
        $truncator = new OutputTruncator(maxLines: 3, maxBytes: 100_000, storagePath: $this->tmpDir);
        $output = "line 1\nline 2\nline 3\nline 4\nline 5";

        $result = $truncator->truncate($output, 'tc1');

        $this->assertStringContainsString("line 1\nline 2\nline 3", $result);
        $this->assertStringNotContainsString('line 4', $result);
        $this->assertStringContainsString('[truncated', $result);
    }

    public function test_truncate_by_bytes(): void
    {
        $truncator = new OutputTruncator(maxLines: 100_000, maxBytes: 20, storagePath: $this->tmpDir);
        $output = str_repeat('x', 100);

        $result = $truncator->truncate($output, 'tc2');

        $this->assertStringContainsString('[truncated', $result);
        // The non-truncated portion should be ~20 bytes
        $lines = explode("\n", $result);
        $this->assertLessThanOrEqual(21, strlen($lines[0])); // 20 bytes of content
    }

    public function test_full_output_saved_to_disk(): void
    {
        $truncator = new OutputTruncator(maxLines: 2, maxBytes: 100_000, storagePath: $this->tmpDir);
        $output = "line 1\nline 2\nline 3\nline 4";

        $truncator->truncate($output, 'tc3');

        $files = glob($this->tmpDir.'/tool_tc3.txt');
        $this->assertCount(1, $files);
        $this->assertSame($output, file_get_contents($files[0]));
    }

    public function test_truncation_message_contains_path(): void
    {
        $truncator = new OutputTruncator(maxLines: 1, maxBytes: 100_000, storagePath: $this->tmpDir);
        $output = "line 1\nline 2";

        $result = $truncator->truncate($output, 'tc4');

        $this->assertStringContainsString($this->tmpDir, $result);
        $this->assertStringContainsString('[truncated - full output saved to', $result);
    }

    public function test_truncate_preserves_multibyte_utf8(): void
    {
        $truncator = new OutputTruncator(maxLines: 100_000, maxBytes: 50, storagePath: $this->tmpDir);
        // Mix emoji 🎉 (4 bytes) and CJK 你好世界 (12 bytes) — repeat to exceed 50 bytes
        $output = str_repeat('🎉你好世界', 10); // ~160 bytes

        $result = $truncator->truncate($output, 'tc_utf8');

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'Truncated output must be valid UTF-8');
        $this->assertStringContainsString('[truncated', $result);

        // Extract the content portion before the truncation marker
        $markerPos = strpos($result, "\n\n[truncated");
        $content = $markerPos !== false ? substr($result, 0, $markerPos) : $result;
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'), 'Content before marker must be valid UTF-8');
    }

    public function test_truncate_empty_output(): void
    {
        $truncator = new OutputTruncator(maxLines: 100, maxBytes: 10, storagePath: $this->tmpDir);

        $result = $truncator->truncate('', 'tc_empty');

        $this->assertSame('', $result);
        $this->assertStringNotContainsString('[truncated', $result);
    }

    public function test_truncate_only_newlines(): void
    {
        $truncator = new OutputTruncator(maxLines: 100, maxBytes: 100_000, storagePath: $this->tmpDir);
        $output = str_repeat("\n", 200);

        $result = $truncator->truncate($output, 'tc_newlines');

        $this->assertStringContainsString('[truncated', $result);
    }

    public function test_truncate_binary_content(): void
    {
        $truncator = new OutputTruncator(maxLines: 100_000, maxBytes: 20, storagePath: $this->tmpDir);
        $output = random_bytes(200);

        $result = $truncator->truncate($output, 'tc_binary');

        $this->assertStringContainsString('[truncated', $result);
    }

    public function test_truncate_very_long_single_line(): void
    {
        $truncator = new OutputTruncator(maxLines: 100_000, maxBytes: 100, storagePath: $this->tmpDir);
        $output = str_repeat('a', 500);

        $result = $truncator->truncate($output, 'tc_longline');

        $this->assertStringContainsString('[truncated', $result);

        // The main output part (before the marker) should be ≤ 100 bytes
        $markerPos = strpos($result, "\n\n[truncated");
        $content = $markerPos !== false ? substr($result, 0, $markerPos) : $result;
        $this->assertLessThanOrEqual(100, strlen($content));
    }
}

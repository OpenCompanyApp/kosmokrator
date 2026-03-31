<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Diff;

use Kosmokrator\UI\Diff\DiffRenderer;
use PHPUnit\Framework\TestCase;

class DiffRendererTest extends TestCase
{
    private DiffRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new DiffRenderer;
    }

    public function test_identical_strings_produce_empty_output(): void
    {
        $code = "line1\nline2\nline3";
        $result = $this->renderer->render($code, $code, '');

        $this->assertSame('', $result);
    }

    public function test_single_line_added(): void
    {
        $old = "line1\nline2\nline3";
        $new = "line1\nline2\nnew_line\nline3";

        $lines = $this->renderer->renderLines($old, $new, '');

        // Should contain the added line with + marker
        $combined = implode("\n", $lines);
        $this->assertStringContainsString('+', $combined);
        $this->assertStringContainsString('new_line', $combined);
        // Should contain context lines
        $this->assertStringContainsString('line1', $combined);
        $this->assertStringContainsString('line2', $combined);
        $this->assertStringContainsString('line3', $combined);
        // Should NOT contain removed marker
        $this->assertStringNotContainsString(' - ', $combined);
    }

    public function test_single_line_removed(): void
    {
        $old = "line1\nline2\nline3";
        $new = "line1\nline3";

        $lines = $this->renderer->renderLines($old, $new, '');

        $combined = implode("\n", $lines);
        $this->assertStringContainsString('-', $combined);
        $this->assertStringContainsString('line2', $combined);
        $this->assertStringNotContainsString('+', $combined);
    }

    public function test_single_line_changed(): void
    {
        $old = "line1\nold_value\nline3";
        $new = "line1\nnew_value\nline3";

        $lines = $this->renderer->renderLines($old, $new, '');

        $combined = implode("\n", $lines);
        // Should have both remove and add
        $this->assertStringContainsString('-', $combined);
        $this->assertStringContainsString('+', $combined);
        $this->assertStringContainsString('old_value', $combined);
        $this->assertStringContainsString('new_value', $combined);
    }

    public function test_multiple_hunks_with_separator(): void
    {
        // Create content with two changes separated by >6 unchanged lines
        $lines = [];
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = "line{$i}";
        }
        $old = implode("\n", $lines);

        // Change line 3 and line 18 (15 lines apart)
        $lines[2] = 'changed_line3';
        $lines[17] = 'changed_line18';
        $new = implode("\n", $lines);

        $result = $this->renderer->renderLines($old, $new, '');

        $combined = implode("\n", $result);
        // Should contain the hunk separator
        $this->assertStringContainsString('· · ✧ · ·', $combined);
        // Should contain both changes
        $this->assertStringContainsString('changed_line3', $combined);
        $this->assertStringContainsString('changed_line18', $combined);
    }

    public function test_no_separator_for_close_changes(): void
    {
        // Two changes separated by only 4 lines (< 2*3=6, should merge)
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "line{$i}";
        }
        $old = implode("\n", $lines);

        $lines[1] = 'changed_line2';
        $lines[5] = 'changed_line6';
        $new = implode("\n", $lines);

        $result = $this->renderer->renderLines($old, $new, '');

        $combined = implode("\n", $result);
        // Should NOT have separator — changes are close enough to merge
        $this->assertStringNotContainsString('· · ✧ · ·', $combined);
        // Both changes present
        $this->assertStringContainsString('changed_line2', $combined);
        $this->assertStringContainsString('changed_line6', $combined);
    }

    public function test_change_summary(): void
    {
        $old = "line1\nold\nline3";
        $new = "line1\nnew\nextra\nline3";

        $result = $this->renderer->renderLines($old, $new, '');

        $combined = implode("\n", $result);
        // Should have a change summary at the end
        $this->assertStringContainsString('✧', $combined);
        $this->assertStringContainsString('addition', $combined);
    }

    public function test_file_context_with_real_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'diff_test_');
        file_put_contents($tmpFile, "<?php\nfunction foo() {\n    \$a = 1;\n    \$b = 2;\n    return \$a + \$b;\n}\n");

        $old = '    $a = 1;';
        $new = '    $a = 42;';

        // Apply the edit to the file
        $content = file_get_contents($tmpFile);
        file_put_contents($tmpFile, str_replace($old, $new, $content));

        $result = $this->renderer->renderLines($old, $new, $tmpFile);

        $combined = implode("\n", $result);
        // Should include file context
        $this->assertStringContainsString('function foo', $combined);
        // Should have the change
        $this->assertStringContainsString('42', $combined);

        unlink($tmpFile);
    }

    public function test_file_not_readable_falls_back(): void
    {
        $old = "line1\nold\nline3";
        $new = "line1\nnew\nline3";

        // Non-existent path
        $result = $this->renderer->renderLines($old, $new, '/nonexistent/path.php');

        // Should still produce output (just without file offset)
        $this->assertNotEmpty($result);
        $combined = implode("\n", $result);
        $this->assertStringContainsString('old', $combined);
        $this->assertStringContainsString('new', $combined);
    }

    public function test_render_returns_string(): void
    {
        $old = "before";
        $new = "after";

        $result = $this->renderer->render($old, $new, '');

        $this->assertIsString($result);
        $this->assertStringContainsString('before', $result);
        $this->assertStringContainsString('after', $result);
    }

    public function test_context_lines_limited_at_file_boundaries(): void
    {
        // Edit at the very start — should have no context before
        $old = "first\nsecond";
        $new = "changed\nsecond";

        $lines = $this->renderer->renderLines($old, $new, '');

        // Should not crash and should contain the change
        $combined = implode("\n", $lines);
        $this->assertStringContainsString('changed', $combined);
    }

    public function test_line_numbers_present(): void
    {
        $old = "line1\nline2\nline3\nline4\nline5";
        $new = "line1\nline2\nchanged\nline4\nline5";

        $lines = $this->renderer->renderLines($old, $new, '');

        $combined = implode("\n", $lines);
        // Should contain line numbers (1-based)
        $this->assertMatchesRegularExpression('/\d+\s+line1/', $combined);
    }

    public function test_empty_old_string(): void
    {
        $old = '';
        $new = "new_line1\nnew_line2";

        $lines = $this->renderer->renderLines($old, $new, '');

        $this->assertNotEmpty($lines);
        $combined = implode("\n", $lines);
        $this->assertStringContainsString('new_line1', $combined);
    }

    public function test_empty_new_string(): void
    {
        $old = "old_line1\nold_line2";
        $new = '';

        $lines = $this->renderer->renderLines($old, $new, '');

        $this->assertNotEmpty($lines);
        $combined = implode("\n", $lines);
        $this->assertStringContainsString('old_line1', $combined);
    }

    public function test_large_diff_performance(): void
    {
        // Generate 500-line blocks with scattered changes
        $oldLines = [];
        $newLines = [];
        for ($i = 0; $i < 500; $i++) {
            $oldLines[] = "line_{$i}_content_here";
            if ($i % 50 === 0) {
                $newLines[] = "changed_{$i}_content_here";
            } else {
                $newLines[] = "line_{$i}_content_here";
            }
        }

        $start = microtime(true);
        $result = $this->renderer->renderLines(implode("\n", $oldLines), implode("\n", $newLines), '');
        $elapsed = microtime(true) - $start;

        $this->assertNotEmpty($result);
        $this->assertLessThan(5.0, $elapsed, 'Diff rendering should complete in under 5 seconds');
    }
}

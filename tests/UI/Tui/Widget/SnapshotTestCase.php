<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\UI\Tui\Widget;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit trait for visual snapshot testing of TUI widgets.
 *
 * Usage:
 *   class MyWidgetTest extends TestCase
 *   {
 *       use SnapshotTestCase;
 *
 *       public function test_renders_basic_state(): void
 *       {
 *           $widget = new MyWidget('hello');
 *           $screen = implode("\n", $this->renderWidget($widget));
 *           $this->assertMatchesSnapshot($screen, 'my-widget/basic-state');
 *       }
 *   }
 *
 * Run with UPDATE_SNAPSHOTS=1 to regenerate golden files:
 *   UPDATE_SNAPSHOTS=1 vendor/bin/phpunit tests/UI/Tui/Widget/
 */
trait SnapshotTestCase
{
    /**
     * Assert that the given screen content matches the stored snapshot.
     *
     * On first run, creates the snapshot and skips the test.
     * On mismatch, shows a unified diff and fails.
     * With UPDATE_SNAPSHOTS=1, overwrites the snapshot and passes.
     *
     * @param string $actual          The rendered screen content
     * @param string $snapshotName   Slash-path identifier (e.g., "question-widget/basic")
     */
    private function assertMatchesSnapshot(string $actual, string $snapshotName): void
    {
        $snapshotPath = $this->resolveSnapshotPath($snapshotName);
        $updateSnapshots = (bool) ($_ENV['UPDATE_SNAPSHOTS'] ?? false);

        if (!file_exists($snapshotPath)) {
            $this->writeSnapshot($snapshotPath, $actual);
            $this->markTestSkipped("Snapshot created: {$snapshotName}");
            return;
        }

        $expected = file_get_contents($snapshotPath);

        if ($actual === $expected) {
            // Snapshot matches — pass
            /** @var TestCase $this */
            $this->assertTrue(true);
            return;
        }

        if ($updateSnapshots) {
            $this->writeSnapshot($snapshotPath, $actual);
            echo "\n  ↻ Snapshot updated: {$snapshotName}\n";
            return;
        }

        // Show diff and fail
        $diff = $this->computeDiff($expected, $actual, $snapshotName);
        throw new AssertionFailedError($diff);
    }

    /**
     * Resolve the filesystem path for a snapshot.
     *
     * Snapshots are stored in __snapshots__/ directories relative to the test class.
     *
     * @param string $name  Slash-path like "question-widget/basic-question"
     * @return string       Absolute path to the .snap file
     */
    private function resolveSnapshotPath(string $name): string
    {
        $reflection = new \ReflectionClass(static::class);
        $testDir = dirname($reflection->getFileName());
        $snapshotDir = $testDir . '/__snapshots__';

        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0755, true);
        }

        // Convert slash-path to file path: "widget/state" → "widget__state.snap"
        $filename = str_replace('/', '__', $name) . '.snap';

        return $snapshotDir . '/' . $filename;
    }

    /**
     * Write content to a snapshot file, creating directories as needed.
     */
    private function writeSnapshot(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * Compute a human-readable unified diff between expected and actual screen content.
     *
     * Uses `diff -u` when available for proper unified format with context lines.
     * Falls back to a built-in line-by-line comparison when `diff` is not available.
     */
    private function computeDiff(string $expected, string $actual, string $name): string
    {
        // Try using system diff for proper unified format
        $diff = $this->systemDiff($expected, $actual, $name);
        if ($diff !== null) {
            return $diff;
        }

        // Fallback: built-in line-by-line diff
        return $this->builtinDiff($expected, $actual, $name);
    }

    /**
     * Attempt to use the system `diff` command for unified diff output.
     *
     * Returns null if the diff command is unavailable or fails.
     */
    private function systemDiff(string $expected, string $actual, string $name): ?string
    {
        $tempDir = sys_get_temp_dir();
        $expFile = $tempDir . '/kosmokrator_snap_expected_' . getmypid();
        $actFile = $tempDir . '/kosmokrator_snap_actual_' . getmypid();

        file_put_contents($expFile, $expected);
        file_put_contents($actFile, $actual);

        $output = @shell_exec(
            'diff -U5 ' . escapeshellarg($expFile) . ' ' . escapeshellarg($actFile) . ' 2>&1'
        );

        @unlink($expFile);
        @unlink($actFile);

        if ($output === null || $output === '') {
            return null;
        }

        $header = "\nSnapshot mismatch: {$name}\n";
        $header .= str_repeat('─', 60) . "\n";

        $footer = str_repeat('─', 60) . "\n";
        $footer .= "To update: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit ...\n";

        return $header . $output . $footer;
    }

    /**
     * Built-in line-by-line diff when system `diff` is unavailable.
     */
    private function builtinDiff(string $expected, string $actual, string $name): string
    {
        $expectedLines = explode("\n", $expected);
        $actualLines = explode("\n", $actual);

        $diff = "\nSnapshot mismatch: {$name}\n";
        $diff .= str_repeat('─', 60) . "\n";

        $maxLines = max(count($expectedLines), count($actualLines));
        for ($i = 0; $i < $maxLines; $i++) {
            $exp = $expectedLines[$i] ?? '';
            $act = $actualLines[$i] ?? '';

            if ($exp === $act) {
                $diff .= sprintf("%4d │ %s\n", $i + 1, $exp);
            } else {
                if ($exp !== '') {
                    $diff .= sprintf("%4d │ - %s\n", $i + 1, $exp);
                }
                if ($act !== '') {
                    $diff .= sprintf("%4d │ + %s\n", $i + 1, $act);
                }
            }
        }

        $diff .= str_repeat('─', 60) . "\n";
        $diff .= "To update: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit ...\n";

        return $diff;
    }
}

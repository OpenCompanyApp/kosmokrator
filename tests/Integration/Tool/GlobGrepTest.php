<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\Tool;

use Kosmokrator\Tests\Integration\IntegrationTestCase;
use Kosmokrator\Tool\Coding\GlobTool;
use Kosmokrator\Tool\Coding\GrepTool;

/**
 * Integration tests for search tools (GlobTool, GrepTool).
 * Uses real filesystem operations and process spawning.
 */
class GlobGrepTest extends IntegrationTestCase
{
    private GlobTool $globTool;

    private GrepTool $grepTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->globTool = new GlobTool;
        $this->grepTool = new GrepTool;
    }

    // ── GlobTool ───────────────────────────────────────────────────────

    public function test_glob_finds_files_by_pattern(): void
    {
        $this->createFile('src/A.php', '<?php class A {}');
        $this->createFile('src/B.php', '<?php class B {}');
        $this->createFile('src/C.txt', 'not php');

        $result = $this->globTool->execute([
            'pattern' => '**/*.php',
            'path' => $this->tmpDir,
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('A.php', $result->output);
        $this->assertStringContainsString('B.php', $result->output);
        $this->assertStringNotContainsString('C.txt', $result->output);
    }

    public function test_glob_finds_all_files(): void
    {
        $this->createFile('a.txt', 'a');
        $this->createFile('sub/b.txt', 'b');
        $this->createFile('sub/deep/c.txt', 'c');

        $result = $this->globTool->execute([
            'pattern' => '**/*',
            'path' => $this->tmpDir,
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('a.txt', $result->output);
        $this->assertStringContainsString('b.txt', $result->output);
        $this->assertStringContainsString('c.txt', $result->output);
    }

    public function test_glob_empty_directory(): void
    {
        $emptyDir = $this->tmpDir.'/empty';
        mkdir($emptyDir, 0755, true);

        $result = $this->globTool->execute([
            'pattern' => '**/*',
            'path' => $emptyDir,
        ]);

        $this->assertTrue($result->success);
        // Glob returns a "no files" message for empty directories
        $this->assertStringContainsString('No files', $result->output);
    }

    // ── GrepTool (requires Amp) ────────────────────────────────────────

    public function test_grep_finds_matching_lines(): void
    {
        $this->createFile('search.txt', "hello world\ngoodbye world\nhello again");

        $result = \Amp\async(function () {
            return $this->grepTool->execute([
                'pattern' => 'hello',
                'path' => $this->tmpDir,
            ]);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('hello world', $result->output);
        $this->assertStringContainsString('hello again', $result->output);
        $this->assertStringNotContainsString('goodbye', $result->output);
    }

    public function test_grep_searches_nested_directories(): void
    {
        $this->createFile('top.txt', 'target string here');
        $this->createFile('sub/nested.txt', 'also has target string');

        $result = \Amp\async(function () {
            return $this->grepTool->execute([
                'pattern' => 'target string',
                'path' => $this->tmpDir,
            ]);
        })->await();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('top.txt', $result->output);
        $this->assertStringContainsString('nested.txt', $result->output);
    }

    public function test_grep_no_matches_returns_empty(): void
    {
        $this->createFile('nope.txt', 'nothing to see here');

        $result = \Amp\async(function () {
            return $this->grepTool->execute([
                'pattern' => 'nonexistent_pattern_xyz',
                'path' => $this->tmpDir,
            ]);
        })->await();

        $this->assertTrue($result->success);
    }

    public function test_glob_then_grep_workflow(): void
    {
        // Create a small project structure
        $this->createFile('src/User.php', '<?php class User { private string $name; }');
        $this->createFile('src/Post.php', '<?php class Post { private string $title; }');
        $this->createFile('src/Controller.php', '<?php class Controller { public function handle() {} }');
        $this->createFile('config.yaml', 'name: test_app');

        // Step 1: Glob for PHP files
        $globResult = $this->globTool->execute([
            'pattern' => '**/*.php',
            'path' => $this->tmpDir,
        ]);

        $this->assertTrue($globResult->success);
        $this->assertStringContainsString('User.php', $globResult->output);
        $this->assertStringContainsString('Post.php', $globResult->output);
        $this->assertStringNotContainsString('config.yaml', $globResult->output);

        // Step 2: Grep for 'private string' across those files
        $grepResult = \Amp\async(function () {
            return $this->grepTool->execute([
                'pattern' => 'private string',
                'path' => $this->tmpDir,
            ]);
        })->await();

        $this->assertTrue($grepResult->success);
        $this->assertStringContainsString('User.php', $grepResult->output);
        $this->assertStringContainsString('Post.php', $grepResult->output);
        $this->assertStringNotContainsString('Controller.php', $grepResult->output);
    }
}

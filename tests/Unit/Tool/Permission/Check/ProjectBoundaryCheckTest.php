<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Permission\Check;

use Kosmokrator\Tool\Permission\Check\ProjectBoundaryCheck;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionMode;
use PHPUnit\Framework\TestCase;

class ProjectBoundaryCheckTest extends TestCase
{
    private string $projectRoot;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/boundary_test_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        // Use realpath to resolve symlinks (e.g. /var → /private/var on macOS)
        $this->projectRoot = realpath($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) && ! is_link($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeCheck(array $allowedPaths = [], PermissionMode $mode = PermissionMode::Guardian): ProjectBoundaryCheck
    {
        return new ProjectBoundaryCheck($this->projectRoot, $allowedPaths, fn () => $mode);
    }

    // --- Inside project passes ---

    public function test_file_write_inside_project_passes(): void
    {
        $check = $this->makeCheck();
        file_put_contents($this->tmpDir.'/file.php', 'test');

        $result = $check->evaluate('file_write', ['path' => $this->tmpDir.'/file.php', 'content' => 'x']);
        $this->assertNull($result);
    }

    public function test_file_edit_inside_project_passes(): void
    {
        $check = $this->makeCheck();
        file_put_contents($this->tmpDir.'/file.php', 'test');

        $result = $check->evaluate('file_edit', ['path' => $this->tmpDir.'/file.php']);
        $this->assertNull($result);
    }

    public function test_file_read_inside_project_passes(): void
    {
        $check = $this->makeCheck();
        file_put_contents($this->tmpDir.'/file.php', 'test');

        $result = $check->evaluate('file_read', ['path' => $this->tmpDir.'/file.php']);
        $this->assertNull($result);
    }

    public function test_glob_inside_project_passes(): void
    {
        $check = $this->makeCheck();

        $result = $check->evaluate('glob', ['pattern' => '*.php', 'path' => $this->tmpDir]);
        $this->assertNull($result);
    }

    public function test_grep_inside_project_passes(): void
    {
        $check = $this->makeCheck();

        $result = $check->evaluate('grep', ['pattern' => 'foo', 'path' => $this->tmpDir]);
        $this->assertNull($result);
    }

    // --- Outside project asks ---

    public function test_file_write_outside_project_asks(): void
    {
        $check = $this->makeCheck();

        $result = $check->evaluate('file_write', ['path' => '/etc/test', 'content' => 'x']);
        $this->assertNotNull($result);
        $this->assertSame(PermissionAction::Ask, $result->action);
        $this->assertStringContainsString('outside the project root', $result->reason);
    }

    public function test_file_read_outside_project_asks(): void
    {
        $check = $this->makeCheck();

        $result = $check->evaluate('file_read', ['path' => '/etc/hosts']);
        $this->assertNotNull($result);
        $this->assertSame(PermissionAction::Ask, $result->action);
    }

    public function test_glob_outside_project_asks(): void
    {
        $check = $this->makeCheck();

        $result = $check->evaluate('glob', ['pattern' => '*', 'path' => '/etc']);
        $this->assertNotNull($result);
        $this->assertSame(PermissionAction::Ask, $result->action);
    }

    public function test_grep_outside_project_asks(): void
    {
        $check = $this->makeCheck();

        $result = $check->evaluate('grep', ['pattern' => 'foo', 'path' => '/etc']);
        $this->assertNotNull($result);
        $this->assertSame(PermissionAction::Ask, $result->action);
    }

    // --- Non-bounded tools pass ---

    public function test_non_file_tool_passes(): void
    {
        $check = $this->makeCheck();

        $this->assertNull($check->evaluate('bash', ['command' => 'cat /etc/hosts']));
        $this->assertNull($check->evaluate('task_create', ['subject' => 'test']));
    }

    // --- Empty path passes ---

    public function test_empty_path_passes(): void
    {
        $check = $this->makeCheck();

        $this->assertNull($check->evaluate('file_write', ['path' => '', 'content' => 'x']));
        $this->assertNull($check->evaluate('file_write', ['content' => 'x']));
    }

    // --- Exact project root passes ---

    public function test_exact_project_root_passes(): void
    {
        $check = $this->makeCheck();

        $result = $check->evaluate('glob', ['pattern' => '*', 'path' => $this->tmpDir]);
        $this->assertNull($result);
    }

    // --- Prometheus exempt ---

    public function test_prometheus_mode_exempt(): void
    {
        $check = $this->makeCheck(mode: PermissionMode::Prometheus);

        $result = $check->evaluate('file_write', ['path' => '/etc/test', 'content' => 'x']);
        $this->assertNull($result);
    }

    public function test_argus_mode_asks(): void
    {
        $check = $this->makeCheck(mode: PermissionMode::Argus);

        $result = $check->evaluate('file_write', ['path' => '/etc/test', 'content' => 'x']);
        $this->assertNotNull($result);
        $this->assertSame(PermissionAction::Ask, $result->action);
    }

    // --- Allowed paths override ---

    public function test_allowed_paths_override(): void
    {
        $allowedDir = sys_get_temp_dir().'/allowed_'.uniqid();
        mkdir($allowedDir, 0755, true);
        file_put_contents($allowedDir.'/config.yaml', 'test');

        try {
            $check = $this->makeCheck([$allowedDir]);

            $result = $check->evaluate('file_write', ['path' => $allowedDir.'/config.yaml', 'content' => 'x']);
            $this->assertNull($result);
        } finally {
            @unlink($allowedDir.'/config.yaml');
            @rmdir($allowedDir);
        }
    }

    // --- Symlink to outside asks ---

    public function test_symlink_to_outside_asks(): void
    {
        $outsideDir = sys_get_temp_dir().'/outside_'.uniqid();
        mkdir($outsideDir, 0755, true);
        file_put_contents($outsideDir.'/secret.txt', 'secret');

        $linkPath = $this->tmpDir.'/link';
        symlink($outsideDir.'/secret.txt', $linkPath);

        try {
            $check = $this->makeCheck();

            // The link is inside the project, but resolves to outside
            $result = $check->evaluate('file_read', ['path' => $linkPath]);
            $this->assertNotNull($result);
            $this->assertSame(PermissionAction::Ask, $result->action);
        } finally {
            @unlink($linkPath);
            @unlink($outsideDir.'/secret.txt');
            @rmdir($outsideDir);
        }
    }

    // --- Unresolvable path asks ---

    public function test_unresolvable_path_asks(): void
    {
        $check = $this->makeCheck();

        $result = $check->evaluate('file_write', ['path' => '/nonexistent/deep/path/file.txt', 'content' => 'x']);
        $this->assertNotNull($result);
        $this->assertSame(PermissionAction::Ask, $result->action);
    }

    // --- New file inside project passes ---

    public function test_new_file_inside_project_passes(): void
    {
        $check = $this->makeCheck();

        // File doesn't exist yet but parent does
        $result = $check->evaluate('file_write', ['path' => $this->tmpDir.'/new_file.php', 'content' => 'x']);
        $this->assertNull($result);
    }

    public function test_deeply_nested_new_file_inside_project_passes(): void
    {
        $check = $this->makeCheck();

        // Neither file nor parent dirs exist, but they're under project root
        $result = $check->evaluate('file_write', ['path' => $this->tmpDir.'/deep/nested/dir/file.php', 'content' => 'x']);
        $this->assertNull($result);
    }

    // --- Temp dir and kosmokrator dir ---

    public function test_temp_dir_passes_when_in_allowed_paths(): void
    {
        $tmpDir = realpath(sys_get_temp_dir());
        $check = $this->makeCheck([$tmpDir]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'boundary_test_');
        try {
            $result = $check->evaluate('file_read', ['path' => $tmpFile]);
            $this->assertNull($result, 'Temp dir files should pass when temp dir is in allowed_paths');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_home_kosmokrator_dir_passes_when_in_allowed_paths(): void
    {
        $home = getenv('HOME') ?: '';
        if ($home === '') {
            $this->markTestSkipped('HOME not set');
        }

        $kosmoDir = $home.'/.kosmokrator';
        if (! is_dir($kosmoDir)) {
            $this->markTestSkipped('~/.kosmokrator does not exist');
        }

        $check = $this->makeCheck([realpath($kosmoDir)]);

        $result = $check->evaluate('file_read', ['path' => $kosmoDir.'/config.yaml']);
        $this->assertNull($result, 'KosmoKrator home dir files should pass when in allowed_paths');
    }

    // --- Static method ---

    public function test_path_within_boundary_static_method(): void
    {
        file_put_contents($this->tmpDir.'/test.txt', 'x');

        $this->assertTrue(ProjectBoundaryCheck::pathWithinBoundary(
            $this->tmpDir.'/test.txt',
            $this->projectRoot,
        ));

        $this->assertFalse(ProjectBoundaryCheck::pathWithinBoundary(
            '/etc/hosts',
            $this->projectRoot,
        ));
    }
}

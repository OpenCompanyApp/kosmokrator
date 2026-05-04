<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Settings;

use Kosmokrator\Settings\SettingsPaths;
use PHPUnit\Framework\TestCase;

final class SettingsPathsTest extends TestCase
{
    public function test_constructor_with_null_project_root(): void
    {
        $paths = new SettingsPaths(null);

        $this->assertNull($paths->projectWritePath());
    }

    public function test_constructor_with_project_root(): void
    {
        $paths = new SettingsPaths('/tmp/my-project');

        $this->assertSame('/tmp/my-project/.kosmo/config.yaml', $paths->projectWritePath());
    }

    public function test_global_write_path_returns_path_with_home(): void
    {
        $paths = new SettingsPaths;
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        $this->assertSame(
            $home.'/.kosmo/config.yaml',
            $paths->globalWritePath(),
        );
    }

    public function test_global_candidates_returns_canonical_then_legacy_paths(): void
    {
        $paths = new SettingsPaths;
        $candidates = $paths->globalCandidates();

        $this->assertCount(2, $candidates);
        $this->assertStringContainsString('/.kosmo/config.yaml', $candidates[0]);
        $this->assertStringContainsString('/.kosmokrator/config.yaml', $candidates[1]);
    }

    public function test_project_candidates_with_null_root_returns_empty(): void
    {
        $paths = new SettingsPaths(null);

        $this->assertSame([], $paths->projectCandidates());
    }

    public function test_project_candidates_with_root_returns_canonical_then_legacy_paths(): void
    {
        $paths = new SettingsPaths('/tmp/my-project');
        $candidates = $paths->projectCandidates();

        $this->assertCount(4, $candidates);
        $this->assertSame('/tmp/my-project/.kosmo/config.yaml', $candidates[0]);
        $this->assertSame('/tmp/my-project/.kosmo.yaml', $candidates[1]);
        $this->assertSame('/tmp/my-project/.kosmokrator/config.yaml', $candidates[2]);
        $this->assertSame('/tmp/my-project/.kosmokrator.yaml', $candidates[3]);
    }

    public function test_project_write_path_with_null_root_returns_null(): void
    {
        $paths = new SettingsPaths(null);

        $this->assertNull($paths->projectWritePath());
    }

    public function test_project_write_path_with_root_returns_correct_path(): void
    {
        $paths = new SettingsPaths('/tmp/my-project');

        $this->assertSame('/tmp/my-project/.kosmo/config.yaml', $paths->projectWritePath());
    }

    public function test_project_write_path_migrates_legacy_project_config_before_writes(): void
    {
        $projectRoot = sys_get_temp_dir().'/kosmokrator_project_test_'.uniqid();
        $legacy = $projectRoot.'/.kosmokrator.yaml';
        $canonical = $projectRoot.'/.kosmo/config.yaml';

        mkdir($projectRoot, 0777, true);
        file_put_contents($legacy, "agent:\n  mode: plan\n");

        try {
            $paths = new SettingsPaths($projectRoot);

            $this->assertSame($canonical, $paths->projectWritePath());
            $this->assertFileExists($canonical);
            $this->assertSame(file_get_contents($legacy), file_get_contents($canonical));
        } finally {
            @unlink($canonical);
            @rmdir($projectRoot.'/.kosmo');
            @unlink($legacy);
            @rmdir($projectRoot);
        }
    }

    public function test_project_read_path_prefers_migrated_canonical_copy(): void
    {
        $projectRoot = sys_get_temp_dir().'/kosmokrator_project_test_'.uniqid();
        $legacy = $projectRoot.'/.kosmokrator/config.yaml';
        $canonical = $projectRoot.'/.kosmo/config.yaml';

        mkdir(dirname($legacy), 0777, true);
        file_put_contents($legacy, "agent:\n  mode: ask\n");

        try {
            $paths = new SettingsPaths($projectRoot);

            $this->assertSame($canonical, $paths->projectReadPath());
            $this->assertFileExists($canonical);
            $this->assertSame(file_get_contents($legacy), file_get_contents($canonical));
        } finally {
            @unlink($canonical);
            @rmdir($projectRoot.'/.kosmo');
            @unlink($legacy);
            @rmdir(dirname($legacy));
            @rmdir($projectRoot);
        }
    }

    public function test_global_read_path_returns_null_when_no_config_files_exist(): void
    {
        $home = sys_get_temp_dir().'/kosmokrator_test_'.uniqid();
        mkdir($home, 0777, true);

        // Override HOME temporarily
        $originalHome = getenv('HOME');
        putenv("HOME=$home");

        try {
            $paths = new SettingsPaths;

            $this->assertNull($paths->globalReadPath());
        } finally {
            putenv($originalHome !== false ? "HOME=$originalHome" : 'HOME');
            @rmdir($home);
        }
    }

    public function test_project_read_path_returns_null_when_no_config_files_exist(): void
    {
        $projectRoot = sys_get_temp_dir().'/kosmokrator_project_test_'.uniqid();
        mkdir($projectRoot, 0777, true);

        try {
            $paths = new SettingsPaths($projectRoot);

            $this->assertNull($paths->projectReadPath());
        } finally {
            @rmdir($projectRoot);
        }
    }
}

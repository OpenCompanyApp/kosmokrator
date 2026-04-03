<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Settings;

use Kosmokrator\Settings\SettingsPaths;
use PHPUnit\Framework\TestCase;

final class SettingsPathsTest extends TestCase
{
    public function testConstructorWithNullProjectRoot(): void
    {
        $paths = new SettingsPaths(null);

        $this->assertNull($paths->projectWritePath());
    }

    public function testConstructorWithProjectRoot(): void
    {
        $paths = new SettingsPaths('/tmp/my-project');

        $this->assertSame('/tmp/my-project/.kosmokrator/config.yaml', $paths->projectWritePath());
    }

    public function testGlobalWritePathReturnsPathWithHome(): void
    {
        $paths = new SettingsPaths();
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        $this->assertSame(
            $home . '/.config/kosmokrator/config.yaml',
            $paths->globalWritePath(),
        );
    }

    public function testGlobalCandidatesReturnsTwoPaths(): void
    {
        $paths = new SettingsPaths();
        $candidates = $paths->globalCandidates();

        $this->assertCount(2, $candidates);
        $this->assertStringContainsString('/.config/kosmokrator/config.yaml', $candidates[0]);
        $this->assertStringContainsString('/.kosmokrator/config.yaml', $candidates[1]);
    }

    public function testProjectCandidatesWithNullRootReturnsEmpty(): void
    {
        $paths = new SettingsPaths(null);

        $this->assertSame([], $paths->projectCandidates());
    }

    public function testProjectCandidatesWithRootReturnsTwoPaths(): void
    {
        $paths = new SettingsPaths('/tmp/my-project');
        $candidates = $paths->projectCandidates();

        $this->assertCount(2, $candidates);
        $this->assertSame('/tmp/my-project/.kosmokrator/config.yaml', $candidates[0]);
        $this->assertSame('/tmp/my-project/.kosmokrator.yaml', $candidates[1]);
    }

    public function testProjectWritePathWithNullRootReturnsNull(): void
    {
        $paths = new SettingsPaths(null);

        $this->assertNull($paths->projectWritePath());
    }

    public function testProjectWritePathWithRootReturnsCorrectPath(): void
    {
        $paths = new SettingsPaths('/tmp/my-project');

        $this->assertSame('/tmp/my-project/.kosmokrator/config.yaml', $paths->projectWritePath());
    }

    public function testGlobalReadPathReturnsNullWhenNoConfigFilesExist(): void
    {
        $home = sys_get_temp_dir() . '/kosmokrator_test_' . uniqid();
        mkdir($home, 0777, true);

        // Override HOME temporarily
        $originalHome = getenv('HOME');
        putenv("HOME=$home");

        try {
            $paths = new SettingsPaths();

            $this->assertNull($paths->globalReadPath());
        } finally {
            putenv($originalHome !== false ? "HOME=$originalHome" : 'HOME');
            @rmdir($home);
        }
    }

    public function testProjectReadPathReturnsNullWhenNoConfigFilesExist(): void
    {
        $projectRoot = sys_get_temp_dir() . '/kosmokrator_project_test_' . uniqid();
        mkdir($projectRoot, 0777, true);

        try {
            $paths = new SettingsPaths($projectRoot);

            $this->assertNull($paths->projectReadPath());
        } finally {
            @rmdir($projectRoot);
        }
    }
}

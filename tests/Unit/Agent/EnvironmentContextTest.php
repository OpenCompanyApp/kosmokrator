<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\EnvironmentContext;
use PHPUnit\Framework\TestCase;

class EnvironmentContextTest extends TestCase
{
    public function test_gather_includes_working_directory(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('Working directory:', $context);
        $this->assertStringContainsString(getcwd(), $context);
    }

    public function test_gather_includes_platform(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('Platform:', $context);
        $this->assertStringContainsString(PHP_OS_FAMILY, $context);
    }

    public function test_gather_includes_date(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString("Today's date:", $context);
        $this->assertStringContainsString(date('Y-m-d'), $context);
    }

    public function test_gather_includes_shell(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('Shell:', $context);
    }

    public function test_gather_detects_composer_project(): void
    {
        // We're running from the kosmokrator root which has composer.json
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('PHP (Composer)', $context);
        $this->assertStringContainsString('kosmokrator', $context);
    }

    public function test_gather_includes_git_info(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('Git branch:', $context);
    }

    public function test_gather_refreshes_git_info_when_working_directory_changes(): void
    {
        $originalCwd = getcwd();
        $repo = sys_get_temp_dir().'/kosmokrator_env_repo_'.uniqid();
        $plain = sys_get_temp_dir().'/kosmokrator_env_plain_'.uniqid();
        mkdir($repo, 0755, true);
        mkdir($plain, 0755, true);

        try {
            chdir($repo);
            shell_exec('git init 2>/dev/null');
            shell_exec('git symbolic-ref HEAD refs/heads/cache-test 2>/dev/null');
            $repoContext = EnvironmentContext::gather();

            chdir($plain);
            $plainContext = EnvironmentContext::gather();

            $this->assertStringContainsString('Git branch: cache-test', $repoContext);
            $this->assertStringContainsString('Git: not a repository', $plainContext);
        } finally {
            chdir($originalCwd);
            $this->removeDir($repo);
            $this->removeDir($plain);
        }
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

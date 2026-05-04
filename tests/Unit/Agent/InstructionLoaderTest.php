<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\InstructionLoader;
use PHPUnit\Framework\TestCase;

class InstructionLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/kosmokrator_test_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_gather_returns_empty_string_when_no_files_exist(): void
    {
        // Run from a temp dir with no instruction files and no git repo
        $original = getcwd();
        chdir($this->tmpDir);

        try {
            $result = InstructionLoader::gather();
            $this->assertSame('', $result);
        } finally {
            chdir($original);
        }
    }

    public function test_gather_loads_project_kosmokrator_md(): void
    {
        // Create a git repo with a KOSMOKRATOR.md
        $this->initGitRepo($this->tmpDir);
        file_put_contents($this->tmpDir.'/KOSMOKRATOR.md', 'Use strict types everywhere.');

        $original = getcwd();
        chdir($this->tmpDir);

        try {
            $result = InstructionLoader::gather();
            $this->assertStringContainsString('# Project Instructions', $result);
            $this->assertStringContainsString('Use strict types everywhere.', $result);
        } finally {
            chdir($original);
        }
    }

    public function test_gather_loads_agents_md(): void
    {
        $this->initGitRepo($this->tmpDir);
        file_put_contents($this->tmpDir.'/AGENTS.md', 'Prefer functional style.');

        $original = getcwd();
        chdir($this->tmpDir);

        try {
            $result = InstructionLoader::gather();
            $this->assertStringContainsString('# Agent Instructions', $result);
            $this->assertStringContainsString('Prefer functional style.', $result);
        } finally {
            chdir($original);
        }
    }

    public function test_gather_loads_dotfolder_instructions(): void
    {
        $this->initGitRepo($this->tmpDir);
        mkdir($this->tmpDir.'/.kosmo', 0755);
        file_put_contents($this->tmpDir.'/.kosmo/instructions.md', 'Private project notes.');

        $original = getcwd();
        chdir($this->tmpDir);

        try {
            $result = InstructionLoader::gather();
            $this->assertStringContainsString('# Project Instructions', $result);
            $this->assertStringContainsString('Private project notes.', $result);
        } finally {
            chdir($original);
        }
    }

    public function test_gather_loads_subdirectory_override(): void
    {
        $this->initGitRepo($this->tmpDir);
        file_put_contents($this->tmpDir.'/KOSMOKRATOR.md', 'Root instructions.');

        $subDir = $this->tmpDir.'/packages/api';
        mkdir($subDir, 0755, true);
        file_put_contents($subDir.'/KOSMOKRATOR.md', 'API package instructions.');

        $original = getcwd();
        chdir($subDir);

        try {
            $result = InstructionLoader::gather();
            $this->assertStringContainsString('Root instructions.', $result);
            $this->assertStringContainsString('# Directory Instructions', $result);
            $this->assertStringContainsString('API package instructions.', $result);
        } finally {
            chdir($original);
        }
    }

    public function test_gather_skips_empty_files(): void
    {
        $this->initGitRepo($this->tmpDir);
        file_put_contents($this->tmpDir.'/KOSMOKRATOR.md', '   ');

        $original = getcwd();
        chdir($this->tmpDir);

        try {
            $result = InstructionLoader::gather();
            $this->assertSame('', $result);
        } finally {
            chdir($original);
        }
    }

    public function test_gather_preserves_priority_order(): void
    {
        $this->initGitRepo($this->tmpDir);
        file_put_contents($this->tmpDir.'/KOSMOKRATOR.md', 'Project first.');
        file_put_contents($this->tmpDir.'/AGENTS.md', 'Agents second.');

        $original = getcwd();
        chdir($this->tmpDir);

        try {
            $result = InstructionLoader::gather();
            $projectPos = strpos($result, 'Project first.');
            $agentsPos = strpos($result, 'Agents second.');
            $this->assertNotFalse($projectPos);
            $this->assertNotFalse($agentsPos);
            $this->assertLessThan($agentsPos, $projectPos, 'KOSMOKRATOR.md should appear before AGENTS.md');
        } finally {
            chdir($original);
        }
    }

    private function initGitRepo(string $dir): void
    {
        exec("cd {$dir} && git init -q 2>/dev/null");
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}

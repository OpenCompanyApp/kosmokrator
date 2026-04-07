<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration;

use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Tests\Integration\Fake\CapturingRenderer;
use Kosmokrator\Tests\Integration\Fake\RecordingLlmClient;
use Kosmokrator\Tool\Coding\ApplyPatchTool;
use Kosmokrator\Tool\Coding\BashTool;
use Kosmokrator\Tool\Coding\FileEditTool;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;
use Kosmokrator\Tool\Coding\GlobTool;
use Kosmokrator\Tool\Coding\GrepTool;
use Kosmokrator\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Base class for integration tests.
 *
 * Provides:
 * - Isolated temp directory (auto-cleaned)
 * - RecordingLlmClient (fake LLM that records all interactions)
 * - CapturingRenderer (fake UI that captures all calls for assertions)
 * - Factory helpers for AgentLoop, ToolRegistry, SessionManager
 */
abstract class IntegrationTestCase extends TestCase
{
    protected string $tmpDir;

    protected RecordingLlmClient $llm;

    protected CapturingRenderer $renderer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/kkr_integration_'.uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->llm = new RecordingLlmClient;
        $this->renderer = new CapturingRenderer;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // ── Factory helpers ────────────────────────────────────────────────

    /**
     * Create an AgentLoop with the fake LLM and renderer pre-wired.
     */
    protected function createAgentLoop(
        string $systemPrompt = 'You are a test assistant.',
        array $tools = [],
        ?SessionManager $sessionManager = null,
    ): AgentLoop {
        $loop = new AgentLoop(
            llm: $this->llm,
            ui: $this->renderer,
            log: new NullLogger,
            baseSystemPrompt: $systemPrompt,
            sessionManager: $sessionManager,
        );

        if ($tools !== []) {
            $loop->setTools($tools);
        }

        return $loop;
    }

    /**
     * Create a ToolRegistry with real tool implementations scoped to the temp dir.
     */
    protected function createToolRegistry(): ToolRegistry
    {
        $registry = new ToolRegistry;
        $registry->register(new FileReadTool($this->tmpDir));
        $registry->register(new FileWriteTool($this->tmpDir));
        $registry->register(new FileEditTool($this->tmpDir));
        $registry->register(new ApplyPatchTool);
        $registry->register(new GlobTool);
        $registry->register(new GrepTool);
        $registry->register(new BashTool);

        return $registry;
    }

    /**
     * Create a SessionManager backed by an in-memory SQLite database.
     */
    protected function createSessionManager(): SessionManager
    {
        $db = new Database(':memory:');

        return new SessionManager(
            new SessionRepository($db),
            new MessageRepository($db),
            new SettingsRepository($db),
            new MemoryRepository($db),
            new NullLogger,
        );
    }

    // ── Filesystem helpers ─────────────────────────────────────────────

    /**
     * Create a file in the temp directory with the given content.
     * Paths are relative to the temp directory root.
     */
    protected function createFile(string $relativePath, string $content): string
    {
        $fullPath = $this->tmpDir.'/'.$relativePath;
        $dir = dirname($fullPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $content);

        return $fullPath;
    }

    /**
     * Read a file from the temp directory.
     */
    protected function readFile(string $relativePath): string
    {
        return file_get_contents($this->tmpDir.'/'.$relativePath);
    }

    /**
     * Assert that a file exists in the temp directory.
     */
    protected function assertFileExistsInTmp(string $relativePath, string $message = ''): void
    {
        $this->assertFileExists($this->tmpDir.'/'.$relativePath, $message);
    }

    // ── Internal ───────────────────────────────────────────────────────

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

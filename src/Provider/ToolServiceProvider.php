<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Lua\NativeToolBridge;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\Tool\MemorySaveTool;
use Kosmokrator\Session\Tool\MemorySearchTool;
use Kosmokrator\Session\Tool\SessionReadTool;
use Kosmokrator\Session\Tool\SessionSearchTool;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Task\Tool\TaskCreateTool;
use Kosmokrator\Task\Tool\TaskGetTool;
use Kosmokrator\Task\Tool\TaskListTool;
use Kosmokrator\Task\Tool\TaskUpdateTool;
use Kosmokrator\Tool\Coding\ApplyPatchTool;
use Kosmokrator\Tool\Coding\BashTool;
use Kosmokrator\Tool\Coding\FileEditTool;
use Kosmokrator\Tool\Coding\FileReadTool;
use Kosmokrator\Tool\Coding\FileWriteTool;
use Kosmokrator\Tool\Coding\GlobTool;
use Kosmokrator\Tool\Coding\GrepTool;
use Kosmokrator\Tool\Coding\Lua\ExecuteLuaTool;
use Kosmokrator\Tool\Coding\Lua\ListDocsTool;
use Kosmokrator\Tool\Coding\Lua\ReadDocTool;
use Kosmokrator\Tool\Coding\Lua\SearchDocsTool;
use Kosmokrator\Tool\Coding\Patch\PatchApplier;
use Kosmokrator\Tool\Coding\Patch\PatchParser;
use Kosmokrator\Tool\Coding\ShellKillTool;
use Kosmokrator\Tool\Coding\ShellReadTool;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Coding\ShellStartTool;
use Kosmokrator\Tool\Coding\ShellWriteTool;
use Kosmokrator\Tool\Permission\Check\ProjectBoundaryCheck;
use Kosmokrator\Tool\Permission\GuardianEvaluator;
use Kosmokrator\Tool\Permission\PermissionConfigParser;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\Tool\Permission\SessionGrants;
use Kosmokrator\Tool\ToolRegistry;
use Lua\Sandbox;
use Psr\Log\LoggerInterface;

/**
 * Registers the tool registry with all coding, task, and memory tools,
 * plus the permission evaluator and its dependencies.
 */
class ToolServiceProvider extends ServiceProvider
{
    /** Paths outside the project root that tools may access without prompting. */
    private const DEFAULT_ALLOWED_PATHS = [
        '~/.kosmokrator',
        '/tmp',
    ];

    public function register(): void
    {
        $config = $this->container->make('config');

        $bashTimeout = $config->get('kosmokrator.tools.bash.timeout', 120);
        $shellWaitMs = (int) $config->get('kosmokrator.tools.shell.wait_ms', 100);
        $shellIdleTtl = (int) $config->get('kosmokrator.tools.shell.idle_ttl', 300);
        $this->container->singleton(TaskStore::class);
        $this->container->singleton(PatchParser::class);
        $this->container->singleton(PatchApplier::class, function () use ($config) {
            $projectRoot = InstructionLoader::gitRoot() ?? getcwd();
            $allowedPaths = $this->resolveAllowedPaths(
                $config->get('kosmokrator.tools.allowed_paths', self::DEFAULT_ALLOWED_PATHS),
            );

            return new PatchApplier(
                $config->get('kosmokrator.tools.blocked_paths', []),
                $projectRoot,
                $allowedPaths,
            );
        });
        $this->container->singleton(ShellSessionManager::class, fn () => new ShellSessionManager(
            $this->container->make(LoggerInterface::class),
            $shellWaitMs,
            $bashTimeout,
            $shellIdleTtl,
        ));

        $this->container->singleton(SessionGrants::class);
        $this->container->singleton(PermissionEvaluator::class, function () use ($config) {
            $projectRoot = InstructionLoader::gitRoot() ?? getcwd();
            $allowedPaths = $this->resolveAllowedPaths(
                $config->get('kosmokrator.tools.allowed_paths', self::DEFAULT_ALLOWED_PATHS),
            );
            $parser = new PermissionConfigParser;
            $parsed = $parser->parse($config);

            $guardian = new GuardianEvaluator($projectRoot, $parsed['guardian_safe_commands']);
            $defaultMode = PermissionMode::tryFrom($parsed['default_permission_mode']) ?? PermissionMode::Guardian;

            $evaluator = new PermissionEvaluator(
                $parsed['rules'],
                $this->container->make(SessionGrants::class),
                $parsed['blocked_paths'],
                $guardian,
                // Reference capture: $evaluator isn't assigned yet during construction,
                // but the closure is only called later during evaluate() calls.
                new ProjectBoundaryCheck(
                    $projectRoot,
                    $allowedPaths,
                    function () use (&$evaluator) {
                        return $evaluator->getPermissionMode();
                    },
                ),
            );
            $evaluator->setPermissionMode($defaultMode);

            return $evaluator;
        });

        $this->container->singleton(ToolRegistry::class, function () use ($bashTimeout, $config) {
            $projectRoot = InstructionLoader::gitRoot() ?? getcwd();
            $allowedPaths = $this->resolveAllowedPaths(
                $config->get('kosmokrator.tools.allowed_paths', self::DEFAULT_ALLOWED_PATHS),
            );
            $registry = new ToolRegistry;
            $registry->register(new FileReadTool($projectRoot, $allowedPaths));
            $registry->register(new FileWriteTool($projectRoot, $allowedPaths));
            $registry->register(new FileEditTool($projectRoot, $allowedPaths));
            $registry->register(new ApplyPatchTool(
                $this->container->make(PatchParser::class),
                $this->container->make(PatchApplier::class),
            ));
            $registry->register(new GlobTool);
            $registry->register(new GrepTool);
            $registry->register(new BashTool($bashTimeout));
            $registry->register(new ShellStartTool(
                $this->container->make(ShellSessionManager::class),
            ));
            $registry->register(new ShellWriteTool(
                $this->container->make(ShellSessionManager::class),
                $this->container->make(PermissionEvaluator::class),
            ));
            $registry->register(new ShellReadTool(
                $this->container->make(ShellSessionManager::class),
            ));
            $registry->register(new ShellKillTool(
                $this->container->make(ShellSessionManager::class),
            ));

            $taskStore = $this->container->make(TaskStore::class);
            $registry->register(new TaskCreateTool($taskStore));
            $registry->register(new TaskUpdateTool($taskStore));
            $registry->register(new TaskListTool($taskStore));
            $registry->register(new TaskGetTool($taskStore));

            $sessionManager = $this->container->make(SessionManager::class);
            $registry->register(new MemorySaveTool($sessionManager));
            $registry->register(new MemorySearchTool($sessionManager));
            $registry->register(new SessionSearchTool($sessionManager));
            $registry->register(new SessionReadTool($sessionManager));

            // Lua integration tools — only if Lua extension is available
            if (class_exists(Sandbox::class) && $this->container->bound(LuaDocService::class)) {
                $luaDocService = $this->container->make(LuaDocService::class);
                $registry->register(new ListDocsTool($luaDocService));
                $registry->register(new SearchDocsTool($luaDocService));
                $registry->register(new ReadDocTool($luaDocService));
                $registry->register(new ExecuteLuaTool(
                    $this->container->make(IntegrationRuntime::class),
                ));

                // Set lazy resolver for native tool bridge (app.tools.* in Lua)
                // Must be deferred — $registry is still being built here
                ExecuteLuaTool::setNativeBridgeResolver(fn () => new NativeToolBridge(fn () => $registry));
            }

            return $registry;
        });
    }

    /**
     * Resolve allowed_paths config entries to absolute paths.
     * Expands ~, strips trailing glob wildcards, and filters unresolvable entries.
     *
     * @param  string[]  $rawPaths
     * @return string[]
     */
    private function resolveAllowedPaths(array $rawPaths): array
    {
        $home = getenv('HOME') ?: '';
        $resolved = [];

        foreach ($rawPaths as $path) {
            // Expand ~ to home directory
            if ($home !== '' && str_starts_with($path, '~/')) {
                $path = $home.substr($path, 1);
            }

            // Strip trailing glob wildcards (e.g. ~/.kosmokrator/* → ~/.kosmokrator)
            $path = rtrim($path, '/*');

            $real = realpath($path);
            if ($real !== false) {
                $resolved[] = $real;
            }
        }

        // Always allow the system temp directory (may differ from /tmp on some platforms)
        $tmpDir = realpath(sys_get_temp_dir());
        if ($tmpDir !== false && ! in_array($tmpDir, $resolved, true)) {
            $resolved[] = $tmpDir;
        }

        return array_unique($resolved);
    }
}

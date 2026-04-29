<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Config;

use Illuminate\Container\Container;
use Kosmokrator\Kernel;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Settings\SettingsManager;

abstract class RuntimeConfigurator
{
    private ?Container $container = null;

    protected function __construct(
        protected readonly ?string $cwd = null,
        protected readonly ?string $basePath = null,
    ) {}

    protected function container(): Container
    {
        if ($this->container !== null) {
            return $this->container;
        }

        return $this->withCwd(function (): Container {
            $kernel = new Kernel($this->basePath ?? dirname(__DIR__, 3));
            $kernel->boot();

            $container = $kernel->getContainer();
            if ($this->cwd !== null) {
                if ($container->bound(SettingsManager::class)) {
                    $container->make(SettingsManager::class)->setProjectRoot($this->cwd);
                }
                if ($container->bound(McpConfigStore::class)) {
                    $container->make(McpConfigStore::class)->setProjectRoot($this->cwd);
                }
            }

            return $this->container = $container;
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function withCwd(\Closure $callback): mixed
    {
        if ($this->cwd === null || $this->cwd === '') {
            return $callback();
        }

        if (! is_dir($this->cwd)) {
            throw new \RuntimeException("Project directory does not exist: {$this->cwd}");
        }

        $previous = getcwd() ?: null;
        chdir($this->cwd);
        try {
            return $callback();
        } finally {
            if ($previous !== null && is_dir($previous)) {
                chdir($previous);
            }
        }
    }
}

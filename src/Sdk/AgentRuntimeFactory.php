<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentSession;
use Kosmokrator\Agent\AgentSessionBuilder;
use Kosmokrator\Kernel;
use Kosmokrator\Mcp\McpCatalog;
use Kosmokrator\Mcp\McpClientManager;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpServerConfig;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\UI\RendererInterface;

final class AgentRuntimeFactory
{
    private ?Container $bootedContainer = null;

    public function __construct(
        private readonly ?Container $container = null,
        private readonly ?string $basePath = null,
    ) {}

    public function container(?string $cwd = null): Container
    {
        if ($this->container !== null) {
            $this->applyProjectRoot($this->container, $cwd);

            return $this->container;
        }

        if ($this->bootedContainer !== null) {
            $this->applyProjectRoot($this->bootedContainer, $cwd);

            return $this->bootedContainer;
        }

        return $this->withCwd($cwd, function (): Container {
            $kernel = new Kernel($this->basePath ?? dirname(__DIR__, 2));
            $kernel->boot();

            $this->bootedContainer = $kernel->getContainer();
            $this->applyProjectRoot($this->bootedContainer, getcwd() ?: null);

            return $this->bootedContainer;
        });
    }

    public function runtimeContainer(AgentRunOptions $options): Container
    {
        $container = $this->container($options->cwd);
        $this->applyProgrammaticConfig($container, $options);
        $this->applyRuntimeMcpServers($container, $options);

        return $container;
    }

    public function currentContainer(?string $cwd = null): ?Container
    {
        $container = $this->container ?? $this->bootedContainer;
        if ($container !== null) {
            $this->applyProjectRoot($container, $cwd);
        }

        return $container;
    }

    public function buildHeadless(AgentRunOptions $options, RendererInterface $renderer): AgentSession
    {
        return $this->withCwd($options->cwd, function () use ($options, $renderer): AgentSession {
            $container = $this->runtimeContainer($options);

            $builder = new AgentSessionBuilder($container);

            return $builder->buildHeadless($options->outputFormat, [
                ...$options->toHeadlessBuildOptions(),
                'renderer' => $renderer,
            ]);
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function withCwd(?string $cwd, \Closure $callback): mixed
    {
        if ($cwd === null || $cwd === '') {
            return $callback();
        }

        $previous = getcwd() ?: null;
        if (! is_dir($cwd)) {
            throw new \RuntimeException("Project directory does not exist: {$cwd}");
        }

        chdir($cwd);
        try {
            return $callback();
        } finally {
            if ($previous !== null && is_dir($previous)) {
                chdir($previous);
            }
        }
    }

    private function applyProgrammaticConfig(Container $container, AgentRunOptions $options): void
    {
        $config = $container->make('config');

        foreach ($options->config as $key => $value) {
            $config->set($key, $value);
        }

        if ($options->provider !== null) {
            $config->set('kosmokrator.agent.default_provider', $options->provider);
        }

        if ($options->model !== null) {
            $config->set('kosmokrator.agent.default_model', $options->model);
        }

        if ($options->apiKey !== null && $options->provider !== null) {
            $config->set("prism.providers.{$options->provider}.api_key", $options->apiKey);
        }

        if ($options->baseUrl !== null && $options->provider !== null) {
            $config->set("prism.providers.{$options->provider}.url", $options->baseUrl);
        }
    }

    private function applyProjectRoot(Container $container, ?string $cwd): void
    {
        if ($cwd === null || $cwd === '') {
            return;
        }

        if ($container->bound(SettingsManager::class)) {
            $container->make(SettingsManager::class)->setProjectRoot($cwd);
        }

        if ($container->bound(McpConfigStore::class)) {
            $container->make(McpConfigStore::class)->setProjectRoot($cwd);
        }
    }

    private function applyRuntimeMcpServers(Container $container, AgentRunOptions $options): void
    {
        if ($options->mcpServers === [] || ! $container->bound(McpConfigStore::class)) {
            return;
        }

        $store = $container->make(McpConfigStore::class);
        if ($options->cwd !== null) {
            $store->setProjectRoot($options->cwd);
        }

        foreach ($options->mcpServers as $rawServer) {
            $name = (string) ($rawServer['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $store->addRuntimeServer(McpServerConfig::fromArray($name, $rawServer, 'sdk'));
        }

        if ($container->bound(McpClientManager::class)) {
            $container->make(McpClientManager::class)->closeAll();
        }
        if ($container->bound(McpCatalog::class)) {
            $container->make(McpCatalog::class)->clearCache();
        }
    }
}

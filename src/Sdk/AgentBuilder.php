<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Sdk\Renderer\CollectingRenderer;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\OutputFormat;
use Kosmokrator\UI\RendererInterface;
use Symfony\Component\Yaml\Yaml;

final class AgentBuilder
{
    private AgentRunOptions $options;

    private ?RendererInterface $renderer = null;

    private ?\Closure $permissionCallback = null;

    private function __construct(
        private readonly ?Container $container = null,
        private readonly ?string $basePath = null,
    ) {
        $this->options = new AgentRunOptions;
    }

    public static function create(?string $basePath = null): self
    {
        return new self(basePath: $basePath);
    }

    public static function fromContainer(Container $container): self
    {
        return new self(container: $container);
    }

    public function forProject(string $cwd): self
    {
        $this->options->cwd = $cwd;

        return $this;
    }

    public function fromKosmokratorConfig(): self
    {
        return $this;
    }

    /** @param array<string, mixed> $config */
    public function withConfig(array $config): self
    {
        foreach ($config as $key => $value) {
            $this->options->config[$key] = $value;
        }

        return $this;
    }

    public function withConfigFile(string $path): self
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Config file does not exist: {$path}");
        }

        $parsed = Yaml::parseFile($path);
        if (! is_array($parsed)) {
            throw new \RuntimeException("Config file must contain a mapping: {$path}");
        }

        return $this->withConfig($parsed);
    }

    public function withProvider(string $provider): self
    {
        $this->options->provider = $provider;

        return $this;
    }

    public function withModel(string $model): self
    {
        $this->options->model = $model;

        return $this;
    }

    public function withApiKey(string $key): self
    {
        $this->options->apiKey = $key;

        return $this;
    }

    public function withBaseUrl(string $url): self
    {
        $this->options->baseUrl = $url;

        return $this;
    }

    public function withMode(string|AgentMode $mode): self
    {
        $this->options->agentMode = $mode instanceof AgentMode ? $mode : AgentMode::from($mode);

        return $this;
    }

    public function withPermissionMode(string|PermissionMode $mode): self
    {
        $this->options->permissionMode = $mode instanceof PermissionMode ? $mode : PermissionMode::from($mode);

        return $this;
    }

    public function withYolo(): self
    {
        return $this->withPermissionMode(PermissionMode::Prometheus);
    }

    public function withMaxTurns(int $turns): self
    {
        $this->options->maxTurns = $turns;

        return $this;
    }

    public function withTimeout(int $seconds): self
    {
        $this->options->timeout = $seconds;

        return $this;
    }

    public function withSystemPrompt(string $prompt): self
    {
        $this->options->systemPrompt = $prompt;

        return $this;
    }

    public function appendSystemPrompt(string $suffix): self
    {
        $this->options->appendSystemPrompt = $suffix;

        return $this;
    }

    public function resumeSession(string $sessionId): self
    {
        $this->options->sessionId = $sessionId;

        return $this;
    }

    public function resumeLatestSession(): self
    {
        $this->options->resumeLatest = true;

        return $this;
    }

    public function withoutSessionPersistence(): self
    {
        $this->options->persistSession = false;

        return $this;
    }

    public function withOutputFormat(OutputFormat|string $format): self
    {
        $this->options->outputFormat = $format instanceof OutputFormat ? $format : OutputFormat::from($format);

        return $this;
    }

    public function withRenderer(RendererInterface $renderer): self
    {
        $this->renderer = $renderer;

        return $this;
    }

    /** @param \Closure(string, array<string, mixed>): string|bool $callback */
    public function withPermissionCallback(\Closure $callback): self
    {
        $this->permissionCallback = $callback;

        return $this;
    }

    /**
     * Add a runtime-only MCP server. This mirrors ACP runtime MCP overlays and
     * does not write to project .mcp.json.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     * @param  array<string, string>  $headers
     */
    public function withMcpServer(
        string $name,
        ?string $command = null,
        array $args = [],
        array $env = [],
        ?string $url = null,
        string $type = 'stdio',
        array $headers = [],
    ): self {
        $this->options->mcpServers[] = [
            'name' => $name,
            'type' => $type,
            'command' => $command,
            'args' => array_values(array_map('strval', $args)),
            'env' => array_map('strval', $env),
            'url' => $url,
            'headers' => array_map('strval', $headers),
        ];

        return $this;
    }

    public function build(): Agent
    {
        $renderer = $this->renderer ?? new CollectingRenderer(permissionCallback: $this->permissionCallback);

        return new Agent(
            new AgentRuntimeFactory($this->container, $this->basePath),
            clone $this->options,
            $renderer,
        );
    }
}

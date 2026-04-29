<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk;

use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Tool\Permission\PermissionMode;
use Kosmokrator\UI\OutputFormat;

final class AgentRunOptions
{
    public ?string $cwd = null;

    public ?string $provider = null;

    public ?string $model = null;

    public ?string $apiKey = null;

    public ?string $baseUrl = null;

    public ?AgentMode $agentMode = null;

    public ?PermissionMode $permissionMode = null;

    public bool $persistSession = true;

    public ?string $sessionId = null;

    public bool $resumeLatest = false;

    public ?string $systemPrompt = null;

    public ?string $appendSystemPrompt = null;

    public ?int $maxTurns = null;

    public ?int $timeout = null;

    public OutputFormat $outputFormat = OutputFormat::Text;

    public bool $cleanupShells = true;

    /** @var array<string, mixed> */
    public array $config = [];

    /** @var list<array<string, mixed>> */
    public array $mcpServers = [];

    /** @return array<string, mixed> */
    public function toHeadlessBuildOptions(): array
    {
        return [
            'model' => $this->model,
            'permission_mode' => $this->permissionMode?->value,
            'agent_mode' => $this->agentMode?->value,
            'persist_session' => $this->persistSession,
            'system_prompt' => $this->systemPrompt,
            'append_system_prompt' => $this->appendSystemPrompt,
            'max_turns' => $this->maxTurns,
            'timeout' => $this->timeout,
        ];
    }
}

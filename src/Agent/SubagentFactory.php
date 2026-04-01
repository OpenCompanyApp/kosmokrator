<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Amp\Cancellation;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Tool\Coding\SubagentTool;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\UI\NullRenderer;
use Psr\Log\LoggerInterface;

/**
 * Creates isolated child AgentLoop instances for subagents.
 *
 * Cannot clone LLM clients (readonly properties + HttpClient state),
 * so each subagent gets a fresh instance with the same configuration.
 */
class SubagentFactory
{
    public function __construct(
        private readonly ToolRegistry $rootRegistry,
        private readonly LoggerInterface $log,
        private readonly ?ModelCatalog $models,
        private readonly ?OutputTruncator $truncator,
        private readonly ?PermissionEvaluator $permissions,
        private readonly \Closure|Cancellation|null $rootCancellation,
        private readonly string $llmClientClass,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly ?int $maxTokens,
        private readonly int|float|null $temperature,
        private readonly string $provider,
    ) {}

    /**
     * Create and run a subagent, returning its final response text.
     */
    public function createAndRunAgent(AgentContext $context, string $task): string
    {
        $llm = $this->createLlmClient();
        $ui = new NullRenderer($context->cancellation ?? $this->rootCancellation);
        $systemPrompt = $this->buildSystemPrompt($context);
        $llm->setSystemPrompt($systemPrompt);

        $scopedRegistry = $this->rootRegistry->scoped($context);

        if ($context->canSpawn()) {
            $scopedRegistry->register(new SubagentTool(
                $context,
                fn (AgentContext $ctx, string $t) => $this->createAndRunAgent($ctx, $t),
            ));
        }

        $mode = match ($context->type) {
            AgentType::General => AgentMode::Edit,
            AgentType::Explore => AgentMode::Ask,
            AgentType::Plan => AgentMode::Plan,
        };

        $pruner = new ContextPruner(20_000, 10_000);

        $loop = new AgentLoop(
            llm: $llm,
            ui: $ui,
            log: $this->log,
            baseSystemPrompt: $systemPrompt,
            permissions: $this->permissions,
            models: $this->models,
            truncator: $this->truncator,
            pruner: $pruner,
            deduplicator: new ToolResultDeduplicator,
        );
        $loop->setMode($mode);
        $loop->setTools($scopedRegistry->toPrismTools());
        $loop->setAgentContext($context);

        $stats = $context->orchestrator->getStats($context->id);
        if ($stats !== null) {
            $loop->setStats($stats);
        }

        return $loop->runHeadless($task);
    }

    private function createLlmClient(): LlmClientInterface
    {
        if ($this->llmClientClass === 'async') {
            $inner = new AsyncLlmClient(
                apiKey: $this->apiKey,
                baseUrl: $this->baseUrl,
                model: $this->model,
                systemPrompt: '',
                maxTokens: $this->maxTokens,
                temperature: $this->temperature,
                provider: $this->provider,
            );
        } else {
            $inner = new PrismService(
                provider: $this->provider,
                model: $this->model,
                systemPrompt: '',
                maxTokens: $this->maxTokens,
                temperature: $this->temperature,
            );
        }

        return new RetryableLlmClient($inner, $this->log);
    }

    private function buildSystemPrompt(AgentContext $context): string
    {
        $cwd = getcwd() ?: '.';
        $env = EnvironmentContext::gather();
        $typeSuffix = $context->type->systemPromptSuffix();
        $canSpawn = $context->canSpawn()
            ? 'You can spawn sub-agents for parallel work using the `subagent` tool.'
            : 'You are at maximum depth and cannot spawn further sub-agents.';

        return <<<PROMPT
You are a KosmoKrator sub-agent operating autonomously. Your output goes back to a parent agent — not a human. Be thorough but concise.
{$typeSuffix}

# Tools

- **Read before writing** — always read a file before editing. Understand existing code before making changes.
- **Search before assuming** — use `grep` and `glob` to find existing implementations and patterns. Never assume a file or function exists — check first.
- **Use memories when available** — use `memory_search` when prior project facts or decisions may already be stored. Only General agents can write memories.
- **Batch independent calls** — when you need multiple reads, searches, or commands that don't depend on each other, make them all in one response. They execute concurrently.
- **file_edit over file_write** — prefer editing existing files. Only use file_write for new files.
- **bash for commands only** — use bash for shell commands (git, tests, builds). Use dedicated tools for file operations.

# Output

- Lead with findings, not reasoning.
- Reference code as `file_path:line_number`.
- Use markdown for structure (headers, lists, code blocks).
- No filler, no preamble, no restating the task.

# Safety

- Never introduce security vulnerabilities (injection, XSS, exposed secrets).
- Never delete files, force push, or run destructive commands without explicit instruction in the task.
- Read-only agents must not run mutative bash commands.
- Validate at system boundaries (user input, external APIs).

# Coordination

{$canSpawn}

# Return Format

When done, return a structured summary:
- **What you found or did** — specific files, line numbers, code references.
- **Key details** — the actual content the parent needs, not just "I found it."
- **Issues or concerns** — anything unexpected or worth noting.

{$env}
PROMPT;
    }
}

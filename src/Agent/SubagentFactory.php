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
use OpenCompany\PrismRelay\Relay;
use Psr\Log\LoggerInterface;

/**
 * Creates isolated child AgentLoop instances for subagents.
 *
 * Cannot clone LLM clients (readonly properties + HttpClient state),
 * so each subagent gets a fresh instance with the same configuration.
 */
class SubagentFactory
{
    /**
     * @param ToolRegistry                    $rootRegistry          Tool set inherited from the root agent
     * @param LoggerInterface                 $log                   Logger for factory lifecycle events
     * @param ModelCatalog|null               $models                LLM model catalog for compaction model selection
     * @param OutputTruncator|null            $truncator             Truncates oversized tool output
     * @param PermissionEvaluator|null        $permissions           Permission policy evaluator
     * @param Closure|Cancellation|null       $rootCancellation      Cancellation token from the root session
     * @param string                          $llmClientClass        'async' or 'prism' — determines client type
     * @param string                          $apiKey                LLM API key
     * @param string                          $baseUrl               LLM API base URL
     * @param string                          $model                 LLM model identifier
     * @param int|null                        $maxTokens             Maximum response tokens
     * @param int|float|null                  $temperature           LLM sampling temperature
     * @param string                          $provider              LLM provider name (e.g. 'openai', 'anthropic')
     * @param ContextBudget|null              $budget                Token budget for context compaction
     * @param ProtectedContextBuilder|null    $protectedContextBuilder  Builds immutable context sections
     * @param Relay|null                      $relay                 Prism relay for LLM communication
     */
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
        private readonly ?ContextBudget $budget = null,
        private readonly ?ProtectedContextBuilder $protectedContextBuilder = null,
        private readonly ?Relay $relay = null,
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
        $compactor = $this->models !== null
            ? new ContextCompactor($llm, $this->models, $this->log, 50, $this->budget)
            : null;

        $loop = new AgentLoop(
            llm: $llm,
            ui: $ui,
            log: $this->log,
            baseSystemPrompt: $systemPrompt,
            permissions: $this->permissions,
            models: $this->models,
            compactor: $compactor,
            truncator: $this->truncator,
            pruner: $pruner,
            deduplicator: new ToolResultDeduplicator,
            budget: $this->budget,
            protectedContextBuilder: $this->protectedContextBuilder,
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

    /**
     * Instantiate a fresh LLM client with the same config as the root agent.
     */
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
                relay: $this->relay,
            );
        } else {
            $inner = new PrismService(
                provider: $this->provider,
                model: $this->model,
                systemPrompt: '',
                maxTokens: $this->maxTokens,
                temperature: $this->temperature,
                relay: $this->relay,
            );
        }

        return new RetryableLlmClient($inner, $this->log);
    }

    /**
     * Build the subagent system prompt with role-specific instructions and environment context.
     */
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
- **Use apply_patch for manual edits** — when you need multi-file or multi-hunk edits, prefer `apply_patch` over repeated point edits.
- **file_edit over file_write** — prefer editing existing files. Only use file_write for new files.
- **Use shell sessions sparingly** — use `shell_start`, `shell_write`, `shell_read`, and `shell_kill` only for interactive or incremental command workflows. Finished sessions clean themselves up after the final output is drained.

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

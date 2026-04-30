<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentSession;
use Kosmokrator\Agent\Exception\MaxTurnsExceededException;
use Kosmokrator\Agent\Exception\TimeoutExceededException;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Mcp\McpClientManager;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Sdk\Event\AgentEvent;
use Kosmokrator\Sdk\Event\RunCompleted;
use Kosmokrator\Sdk\Renderer\EventRenderer;
use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\UI\RendererInterface;

final class Agent
{
    private ?AgentSession $session = null;

    public function __construct(
        private readonly AgentRuntimeFactory $factory,
        private readonly AgentRunOptions $options,
        private readonly RendererInterface $renderer,
    ) {}

    public function collect(string $prompt): AgentResult
    {
        return $this->run($prompt);
    }

    /**
     * Returns the events produced by the run. Event callbacks receive events
     * live during execution; the generator yields the collected event sequence
     * when the run has completed.
     *
     * @return \Generator<int, AgentEvent>
     */
    public function stream(string $prompt): \Generator
    {
        if (! $this->renderer instanceof EventRenderer) {
            $result = $this->run($prompt);

            foreach ($result->events as $event) {
                yield $event;
            }

            return;
        }

        $queue = new \SplQueue;
        $removeListener = $this->renderer->addEventListener(static function (AgentEvent $event) use ($queue): void {
            $queue->enqueue($event);
        });

        $future = \Amp\async(fn (): AgentResult => $this->run($prompt));

        try {
            while (! $future->isComplete() || ! $queue->isEmpty()) {
                while (! $queue->isEmpty()) {
                    yield $queue->dequeue();
                }

                if (! $future->isComplete()) {
                    \Amp\delay(0.01);
                }
            }

            $future->await();
        } finally {
            $removeListener();
        }
    }

    public function conversation(): AgentConversation
    {
        return new AgentConversation($this);
    }

    public function run(string $prompt): AgentResult
    {
        return $this->factory->withCwd($this->options->cwd, function () use ($prompt): AgentResult {
            $start = microtime(true);
            $resultText = '';
            $exitCode = 0;
            $error = null;
            $session = null;
            if ($this->renderer instanceof EventRenderer) {
                $this->renderer->reset();
            }

            try {
                $session = $this->session();
                $this->prepareSession($session);
                $this->renderer->showUserMessage($prompt);
                $resultText = $session->agentLoop->runHeadless($prompt);
                if (str_starts_with($resultText, 'Error: ')) {
                    $exitCode = 1;
                }
            } catch (MaxTurnsExceededException $e) {
                $exitCode = 2;
                $error = "Agent exceeded maximum of {$e->maxTurns} turns.";
                $this->renderer->showError($error);
                $resultText = $e->partialResult;
            } catch (TimeoutExceededException $e) {
                $exitCode = 2;
                $error = "Agent timed out after {$e->timeoutSeconds} seconds.";
                $this->renderer->showError($error);
                $resultText = $e->partialResult;
            } catch (\Throwable $e) {
                $exitCode = 1;
                $error = $e->getMessage();
                $this->renderer->showError($error);
                $resultText = 'Error: '.$error;
            } finally {
                if ($session !== null) {
                    $this->cleanup($session);
                }
            }

            $tokensIn = $session?->agentLoop->getSessionTokensIn() ?? 0;
            $tokensOut = $session?->agentLoop->getSessionTokensOut() ?? 0;
            $events = $this->renderer instanceof EventRenderer ? $this->renderer->events() : [];
            $toolCalls = $this->renderer instanceof EventRenderer ? $this->renderer->toolCallCount() : 0;
            $sessionId = $session === null ? null : $this->currentSessionId($session);
            $turns = $session?->agentLoop->getLastHeadlessTurns() ?? 0;
            $elapsed = microtime(true) - $start;

            $completed = new RunCompleted(
                $resultText,
                $sessionId,
                $tokensIn,
                $tokensOut,
                $turns,
                $toolCalls,
                $elapsed,
            );
            if ($this->renderer instanceof EventRenderer) {
                $this->renderer->emit($completed);
                $events = $this->renderer->events();
            } else {
                $events[] = $completed;
            }

            return new AgentResult(
                text: $resultText,
                sessionId: $sessionId,
                tokensIn: $tokensIn,
                tokensOut: $tokensOut,
                turns: $turns,
                toolCalls: $toolCalls,
                elapsedSeconds: $elapsed,
                events: $events,
                success: $exitCode === 0,
                exitCode: $exitCode,
                error: $error,
            );
        });
    }

    public function session(): AgentSession
    {
        if ($this->session === null) {
            $this->session = $this->factory->buildHeadless($this->options, $this->renderer);
        }

        return $this->session;
    }

    public function cancel(string $reason = 'SDK run cancelled'): void
    {
        if ($this->renderer instanceof EventRenderer) {
            $this->renderer->cancel($reason);
        }

        $this->session?->orchestrator?->cancelAll();
    }

    public function close(): void
    {
        if ($this->session !== null) {
            $this->cleanup($this->session);

            return;
        }

        $container = $this->factory->currentContainer($this->options->cwd);
        if ($container === null) {
            return;
        }

        $this->cleanupContainer($container);
    }

    public function integrations(): IntegrationClient
    {
        return new IntegrationClient($this->factory->runtimeContainer($this->options)->make(IntegrationRuntime::class));
    }

    public function mcp(): McpClient
    {
        return new McpClient($this->factory->runtimeContainer($this->options)->make(McpRuntime::class));
    }

    private function prepareSession(AgentSession $session): void
    {
        if ($session->sessionManager->currentSessionId() !== null || $session->agentLoop->history()->count() > 0) {
            return;
        }

        $resumeId = $this->options->sessionId;
        if ($resumeId === null && $this->options->resumeLatest) {
            $resumeId = $session->sessionManager->latestSession();
        }

        if ($resumeId !== null) {
            $session->sessionManager->setCurrentSession($resumeId);
            $history = $session->sessionManager->loadHistory($resumeId);
            if ($history->count() > 0) {
                $session->agentLoop->setHistory($history);
            }

            return;
        }

        if ($this->options->persistSession) {
            $modelName = $session->llm->getProvider().'/'.$session->llm->getModel();
            $session->sessionManager->createSession($modelName);
        }
    }

    private function cleanup(AgentSession $session): void
    {
        $session->orchestrator?->cancelAll();

        $container = $this->factory->currentContainer($this->options->cwd);
        if ($container !== null) {
            $this->cleanupContainer($container);
        }
    }

    private function cleanupContainer(Container $container): void
    {
        try {
            if ($container->bound(McpClientManager::class)) {
                $container->make(McpClientManager::class)->closeAll();
            }
        } catch (\Throwable) {
            // Best-effort cleanup; the agent result should still be returned.
        }

        if (! $this->options->cleanupShells) {
            return;
        }

        try {
            if ($container->bound(ShellSessionManager::class)) {
                $container->make(ShellSessionManager::class)->killAll();
            }
        } catch (\Throwable) {
            // Best-effort cleanup; the agent result should still be returned.
        }
    }

    private function currentSessionId(AgentSession $session): ?string
    {
        try {
            return $session->sessionManager->currentSessionId();
        } catch (\Throwable) {
            return null;
        }
    }
}

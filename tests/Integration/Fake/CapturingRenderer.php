<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Integration\Fake;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\Ansi\AnsiAnimation;
use Kosmokrator\UI\RendererInterface;

/**
 * Renderer that captures all interactions into arrays for assertions.
 *
 * Extends the NullRenderer pattern by recording calls instead of discarding them.
 * Supports configurable return values for interactive methods (askUser, askChoice,
 * askToolPermission) via queues.
 */
final class CapturingRenderer implements RendererInterface
{
    // ── Recorded interactions ──────────────────────────────────────────

    /** @var list<string> All streamed text chunks */
    public array $streamedChunks = [];

    /** @var list<string> Complete streamed text (concatenated after streamComplete) */
    public array $completedStreams = [];

    /** @var list<array{name: string, args: array}> Tool call headers */
    public array $toolCalls = [];

    /** @var list<array{name: string, output: string, success: bool}> Tool results */
    public array $toolResults = [];

    /** @var list<string> Error messages */
    public array $errors = [];

    /** @var list<string> Notice messages */
    public array $notices = [];

    /** @var list<string> Status display calls */
    public array $statusCalls = [];

    /** @var list<AgentPhase> Phase transitions */
    public array $phaseTransitions = [];

    /** @var list<string> Reasoning content blocks */
    public array $reasoningBlocks = [];

    /** @var list<string> Mode labels shown */
    public array $modeLabels = [];

    /** @var list<string> Permission mode labels */
    public array $permissionModes = [];

    /** @var list<string> User messages shown */
    public array $userMessages = [];

    /** @var list<string> Questions asked via askUser */
    public array $askedQuestions = [];

    /** @var list<array{question: string, choices: array}> Choice prompts shown */
    public array $askedChoices = [];

    /** @var list<array{toolName: string, args: array}> Permission prompts shown */
    public array $permissionPrompts = [];

    // ── Queued return values ───────────────────────────────────────────

    /** @var list<string> Queue of answers for askUser */
    private array $askUserQueue = [];

    /** @var list<string> Queue of answers for askChoice */
    private array $askChoiceQueue = [];

    /** @var list<string> Queue of answers for askToolPermission */
    private array $askPermissionQueue = [];

    /** @var list<string|null> Queue of queued user messages */
    private array $queuedMessages = [];

    private string $currentStream = '';

    private ?TaskStore $taskStore = null;

    // ── Queue configuration methods ────────────────────────────────────

    public function queueAskUserResponse(string $answer): self
    {
        $this->askUserQueue[] = $answer;

        return $this;
    }

    public function queueAskChoiceResponse(string $selection): self
    {
        $this->askChoiceQueue[] = $selection;

        return $this;
    }

    public function queueAskPermissionResponse(string $response): self
    {
        $this->askPermissionQueue[] = $response;

        return $this;
    }

    public function queueMessage(?string $message): self
    {
        $this->queuedMessages[] = $message;

        return $this;
    }

    // ── Assertion helpers ──────────────────────────────────────────────

    /** Get the full text of all streamed chunks concatenated. */
    public function getFullStreamedText(): string
    {
        return implode('', $this->streamedChunks);
    }

    /** Get the full text of a completed stream by index (0-based). */
    public function getCompletedStreamText(int $index = 0): string
    {
        return $this->completedStreams[$index] ?? '';
    }

    /** Check if a specific error was shown. */
    public function hasError(string $substring): bool
    {
        foreach ($this->errors as $error) {
            if (str_contains($error, $substring)) {
                return true;
            }
        }

        return false;
    }

    // ── CoreRendererInterface ──────────────────────────────────────────

    public function initialize(): void {}

    public function renderIntro(bool $animated): void {}

    public function prompt(): string
    {
        return '';
    }

    public function showUserMessage(string $text): void
    {
        $this->userMessages[] = $text;
    }

    public function setPhase(AgentPhase $phase): void
    {
        $this->phaseTransitions[] = $phase;
    }

    public function showThinking(): void {}

    public function clearThinking(): void {}

    public function showCompacting(): void {}

    public function clearCompacting(): void {}

    public function getCancellation(): ?Cancellation
    {
        return null;
    }

    public function showReasoningContent(string $content): void
    {
        $this->reasoningBlocks[] = $content;
    }

    public function streamChunk(string $text): void
    {
        $this->streamedChunks[] = $text;
        $this->currentStream .= $text;
    }

    public function streamComplete(): void
    {
        $this->completedStreams[] = $this->currentStream;
        $this->currentStream = '';
    }

    public function showError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function showNotice(string $message): void
    {
        $this->notices[] = $message;
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->modeLabels[] = $label;
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->permissionModes[] = $label;
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->statusCalls[] = compact('model', 'tokensIn', 'tokensOut', 'cost', 'maxContext');
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void {}

    public function consumeQueuedMessage(): ?string
    {
        if (count($this->queuedMessages) > 0) {
            return array_shift($this->queuedMessages);
        }

        return null;
    }

    public function setImmediateCommandHandler(?\Closure $handler): void {}

    public function teardown(): void {}

    public function showWelcome(): void {}

    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    public function refreshTaskBar(): void {}

    public function playTheogony(): void {}

    public function playPrometheus(): void {}

    public function playUnleash(): void {}

    public function playAnimation(AnsiAnimation $animation): void {}

    public function setSkillCompletions(array $completions): void {}

    // ── ToolRendererInterface ──────────────────────────────────────────

    public function showToolCall(string $name, array $args): void
    {
        $this->toolCalls[] = compact('name', 'args');
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $this->toolResults[] = compact('name', 'output', 'success');
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        $this->permissionPrompts[] = compact('toolName', 'args');

        if (count($this->askPermissionQueue) > 0) {
            return array_shift($this->askPermissionQueue);
        }

        return 'allow';
    }

    public function showAutoApproveIndicator(string $toolName): void {}

    public function showToolExecuting(string $name): void {}

    public function updateToolExecuting(string $output): void {}

    public function clearToolExecuting(): void {}

    // ── DialogRendererInterface ────────────────────────────────────────

    public function showSettings(array $currentSettings): array
    {
        return [];
    }

    public function pickSession(array $items): ?string
    {
        return null;
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        return null;
    }

    public function askUser(string $question): string
    {
        $this->askedQuestions[] = $question;

        if (count($this->askUserQueue) > 0) {
            return array_shift($this->askUserQueue);
        }

        return '';
    }

    public function askChoice(string $question, array $choices): string
    {
        $this->askedChoices[] = compact('question', 'choices');

        if (count($this->askChoiceQueue) > 0) {
            return array_shift($this->askChoiceQueue);
        }

        return 'dismissed';
    }

    // ── ConversationRendererInterface ──────────────────────────────────

    public function clearConversation(): void {}

    public function replayHistory(array $messages): void {}

    // ── SubagentRendererInterface ──────────────────────────────────────

    public function showSubagentStatus(array $stats): void {}

    public function clearSubagentStatus(): void {}

    public function showSubagentRunning(array $entries): void {}

    public function showSubagentSpawn(array $entries): void {}

    public function showSubagentBatch(array $entries): void {}

    public function refreshSubagentTree(array $tree): void {}

    public function setAgentTreeProvider(?\Closure $provider): void {}

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void {}
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Renderer;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Sdk\Event\AgentEvent;
use Kosmokrator\Sdk\Event\ErrorEvent;
use Kosmokrator\Sdk\Event\PhaseChanged;
use Kosmokrator\Sdk\Event\SubagentCompleted;
use Kosmokrator\Sdk\Event\SubagentSpawned;
use Kosmokrator\Sdk\Event\TextDelta;
use Kosmokrator\Sdk\Event\ThinkingDelta;
use Kosmokrator\Sdk\Event\ToolCallCompleted;
use Kosmokrator\Sdk\Event\ToolCallStarted;
use Kosmokrator\Sdk\Event\UsageUpdated;
use Kosmokrator\UI\NullRenderer;

/**
 * Renderer used by the embeddable SDK.
 *
 * It translates the existing RendererInterface callbacks into stable SDK
 * events while keeping execution on the same AgentLoop path as CLI headless
 * mode.
 */
class EventRenderer extends NullRenderer
{
    /** @var list<AgentEvent> */
    private array $events = [];

    private string $text = '';

    private int $toolCalls = 0;

    private int $tokensIn = 0;

    private int $tokensOut = 0;

    /** @var null|\Closure(AgentEvent): void */
    private ?\Closure $eventCallback = null;

    /** @var null|\Closure(string, array<string, mixed>): string|bool */
    private ?\Closure $permissionCallback = null;

    private DeferredCancellation $cancellation;

    /**
     * @param  null|\Closure(AgentEvent): void  $eventCallback
     * @param  null|\Closure(string, array<string, mixed>): string|bool  $permissionCallback
     */
    public function __construct(?\Closure $eventCallback = null, ?\Closure $permissionCallback = null)
    {
        parent::__construct(fn (): Cancellation => $this->cancellation->getCancellation());

        $this->eventCallback = $eventCallback;
        $this->permissionCallback = $permissionCallback;
        $this->cancellation = new DeferredCancellation;
    }

    /** @return list<AgentEvent> */
    public function events(): array
    {
        return $this->events;
    }

    public function reset(): void
    {
        $this->events = [];
        $this->text = '';
        $this->toolCalls = 0;
        $this->tokensIn = 0;
        $this->tokensOut = 0;
        if ($this->cancellation->isCancelled()) {
            $this->cancellation = new DeferredCancellation;
        }
    }

    public function text(): string
    {
        return $this->text;
    }

    public function toolCallCount(): int
    {
        return $this->toolCalls;
    }

    public function tokensIn(): int
    {
        return $this->tokensIn;
    }

    public function tokensOut(): int
    {
        return $this->tokensOut;
    }

    public function cancel(string $reason = 'SDK run cancelled'): void
    {
        if (! $this->cancellation->isCancelled()) {
            $this->cancellation->cancel(new \RuntimeException($reason));
        }
    }

    public function setPhase(AgentPhase $phase): void
    {
        $this->record(new PhaseChanged($phase->value));
    }

    public function showReasoningContent(string $content): void
    {
        if ($content !== '') {
            $this->record(new ThinkingDelta($content));
        }
    }

    public function streamChunk(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->text .= $text;
        $this->record(new TextDelta($text));
    }

    public function showToolCall(string $name, array $args): void
    {
        $this->toolCalls++;
        $this->record(new ToolCallStarted($name, $args));
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $this->record(new ToolCallCompleted($name, $output, $success));
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->tokensIn = $tokensIn;
        $this->tokensOut = $tokensOut;
        $this->record(new UsageUpdated($model, $tokensIn, $tokensOut, $cost, $maxContext));
    }

    public function showError(string $message): void
    {
        $this->record(new ErrorEvent($message));
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        if ($this->permissionCallback === null) {
            return 'allow';
        }

        $result = ($this->permissionCallback)($toolName, $args);

        if (is_bool($result)) {
            return $result ? 'allow' : 'deny';
        }

        return match ($result) {
            'allow', 'deny', 'always' => $result,
            default => 'deny',
        };
    }

    public function showSubagentSpawn(array $entries): void
    {
        $this->record(new SubagentSpawned($entries));
    }

    public function showSubagentBatch(array $entries): void
    {
        $this->record(new SubagentCompleted($entries));
    }

    public function emit(AgentEvent $event): void
    {
        $this->record($event);
    }

    protected function record(AgentEvent $event): void
    {
        $this->events[] = $event;

        if ($this->eventCallback !== null) {
            ($this->eventCallback)($event);
        }
    }
}

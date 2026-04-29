<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Renderer;

use Kosmokrator\Sdk\Event\AgentEvent;
use Kosmokrator\Sdk\Event\TextDelta;
use Kosmokrator\Sdk\Event\ToolCallCompleted;
use Kosmokrator\Sdk\Event\ToolCallStarted;

final class CallbackRenderer extends EventRenderer
{
    /**
     * @param  null|\Closure(AgentEvent): void  $onEvent
     * @param  null|\Closure(string): void  $onText
     * @param  null|\Closure(string, array<string, mixed>): void  $onToolCall
     * @param  null|\Closure(string, string, bool): void  $onToolResult
     * @param  null|\Closure(string, array<string, mixed>): (string|bool)  $onPermission
     */
    public function __construct(
        ?\Closure $onEvent = null,
        ?\Closure $onText = null,
        ?\Closure $onToolCall = null,
        ?\Closure $onToolResult = null,
        ?\Closure $onPermission = null,
    ) {
        parent::__construct(
            function (AgentEvent $event) use ($onEvent, $onText, $onToolCall, $onToolResult): void {
                $onEvent?->__invoke($event);

                if ($event instanceof TextDelta) {
                    $onText?->__invoke($event->text);
                } elseif ($event instanceof ToolCallStarted) {
                    $onToolCall?->__invoke($event->tool, $event->args);
                } elseif ($event instanceof ToolCallCompleted) {
                    $onToolResult?->__invoke($event->tool, $event->output, $event->success);
                }
            },
            $onPermission,
        );
    }
}

<?php

namespace Kosmokrator\Tests\Unit\UI;

use Amp\DeferredCancellation;
use Kosmokrator\UI\NullRenderer;
use PHPUnit\Framework\TestCase;

class NullRendererTest extends TestCase
{
    public function test_all_methods_callable(): void
    {
        $renderer = new NullRenderer;

        // These should all be no-ops without throwing
        $renderer->initialize();
        $renderer->renderIntro(false);
        $renderer->showUserMessage('test');
        $renderer->showThinking();
        $renderer->clearThinking();
        $renderer->showCompacting();
        $renderer->clearCompacting();
        $renderer->streamChunk('text');
        $renderer->streamComplete();
        $renderer->showToolCall('bash', ['command' => 'ls']);
        $renderer->showToolResult('bash', 'output', true);
        $renderer->showAutoApproveIndicator('bash');
        $renderer->showNotice('notice');
        $renderer->showMode('Edit');
        $renderer->setPermissionMode('Guardian', '#ccc');
        $renderer->clearConversation();
        $renderer->replayHistory([]);
        $renderer->showError('err');
        $renderer->showStatus('model', 100, 50, 0.01, 200000);
        $renderer->showSubagentStatus([]);
        $renderer->clearSubagentStatus();
        $renderer->teardown();

        $this->assertTrue(true); // If we got here, all methods are callable
    }

    public function test_cancellation_passes_through(): void
    {
        $deferred = new DeferredCancellation;
        $renderer = new NullRenderer($deferred->getCancellation());

        $this->assertSame($deferred->getCancellation(), $renderer->getCancellation());
    }

    public function test_cancellation_lazy_closure(): void
    {
        $deferred = new DeferredCancellation;
        $renderer = new NullRenderer(fn () => $deferred->getCancellation());

        $this->assertSame($deferred->getCancellation(), $renderer->getCancellation());
    }

    public function test_cancellation_null_when_none_provided(): void
    {
        $renderer = new NullRenderer;
        $this->assertNull($renderer->getCancellation());
    }

    public function test_permission_auto_allows(): void
    {
        $renderer = new NullRenderer;
        $this->assertSame('allow', $renderer->askToolPermission('bash', ['command' => 'rm -rf /']));
    }

    public function test_prompt_returns_empty_string(): void
    {
        $renderer = new NullRenderer;
        $this->assertSame('', $renderer->prompt());
    }

    public function test_ask_choice_returns_dismissed(): void
    {
        $renderer = new NullRenderer;
        $this->assertSame('dismissed', $renderer->askChoice('Pick one', [['label' => 'A', 'detail' => null]]));
    }

    public function test_consume_queued_message_returns_null(): void
    {
        $renderer = new NullRenderer;
        $this->assertNull($renderer->consumeQueuedMessage());
    }
}

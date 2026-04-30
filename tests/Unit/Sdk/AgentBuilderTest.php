<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Sdk;

use Kosmokrator\Kernel;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmResponse;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\Sdk\AgentBuilder;
use Kosmokrator\Sdk\Event\RunCompleted;
use Kosmokrator\Sdk\Event\ToolCallCompleted;
use Kosmokrator\Sdk\Event\ToolCallStarted;
use Kosmokrator\Sdk\Renderer\CollectingRenderer;
use Kosmokrator\Tests\Integration\Fake\RecordingLlmClient;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ToolCall;

final class AgentBuilderTest extends TestCase
{
    private string $home;

    private string $project;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/kk-sdk-home-'.bin2hex(random_bytes(4));
        $this->project = sys_get_temp_dir().'/kk-sdk-project-'.bin2hex(random_bytes(4));
        mkdir($this->home, 0755, true);
        mkdir($this->project, 0755, true);
        putenv("HOME={$this->home}");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->home);
        $this->removeDir($this->project);
    }

    public function test_collect_uses_existing_headless_runtime(): void
    {
        $llm = new RecordingLlmClient;
        $llm->queueResponse(new LlmResponse('SDK_OK', FinishReason::Stop, [], 11, 7));
        $kernel = $this->kernelWithFakeLlm($llm);

        $result = AgentBuilder::fromContainer($kernel->getContainer())
            ->forProject($this->project)
            ->withoutSessionPersistence()
            ->withMode('ask')
            ->withPermissionMode('guardian')
            ->build()
            ->collect('Reply with SDK_OK');

        $this->assertTrue($result->success);
        $this->assertSame('SDK_OK', $result->text);
        $this->assertSame(11, $result->tokensIn);
        $this->assertSame(7, $result->tokensOut);
        $this->assertSame(1, $result->turns);
        $this->assertContainsOnlyInstancesOf(RunCompleted::class, array_slice($result->events, -1));
    }

    public function test_collect_records_tool_events(): void
    {
        $llm = new RecordingLlmClient;
        $llm->queueResponse(new LlmResponse('', FinishReason::ToolCalls, [
            new ToolCall(id: 'tc_1', name: 'grep', arguments: ['pattern' => 'SDK']),
        ], 10, 5));
        $llm->queueResponse(new LlmResponse('Done.', FinishReason::Stop, [], 20, 5));

        $kernel = $this->kernelWithFakeLlm($llm);
        $result = AgentBuilder::fromContainer($kernel->getContainer())
            ->forProject($this->project)
            ->withoutSessionPersistence()
            ->build()
            ->collect('Search for SDK');

        $this->assertSame('Done.', $result->text);
        $this->assertSame(2, $result->turns);
        $this->assertNotEmpty(array_filter($result->events, fn ($event): bool => $event instanceof ToolCallStarted));
        $this->assertNotEmpty(array_filter($result->events, fn ($event): bool => $event instanceof ToolCallCompleted));
    }

    public function test_callback_renderer_receives_events_during_run(): void
    {
        $llm = new RecordingLlmClient;
        $llm->queueResponse(new LlmResponse('Callback OK', FinishReason::Stop, [], 3, 2));
        $kernel = $this->kernelWithFakeLlm($llm);
        $events = [];

        $renderer = new CollectingRenderer(eventCallback: function ($event) use (&$events): void {
            $events[] = $event;
        });

        AgentBuilder::fromContainer($kernel->getContainer())
            ->forProject($this->project)
            ->withoutSessionPersistence()
            ->withRenderer($renderer)
            ->build()
            ->collect('Run callback');

        $this->assertNotEmpty($events);
    }

    public function test_collecting_renderer_reset_refreshes_cancelled_token(): void
    {
        $renderer = new CollectingRenderer;

        $renderer->cancel('stop');
        $this->assertTrue($this->cancellationWasRequested($renderer));

        $renderer->reset();
        $this->assertFalse($this->cancellationWasRequested($renderer));
    }

    public function test_mcp_client_uses_project_root_without_agent_run(): void
    {
        $server = dirname(__DIR__, 2).'/fixtures/mcp/fake_stdio_server.php';
        file_put_contents($this->project.'/.mcp.json', json_encode([
            'mcpServers' => [
                'fake' => ['command' => 'php', 'args' => [$server]],
            ],
        ], JSON_PRETTY_PRINT));

        $agent = AgentBuilder::create()
            ->forProject($this->project)
            ->build();

        $result = $agent->mcp()->call('fake.echo', ['message' => 'project-root'], force: true);

        $this->assertTrue($result['success']);
        $this->assertSame('project-root', $result['data']);
        $agent->close();
    }

    public function test_mcp_client_uses_runtime_server_overlay_without_agent_run(): void
    {
        $server = dirname(__DIR__, 2).'/fixtures/mcp/fake_stdio_server.php';

        $agent = AgentBuilder::create()
            ->forProject($this->project)
            ->withMcpServer('runtime_fake', 'php', [$server])
            ->build();

        $result = $agent->mcp()->call('runtime_fake.echo', ['message' => 'runtime-overlay'], force: true);

        $this->assertTrue($result['success']);
        $this->assertSame('runtime-overlay', $result['data']);
        $agent->close();
    }

    private function kernelWithFakeLlm(?RecordingLlmClient $llm = null): Kernel
    {
        $llm ??= new RecordingLlmClient;
        $kernel = new Kernel(dirname(__DIR__, 3));
        $kernel->boot();
        $container = $kernel->getContainer();
        $container->make('config')->set('kosmo.agent.default_provider', 'ollama');
        $container->make('config')->set('kosmo.agent.default_model', 'test-model');
        $container->instance(AsyncLlmClient::class, $llm);
        $container->instance(PrismService::class, $llm);

        return $kernel;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }

    private function cancellationWasRequested(CollectingRenderer $renderer): bool
    {
        try {
            $renderer->getCancellation()?->throwIfRequested();

            return false;
        } catch (\Throwable) {
            return true;
        }
    }
}

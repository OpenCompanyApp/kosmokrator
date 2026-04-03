<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentSession;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class AgentSessionTest extends TestCase
{
    private UIManager $ui;
    private AgentLoop $agentLoop;
    private LlmClientInterface $llm;
    private PermissionEvaluator $permissions;
    private SessionManager $sessionManager;

    protected function setUp(): void
    {
        $this->ui = $this->createStub(UIManager::class);
        $this->agentLoop = $this->createStub(AgentLoop::class);
        $this->llm = $this->createStub(LlmClientInterface::class);
        $this->permissions = $this->createStub(PermissionEvaluator::class);
        $this->sessionManager = $this->createStub(SessionManager::class);
    }

    public function test_constructor_assigns_all_properties(): void
    {
        $orchestrator = $this->createStub(SubagentOrchestrator::class);

        $session = new AgentSession(
            $this->ui,
            $this->agentLoop,
            $this->llm,
            $this->permissions,
            $this->sessionManager,
            $orchestrator,
        );

        $this->assertSame($this->ui, $session->ui);
        $this->assertSame($this->agentLoop, $session->agentLoop);
        $this->assertSame($this->llm, $session->llm);
        $this->assertSame($this->permissions, $session->permissions);
        $this->assertSame($this->sessionManager, $session->sessionManager);
        $this->assertSame($orchestrator, $session->orchestrator);
    }

    public function test_properties_are_accessible(): void
    {
        $orchestrator = $this->createStub(SubagentOrchestrator::class);

        $session = new AgentSession(
            $this->ui,
            $this->agentLoop,
            $this->llm,
            $this->permissions,
            $this->sessionManager,
            $orchestrator,
        );

        $this->assertInstanceOf(UIManager::class, $session->ui);
        $this->assertInstanceOf(AgentLoop::class, $session->agentLoop);
        $this->assertInstanceOf(LlmClientInterface::class, $session->llm);
        $this->assertInstanceOf(PermissionEvaluator::class, $session->permissions);
        $this->assertInstanceOf(SessionManager::class, $session->sessionManager);
        $this->assertInstanceOf(SubagentOrchestrator::class, $session->orchestrator);
    }

    public function test_orchestrator_can_be_null(): void
    {
        $session = new AgentSession(
            $this->ui,
            $this->agentLoop,
            $this->llm,
            $this->permissions,
            $this->sessionManager,
            null,
        );

        $this->assertNull($session->orchestrator);
    }

    public function test_orchestrator_can_be_mock(): void
    {
        $orchestrator = $this->createStub(SubagentOrchestrator::class);

        $session = new AgentSession(
            $this->ui,
            $this->agentLoop,
            $this->llm,
            $this->permissions,
            $this->sessionManager,
            $orchestrator,
        );

        $this->assertNotNull($session->orchestrator);
        $this->assertSame($orchestrator, $session->orchestrator);
    }

    public function test_properties_are_readonly(): void
    {
        $session = new AgentSession(
            $this->ui,
            $this->agentLoop,
            $this->llm,
            $this->permissions,
            $this->sessionManager,
            null,
        );

        $reflection = new \ReflectionClass($session);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" should be readonly.', $property->getName()),
            );
        }

        // Verify values are set correctly via reflection (since readonly prevents re-assignment)
        $this->assertSame($this->ui, $reflection->getProperty('ui')->getValue($session));
        $this->assertSame($this->agentLoop, $reflection->getProperty('agentLoop')->getValue($session));
        $this->assertSame($this->llm, $reflection->getProperty('llm')->getValue($session));
        $this->assertSame($this->permissions, $reflection->getProperty('permissions')->getValue($session));
        $this->assertSame($this->sessionManager, $reflection->getProperty('sessionManager')->getValue($session));
        $this->assertNull($reflection->getProperty('orchestrator')->getValue($session));
    }
}

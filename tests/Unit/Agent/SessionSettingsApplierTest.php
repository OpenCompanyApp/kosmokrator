<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\SessionSettingsApplier;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
use PHPUnit\Framework\TestCase;

final class SessionSettingsApplierTest extends TestCase
{
    public function test_apply_prefers_canonical_session_settings(): void
    {
        $sessionManager = $this->createStub(SessionManager::class);
        $sessionManager->method('getSetting')->willReturnMap([
            ['agent.temperature', '0.4'],
            ['agent.max_tokens', '1234'],
            ['agent.reasoning_effort', 'medium'],
            ['tools.default_permission_mode', 'prometheus'],
            ['agent.max_retries', null],
            ['max_retries', null],
        ]);

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->once())->method('setTemperature')->with(0.4);
        $llm->expects($this->once())->method('setMaxTokens')->with(1234);
        $llm->expects($this->once())->method('setReasoningEffort')->with('medium');

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->expects($this->once())->method('setPermissionMode')->with(PermissionMode::Prometheus);

        (new SessionSettingsApplier($sessionManager, ['agent' => ['max_retries' => 0]]))->apply($llm, $permissions);

        $this->assertTrue(true);
    }

    public function test_apply_falls_back_to_legacy_permission_mode_key(): void
    {
        $sessionManager = $this->createStub(SessionManager::class);
        $sessionManager->method('getSetting')->willReturnMap([
            ['agent.temperature', null],
            ['temperature', null],
            ['agent.max_tokens', null],
            ['max_tokens', null],
            ['agent.reasoning_effort', null],
            ['reasoning_effort', null],
            ['tools.default_permission_mode', null],
            ['permission_mode', 'argus'],
            ['agent.max_retries', null],
            ['max_retries', null],
        ]);

        $llm = $this->createStub(LlmClientInterface::class);

        $permissions = $this->createMock(PermissionEvaluator::class);
        $permissions->expects($this->once())->method('setPermissionMode')->with(PermissionMode::Argus);

        (new SessionSettingsApplier($sessionManager, ['agent' => ['max_retries' => 0]]))->apply($llm, $permissions);

        $this->assertTrue(true);
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;

/**
 * Restores persisted session settings (temperature, max_tokens, permission_mode,
 * max_retries) onto the LLM client and permission evaluator.
 */
final class SessionSettingsApplier
{
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly array $config,
    ) {}

    /**
     * Apply persisted settings from session storage to LLM and permissions.
     */
    public function apply(LlmClientInterface $llm, PermissionEvaluator $permissions): void
    {
        $temp = $this->sessionManager->getSetting('temperature');
        if ($temp !== null) {
            $llm->setTemperature((float) $temp);
        }

        $maxTokens = $this->sessionManager->getSetting('max_tokens');
        if ($maxTokens !== null) {
            $llm->setMaxTokens((int) $maxTokens);
        }

        $permMode = $this->sessionManager->getSetting('permission_mode');
        if ($permMode !== null) {
            $mode = PermissionMode::tryFrom($permMode);
            if ($mode !== null) {
                $permissions->setPermissionMode($mode);
            }
        } else {
            // Backward compat: old auto_approve setting
            $autoApprove = $this->sessionManager->getSetting('auto_approve');
            if ($autoApprove === 'on') {
                $permissions->setPermissionMode(PermissionMode::Prometheus);
            }
        }

        // Wire configurable max retries
        if ($llm instanceof RetryableLlmClient) {
            $maxRetries = (int) ($this->sessionManager->getSetting('max_retries')
                ?? $this->config['agent']['max_retries'] ?? 0);
            $llm->setMaxAttempts($maxRetries);
        }
    }
}

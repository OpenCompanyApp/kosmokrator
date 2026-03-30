<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;
use Kosmokrator\Tool\Permission\PermissionMode;

class SettingsCommand implements SlashCommand
{
    public function name(): string
    {
        return '/settings';
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return 'Open settings panel';
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $memoriesEnabled = $ctx->sessionManager->getSetting('memories') ?? 'on';
        $autoCompact = $ctx->sessionManager->getSetting('auto_compact') ?? 'on';
        $currentProvider = $ctx->llm->getProvider();

        $currentSettings = [
            'mode' => $ctx->agentLoop->getMode()->value,
            'permission_mode' => $ctx->permissions->getPermissionMode()->value,
            'memories' => $memoriesEnabled,
            'auto_compact' => $autoCompact,
            'compact_threshold' => (string) ($ctx->agentLoop->getCompactor()?->getCompactThresholdPercent() ?? 60),
            'prune_protect' => (string) ($ctx->agentLoop->getPruner()?->getProtectTokens() ?? 40000),
            'prune_min_savings' => (string) ($ctx->agentLoop->getPruner()?->getMinSavings() ?? 20000),
            'temperature' => (string) ($ctx->llm->getTemperature() ?? 0.0),
            'max_tokens' => (string) ($ctx->llm->getMaxTokens() ?? ''),
            'provider' => $currentProvider,
            'model' => $ctx->llm->getModel(),
            'api_key' => self::maskKey($ctx->config->get("prism.providers.{$currentProvider}.api_key", '')),
        ];

        $changes = $ctx->ui->showSettings($currentSettings);

        foreach ($changes as $id => $value) {
            match ($id) {
                'mode' => (function () use ($ctx, $value) {
                    $mode = AgentMode::from($value);
                    $ctx->agentLoop->setMode($mode);
                    $ctx->ui->showMode($mode->label(), $mode->color());
                    $ctx->sessionManager->setSetting('mode', $value);
                })(),
                'permission_mode' => (function () use ($ctx, $value) {
                    $mode = PermissionMode::from($value);
                    $ctx->permissions->setPermissionMode($mode);
                    $ctx->ui->setPermissionMode($mode->statusLabel(), $mode->color());
                    $ctx->sessionManager->setSetting('permission_mode', $value);
                })(),
                'memories' => $ctx->sessionManager->setSetting('memories', $value),
                'auto_compact' => $ctx->sessionManager->setSetting('auto_compact', $value),
                'compact_threshold' => (function () use ($ctx, $value) {
                    $ctx->agentLoop->getCompactor()?->setCompactThresholdPercent((int) $value);
                    $ctx->sessionManager->setSetting('compact_threshold', $value);
                })(),
                'prune_protect' => (function () use ($ctx, $value) {
                    $ctx->agentLoop->getPruner()?->setProtectTokens((int) $value);
                    $ctx->sessionManager->setSetting('prune_protect', $value);
                })(),
                'prune_min_savings' => (function () use ($ctx, $value) {
                    $ctx->agentLoop->getPruner()?->setMinSavings((int) $value);
                    $ctx->sessionManager->setSetting('prune_min_savings', $value);
                })(),
                'temperature' => (function () use ($ctx, $value) {
                    $ctx->llm->setTemperature((float) $value);
                    $ctx->sessionManager->setSetting('temperature', $value);
                })(),
                'max_tokens' => (function () use ($ctx, $value) {
                    $tokens = $value !== '' ? (int) $value : null;
                    $ctx->llm->setMaxTokens($tokens);
                    $ctx->sessionManager->setSetting('max_tokens', $value);
                })(),
                'provider' => (function () use ($ctx, $value) {
                    $ctx->llm->setProvider($value);
                    if (is_callable([$ctx->llm, 'setBaseUrl'])) {
                        $ctx->llm->setBaseUrl(rtrim($ctx->config->get("prism.providers.{$value}.url", ''), '/'));
                    }
                    $key = $ctx->settings->get('global', "provider.{$value}.api_key")
                        ?? $ctx->config->get("prism.providers.{$value}.api_key", '');
                    if ($key !== '' && is_callable([$ctx->llm, 'setApiKey'])) {
                        $ctx->llm->setApiKey($key);
                    }
                    $ctx->settings->set('global', 'agent.default_provider', $value);
                })(),
                'model' => (function () use ($ctx, $value) {
                    $ctx->llm->setModel($value);
                    $ctx->settings->set('global', 'agent.default_model', $value);
                })(),
                'api_key' => (function () use ($ctx, $value, $currentProvider, &$changes) {
                    if ($value !== '') {
                        $provider = $changes['provider'] ?? $currentProvider;
                        if (is_callable([$ctx->llm, 'setApiKey'])) {
                            $ctx->llm->setApiKey($value);
                        }
                        $ctx->settings->set('global', "provider.{$provider}.api_key", $value);
                    }
                })(),
                default => null,
            };
        }

        if ($changes !== []) {
            $ctx->ui->showNotice('Settings updated: ' . implode(', ', array_keys($changes)));
        }

        return SlashCommandResult::continue();
    }

    private static function maskKey(string $key): string
    {
        if ($key === '') { return '(not set)'; }
        if (strlen($key) < 12) { return '***'; }

        return substr($key, 0, 8) . '...' . substr($key, -4);
    }
}

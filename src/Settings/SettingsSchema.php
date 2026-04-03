<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

final class SettingsSchema
{
    /** @var array<string, SettingDefinition>|null */
    private static ?array $definitions = null;

    /** @var array<string, string>|null */
    private static ?array $aliases = null;

    /**
     * @return array<string, SettingDefinition>
     */
    public function definitions(): array
    {
        return self::$definitions ??= $this->buildDefinitions();
    }

    public function definition(string $id): ?SettingDefinition
    {
        $canonical = $this->canonicalId($id);

        return $this->definitions()[$canonical] ?? null;
    }

    public function canonicalId(string $id): string
    {
        return self::$aliases[$id] ?? $id;
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return [
            'general',
            'provider_model',
            'auth',
            'context_memory',
            'agent',
            'permissions',
            'subagents',
            'advanced',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function categoryLabels(): array
    {
        return [
            'general' => 'General',
            'provider_model' => 'Provider & Model',
            'auth' => 'Auth',
            'context_memory' => 'Context & Memory',
            'agent' => 'Agent',
            'permissions' => 'Permissions',
            'subagents' => 'Subagents',
            'advanced' => 'Advanced',
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function definitionsForCategory(string $category): array
    {
        return array_values(array_filter(
            $this->definitions(),
            static fn (SettingDefinition $definition): bool => $definition->category === $category,
        ));
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private function buildDefinitions(): array
    {
        self::$aliases = [
            'mode' => 'agent.mode',
            'permission_mode' => 'tools.default_permission_mode',
            'memories' => 'context.memories',
            'auto_compact' => 'context.auto_compact',
            'compact_threshold' => 'context.compact_threshold',
            'context_reserve_output_tokens' => 'context.reserve_output_tokens',
            'context_warning_buffer_tokens' => 'context.warning_buffer_tokens',
            'context_auto_compact_buffer_tokens' => 'context.auto_compact_buffer_tokens',
            'context_blocking_buffer_tokens' => 'context.blocking_buffer_tokens',
            'prune_protect' => 'context.prune_protect',
            'prune_min_savings' => 'context.prune_min_savings',
            'temperature' => 'agent.temperature',
            'max_tokens' => 'agent.max_tokens',
            'max_retries' => 'agent.max_retries',
            'subagent_max_depth' => 'agent.subagent_max_depth',
            'subagent_concurrency' => 'agent.subagent_concurrency',
            'subagent_max_retries' => 'agent.subagent_max_retries',
        ];

        $definitions = [
            new SettingDefinition(
                id: 'ui.renderer',
                path: 'kosmokrator.ui.renderer',
                label: 'Renderer',
                description: 'Preferred renderer for KosmoKrator sessions.',
                category: 'general',
                type: 'choice',
                options: ['auto', 'tui', 'ansi'],
                effect: 'next_session',
                default: 'auto',
            ),
            new SettingDefinition(
                id: 'ui.theme',
                path: 'kosmokrator.ui.theme',
                label: 'Theme',
                description: 'Terminal theme preset.',
                category: 'general',
                type: 'choice',
                options: ['default'],
                effect: 'next_session',
                default: 'default',
            ),
            new SettingDefinition(
                id: 'ui.intro_animated',
                path: 'kosmokrator.ui.intro_animated',
                label: 'Intro animation',
                description: 'Play the startup animation before opening the REPL.',
                category: 'general',
                type: 'toggle',
                options: ['on', 'off'],
                effect: 'next_session',
                default: 'on',
            ),
            new SettingDefinition(
                id: 'agent.default_provider',
                path: 'kosmokrator.agent.default_provider',
                label: 'Default provider',
                description: 'Default provider used when a session starts.',
                category: 'provider_model',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: 'z',
            ),
            new SettingDefinition(
                id: 'agent.default_model',
                path: 'kosmokrator.agent.default_model',
                label: 'Default model',
                description: 'Default model used when a session starts.',
                category: 'provider_model',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: 'GLM-5.1',
            ),
            new SettingDefinition(
                id: 'agent.mode',
                path: 'kosmokrator.agent.mode',
                label: 'Default mode',
                description: 'Starting mode for interactive sessions.',
                category: 'agent',
                type: 'choice',
                options: ['edit', 'plan', 'ask'],
                effect: 'applies_now',
                default: 'edit',
            ),
            new SettingDefinition(
                id: 'tools.default_permission_mode',
                path: 'kosmokrator.tools.default_permission_mode',
                label: 'Permission mode',
                description: 'Default permission policy for tool calls.',
                category: 'permissions',
                type: 'choice',
                options: ['guardian', 'argus', 'prometheus'],
                effect: 'applies_now',
                default: 'guardian',
            ),
            new SettingDefinition(
                id: 'context.memories',
                path: 'kosmokrator.context.memories',
                label: 'Memories',
                description: 'Enable memory recall and persistence features.',
                category: 'context_memory',
                type: 'toggle',
                options: ['on', 'off'],
                effect: 'next_turn',
                default: 'on',
            ),
            new SettingDefinition(
                id: 'context.auto_compact',
                path: 'kosmokrator.context.auto_compact',
                label: 'Auto compact',
                description: 'Compact context automatically before hitting the model limit.',
                category: 'context_memory',
                type: 'toggle',
                options: ['on', 'off'],
                effect: 'next_turn',
                default: 'on',
            ),
            new SettingDefinition(
                id: 'context.compact_threshold',
                path: 'kosmokrator.context.compact_threshold',
                label: 'Compact threshold',
                description: 'Legacy threshold percentage for compaction fallback.',
                category: 'context_memory',
                type: 'number',
                effect: 'next_turn',
                default: 60,
            ),
            new SettingDefinition(
                id: 'context.reserve_output_tokens',
                path: 'kosmokrator.context.reserve_output_tokens',
                label: 'Reserved output tokens',
                description: 'Headroom reserved for the assistant response.',
                category: 'context_memory',
                type: 'number',
                effect: 'next_turn',
                default: 16000,
            ),
            new SettingDefinition(
                id: 'context.warning_buffer_tokens',
                path: 'kosmokrator.context.warning_buffer_tokens',
                label: 'Warning buffer',
                description: 'When remaining input budget drops below this, show warnings.',
                category: 'context_memory',
                type: 'number',
                effect: 'next_turn',
                default: 24000,
            ),
            new SettingDefinition(
                id: 'context.auto_compact_buffer_tokens',
                path: 'kosmokrator.context.auto_compact_buffer_tokens',
                label: 'Auto compact buffer',
                description: 'When remaining input budget drops below this, auto compact.',
                category: 'context_memory',
                type: 'number',
                effect: 'next_turn',
                default: 12000,
            ),
            new SettingDefinition(
                id: 'context.blocking_buffer_tokens',
                path: 'kosmokrator.context.blocking_buffer_tokens',
                label: 'Blocking buffer',
                description: 'Hard stop buffer to prevent overrunning the model context window.',
                category: 'context_memory',
                type: 'number',
                effect: 'next_turn',
                default: 3000,
            ),
            new SettingDefinition(
                id: 'context.prune_protect',
                path: 'kosmokrator.context.prune_protect',
                label: 'Prune protect',
                description: 'Recent tool-result tokens protected from micro pruning.',
                category: 'context_memory',
                type: 'number',
                effect: 'next_turn',
                default: 40000,
            ),
            new SettingDefinition(
                id: 'context.prune_min_savings',
                path: 'kosmokrator.context.prune_min_savings',
                label: 'Prune minimum savings',
                description: 'Minimum savings required before a prune pass is accepted.',
                category: 'context_memory',
                type: 'number',
                effect: 'next_turn',
                default: 20000,
            ),
            new SettingDefinition(
                id: 'agent.temperature',
                path: 'kosmokrator.agent.temperature',
                label: 'Temperature',
                description: 'Sampling temperature for supported models.',
                category: 'agent',
                type: 'number',
                effect: 'applies_now',
                default: 0.0,
            ),
            new SettingDefinition(
                id: 'agent.max_tokens',
                path: 'kosmokrator.agent.max_tokens',
                label: 'Max output tokens',
                description: 'Override the model default output token limit.',
                category: 'agent',
                type: 'number',
                effect: 'applies_now',
                default: null,
            ),
            new SettingDefinition(
                id: 'agent.max_retries',
                path: 'kosmokrator.agent.max_retries',
                label: 'Max retries',
                description: 'Retry limit for transient provider failures.',
                category: 'agent',
                type: 'number',
                effect: 'applies_now',
                default: 0,
            ),
            new SettingDefinition(
                id: 'agent.subagent_max_depth',
                path: 'kosmokrator.agent.subagent_max_depth',
                label: 'Subagent max depth',
                description: 'Maximum depth for spawned agent trees.',
                category: 'subagents',
                type: 'number',
                effect: 'next_session',
                default: 3,
            ),
            new SettingDefinition(
                id: 'agent.subagent_concurrency',
                path: 'kosmokrator.agent.subagent_concurrency',
                label: 'Subagent concurrency',
                description: 'Maximum concurrent subagents.',
                category: 'subagents',
                type: 'number',
                effect: 'next_session',
                default: 10,
            ),
            new SettingDefinition(
                id: 'agent.subagent_max_retries',
                path: 'kosmokrator.agent.subagent_max_retries',
                label: 'Subagent retries',
                description: 'Retry limit for transient subagent failures.',
                category: 'subagents',
                type: 'number',
                effect: 'next_session',
                default: 2,
            ),
        ];

        return array_reduce(
            $definitions,
            static function (array $carry, SettingDefinition $definition): array {
                $carry[$definition->id] = $definition;

                return $carry;
            },
            [],
        );
    }
}

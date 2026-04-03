<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

/**
 * Central registry of all known setting definitions and their aliases.
 *
 * Provides the schema consumed by SettingsManager for validation, categorisation,
 * and dot-path resolution. Definitions are built once and cached statically.
 */
final class SettingsSchema
{
    /** @var array<string, SettingDefinition>|null Cached definitions keyed by canonical ID */
    private static ?array $definitions = null;

    /** @var array<string, string>|null Alias map: short name → canonical dot-path ID */
    private static ?array $aliases = null;

    /**
     * @return array<string, SettingDefinition> All registered definitions keyed by ID
     */
    public function definitions(): array
    {
        return self::$definitions ??= $this->buildDefinitions();
    }

    /**
     * @param  string  $id  Setting identifier (canonical or alias)
     * @return SettingDefinition|null The matching definition, or null if unknown
     */
    public function definition(string $id): ?SettingDefinition
    {
        $canonical = $this->canonicalId($id);

        return $this->definitions()[$canonical] ?? null;
    }

    /**
     * @param  string  $id  A setting ID, possibly an alias
     * @return string The canonical dot-path ID
     */
    public function canonicalId(string $id): string
    {
        return self::$aliases[$id] ?? $id;
    }

    /**
     * @return list<string> Category identifiers in display order
     */
    public function categories(): array
    {
        return [
            'general',
            'models',
            'provider_setup',
            'auth',
            'context_memory',
            'agent',
            'permissions',
            'subagents',
            'advanced',
            'audio',
        ];
    }

    /**
     * @return array<string, string> Category ID → human-readable label
     */
    public function categoryLabels(): array
    {
        return [
            'general' => 'General',
            'models' => 'Models',
            'provider_setup' => 'Provider Setup',
            'auth' => 'Auth',
            'context_memory' => 'Context & Memory',
            'agent' => 'Agent',
            'permissions' => 'Permissions',
            'subagents' => 'Subagents',
            'advanced' => 'Advanced',
            'audio' => 'Audio',
        ];
    }

    /**
     * @param  string  $category  Category identifier to filter by
     * @return list<SettingDefinition> Definitions belonging to the given category
     */
    public function definitionsForCategory(string $category): array
    {
        return array_values(array_filter(
            $this->definitions(),
            static fn (SettingDefinition $definition): bool => $definition->category === $category,
        ));
    }

    /** Builds and caches the full definition list and alias map. */
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
            'subagent_idle_watchdog_seconds' => 'agent.subagent_idle_watchdog_seconds',
            'completion_sound' => 'audio.completion_sound',
            'soundfont' => 'audio.soundfont',
            'sound_llm_timeout' => 'audio.llm_timeout',
            'sound_max_duration' => 'audio.max_duration',
            'sound_max_retries' => 'audio.max_retries',
            'subagent_provider' => 'agent.subagent_provider',
            'subagent_model' => 'agent.subagent_model',
            'subagent_depth2_provider' => 'agent.subagent_depth2_provider',
            'subagent_depth2_model' => 'agent.subagent_depth2_model',
            'audio_provider' => 'agent.audio_provider',
            'audio_model' => 'agent.audio_model',
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
                category: 'models',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: 'z',
            ),
            new SettingDefinition(
                id: 'agent.default_model',
                path: 'kosmokrator.agent.default_model',
                label: 'Default model',
                description: 'Default model used when a session starts.',
                category: 'models',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: 'GLM-5.1',
            ),
            new SettingDefinition(
                id: 'agent.subagent_provider',
                path: 'kosmokrator.agent.subagent_provider',
                label: 'Subagent provider',
                description: 'Provider for depth-1 subagents. Leave empty to inherit the main agent provider.',
                category: 'subagents',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: null,
            ),
            new SettingDefinition(
                id: 'agent.subagent_model',
                path: 'kosmokrator.agent.subagent_model',
                label: 'Subagent model',
                description: 'Model for depth-1 subagents. Leave empty to inherit the main agent model.',
                category: 'subagents',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: null,
            ),
            new SettingDefinition(
                id: 'agent.subagent_depth2_provider',
                path: 'kosmokrator.agent.subagent_depth2_provider',
                label: 'Sub-subagent provider',
                description: 'Provider for depth-2+ subagents. Falls back to subagent provider, then main agent provider.',
                category: 'subagents',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: null,
            ),
            new SettingDefinition(
                id: 'agent.subagent_depth2_model',
                path: 'kosmokrator.agent.subagent_depth2_model',
                label: 'Sub-subagent model',
                description: 'Model for depth-2+ subagents. Falls back to subagent model, then main agent model.',
                category: 'subagents',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: null,
            ),
            new SettingDefinition(
                id: 'agent.audio_provider',
                path: 'kosmokrator.agent.audio_provider',
                label: 'Audio provider',
                description: 'Provider for completion sound music composition. Leave empty to inherit the main agent provider.',
                category: 'audio',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: null,
            ),
            new SettingDefinition(
                id: 'agent.audio_model',
                path: 'kosmokrator.agent.audio_model',
                label: 'Audio model',
                description: 'Model for completion sound music composition. Leave empty to inherit the main agent model.',
                category: 'audio',
                type: 'dynamic_choice',
                effect: 'next_session',
                default: null,
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
            new SettingDefinition(
                id: 'agent.subagent_idle_watchdog_seconds',
                path: 'kosmokrator.agent.subagent_idle_watchdog_seconds',
                label: 'Idle watchdog seconds',
                description: 'Cancel only when a running subagent stops making progress updates for too long. Set 0 to disable.',
                category: 'subagents',
                type: 'number',
                effect: 'next_session',
                default: 900,
            ),
            new SettingDefinition(
                id: 'audio.completion_sound',
                path: 'kosmokrator.audio.completion_sound',
                label: 'Play completion sound',
                description: 'Compose and play a short musical piece after each agent response. The music reflects what happened.',
                category: 'audio',
                type: 'toggle',
                options: ['on', 'off'],
                effect: 'applies_now',
                default: 'off',
            ),
            new SettingDefinition(
                id: 'audio.soundfont',
                path: 'kosmokrator.audio.soundfont',
                label: 'Soundfont path',
                description: 'Path to the SoundFont (.sf2) file for MIDI playback.',
                category: 'audio',
                type: 'text',
                effect: 'applies_now',
                default: '~/.kosmokrator/soundfonts/FluidR3_GM.sf2',
            ),
            new SettingDefinition(
                id: 'audio.llm_timeout',
                path: 'kosmokrator.audio.llm_timeout',
                label: 'Composition timeout (seconds)',
                description: 'Seconds to wait for AI music composition before falling back to a built-in omen.',
                category: 'audio',
                type: 'number',
                effect: 'applies_now',
                default: 60,
            ),
            new SettingDefinition(
                id: 'audio.max_duration',
                path: 'kosmokrator.audio.max_duration',
                label: 'Max duration (seconds)',
                description: 'Maximum length of the composed musical piece.',
                category: 'audio',
                type: 'number',
                effect: 'applies_now',
                default: 8,
            ),
            new SettingDefinition(
                id: 'audio.max_retries',
                path: 'kosmokrator.audio.max_retries',
                label: 'Composition retries',
                description: 'Number of times to retry if the LLM fails to generate a valid music script.',
                category: 'audio',
                type: 'number',
                effect: 'applies_now',
                default: 1,
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

<?php

namespace Kosmokrator\Tool\Permission;

use Illuminate\Config\Repository;

/**
 * Reads the kosmo.tools config section and converts it into the structured
 * arrays and PermissionRule objects consumed by PermissionEvaluator.
 *
 * Single entry-point for turning raw config into permission infrastructure.
 */
class PermissionConfigParser
{
    /**
     * Parse the config repository into rules, blocked paths, and Guardian-safe commands.
     *
     * @return array{rules: PermissionRule[], blocked_paths: string[], guardian_safe_commands: string[], default_permission_mode: string}
     */
    /** Default tools that are always allowed without prompting. */
    private const DEFAULT_SAFE_TOOLS = [
        'file_read', 'glob', 'grep',
        'task_create', 'task_update', 'task_list', 'task_get',
        'shell_read', 'shell_kill',
        'memory_save', 'memory_search',
        'ask_user', 'ask_choice',
        'subagent',
        'web_search', 'web_fetch',
    ];

    /** Commands that are denied even in Prometheus mode. */
    public const DEFAULT_BLOCKED_COMMANDS = [
        'rm -rf *',
        'rm -fr *',
        'rm -r *',
        'sudo rm *',
        'mkfs*',
        'dd *of=*',
        'shred *',
        'truncate -s 0 *',
        'git reset --hard*',
        'git clean -fd*',
        'git clean -df*',
        'git push --force*',
        'git push -f*',
        'chmod -R 777 *',
        'chown -R *',
        'docker system prune*',
        'kubectl delete *',
    ];

    public function parse(Repository $config): array
    {
        $rules = [];

        $approvalRequired = $config->get('kosmo.tools.approval_required', []);
        $configuredBlockedCommands = $config->get('kosmo.tools.bash.blocked_commands', []);
        $blockedCommands = array_values(array_unique([
            ...self::DEFAULT_BLOCKED_COMMANDS,
            ...$configuredBlockedCommands,
        ]));
        $blockedPaths = $config->get('kosmo.tools.blocked_paths', []);
        $safeCommands = $config->get('kosmo.tools.guardian_safe_commands', []);
        $defaultMode = $config->get('kosmo.tools.default_permission_mode', 'guardian');
        $safeTools = $config->get('kosmo.tools.safe_tools', self::DEFAULT_SAFE_TOOLS);
        $deniedTools = $config->get('kosmo.tools.denied_tools', []);

        // Deny rules FIRST — these override everything including Prometheus
        foreach ($deniedTools as $toolName) {
            $rules[] = new PermissionRule(
                toolName: $toolName,
                action: PermissionAction::Deny,
                denyReason: "Tool '{$toolName}' is disabled in project configuration (denied_tools).",
            );
        }

        // Allow rules for safe tools
        foreach ($safeTools as $toolName) {
            // Skip if already denied
            if (in_array($toolName, $deniedTools, true)) {
                continue;
            }

            $rules[] = new PermissionRule(
                toolName: $toolName,
                action: PermissionAction::Allow,
            );
        }

        // Ask rules for tools requiring approval
        foreach ($approvalRequired as $toolName) {
            // Skip if already denied
            if (in_array($toolName, $deniedTools, true)) {
                continue;
            }

            $denyPatterns = in_array($toolName, ['bash', 'shell_start', 'shell_write'], true) ? $blockedCommands : [];

            $rules[] = new PermissionRule(
                toolName: $toolName,
                action: PermissionAction::Ask,
                denyPatterns: $denyPatterns,
            );
        }

        return [
            'rules' => $rules,
            'blocked_paths' => $blockedPaths,
            'guardian_safe_commands' => $safeCommands,
            'default_permission_mode' => $defaultMode,
        ];
    }
}

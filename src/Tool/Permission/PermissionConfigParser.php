<?php

namespace Kosmokrator\Tool\Permission;

use Illuminate\Config\Repository;

class PermissionConfigParser
{
    /**
     * @return array{rules: PermissionRule[], blocked_paths: string[], guardian_safe_commands: string[], default_permission_mode: string}
     */
    public function parse(Repository $config): array
    {
        $rules = [];

        $approvalRequired = $config->get('kosmokrator.tools.approval_required', []);
        $blockedCommands = $config->get('kosmokrator.tools.bash.blocked_commands', []);
        $blockedPaths = $config->get('kosmokrator.tools.blocked_paths', []);
        $safeCommands = $config->get('kosmokrator.tools.guardian_safe_commands', []);
        $defaultMode = $config->get('kosmokrator.tools.default_permission_mode', 'guardian');

        foreach ($approvalRequired as $toolName) {
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

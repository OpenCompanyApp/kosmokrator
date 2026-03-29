<?php

namespace Kosmokrator\Tool\Permission;

use Illuminate\Config\Repository;

class PermissionConfigParser
{
    /**
     * @return PermissionRule[]
     */
    public function parse(Repository $config): array
    {
        $rules = [];

        $approvalRequired = $config->get('kosmokrator.tools.approval_required', []);
        $blockedCommands = $config->get('kosmokrator.tools.bash.blocked_commands', []);

        foreach ($approvalRequired as $toolName) {
            $denyPatterns = ($toolName === 'bash') ? $blockedCommands : [];

            $rules[] = new PermissionRule(
                toolName: $toolName,
                action: PermissionAction::Ask,
                denyPatterns: $denyPatterns,
            );
        }

        return $rules;
    }
}

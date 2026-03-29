<?php

namespace Kosmokrator\Tool\Permission;

class PermissionRule
{
    /**
     * @param string[] $denyPatterns Glob patterns for args that always deny (e.g. blocked bash commands)
     */
    public function __construct(
        public readonly string $toolName,
        public readonly PermissionAction $action,
        public readonly array $denyPatterns = [],
    ) {}

    /**
     * Evaluate this rule against a tool call.
     * Returns the resolved action if this rule matches, or null if it doesn't apply.
     */
    public function evaluate(string $toolName, array $args): ?PermissionAction
    {
        if ($this->toolName !== $toolName) {
            return null;
        }

        // Check deny patterns against the command argument (bash-specific)
        if ($this->denyPatterns !== [] && isset($args['command'])) {
            $command = trim((string) $args['command']);
            foreach ($this->denyPatterns as $pattern) {
                if ($this->matchesPattern($command, $pattern)) {
                    return PermissionAction::Deny;
                }
            }
        }

        return $this->action;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        // Convert glob-style pattern to regex
        $regex = '/^' . str_replace(
            ['\*', '\?'],
            ['.*', '.'],
            preg_quote($pattern, '/'),
        ) . '$/i';

        return (bool) preg_match($regex, $value);
    }
}

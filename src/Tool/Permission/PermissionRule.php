<?php

namespace Kosmokrator\Tool\Permission;

class PermissionRule
{
    /**
     * @param  string[]  $denyPatterns  Glob patterns for args that always deny (e.g. blocked bash commands)
     */
    public function __construct(
        public readonly string $toolName,
        public readonly PermissionAction $action,
        public readonly array $denyPatterns = [],
    ) {}

    /**
     * Evaluate this rule against a tool call.
     * Returns the resolved result if this rule matches, or null if it doesn't apply.
     */
    public function evaluate(string $toolName, array $args): ?PermissionResult
    {
        if ($this->toolName !== $toolName) {
            return null;
        }

        // Check deny patterns against the command argument (bash-specific)
        if ($this->denyPatterns !== [] && isset($args['command'])) {
            $command = trim((string) $args['command']);
            foreach ($this->denyPatterns as $pattern) {
                if (self::matchesGlob($command, $pattern)) {
                    return new PermissionResult(
                        PermissionAction::Deny,
                        "Command matches blocked pattern '{$pattern}'",
                    );
                }
            }
        }

        return new PermissionResult($this->action);
    }

    /**
     * Match a value against a glob-style pattern (case-insensitive).
     */
    public static function matchesGlob(string $value, string $pattern): bool
    {
        $regex = '/^'.str_replace(
            ['\*', '\?'],
            ['.*', '.'],
            preg_quote($pattern, '/'),
        ).'$/i';

        return (bool) preg_match($regex, $value);
    }
}

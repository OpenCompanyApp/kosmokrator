<?php

namespace Kosmokrator\Tool\Permission;

/**
 * A single permission rule that binds a tool name to an action (Ask / Deny),
 * optionally carrying deny-pattern globs that block specific command arguments.
 *
 * Evaluated by PermissionEvaluator during the rule-matching phase.
 */
class PermissionRule
{
    /**
     * @param  string[]  $denyPatterns  Glob patterns for args that always deny (e.g. blocked bash commands)
     */
    public function __construct(
        public readonly string $toolName,
        public readonly PermissionAction $action,
        public readonly array $denyPatterns = [],
        public readonly ?string $denyReason = null,
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

        // Check deny patterns against command-like input (bash/shell tools)
        $command = trim((string) ($args['command'] ?? $args['input'] ?? ''));
        if ($this->denyPatterns !== [] && $command !== '') {
            foreach ($this->denyPatterns as $pattern) {
                if (self::matchesGlob($command, $pattern)) {
                    return new PermissionResult(
                        PermissionAction::Deny,
                        "Command matches blocked pattern '{$pattern}'",
                    );
                }
            }
        }

        $reason = $this->denyReason;
        if ($reason === null && $this->action === PermissionAction::Deny) {
            $reason = "Tool '{$toolName}' is denied by policy.";
        }

        return new PermissionResult($this->action, $reason ?? '');
    }

    /**
     * Match a value against a glob-style pattern (case-insensitive).
     *
     * The wildcard `*` matches within a single token (non-whitespace only)
     * to prevent patterns like `git *` from matching `git log && rm -rf /`.
     */
    public static function matchesGlob(string $value, string $pattern): bool
    {
        $regex = '/^'.str_replace(
            ['\*', '\?'],
            ['[^;&|`$><\n]*', '.'],
            preg_quote($pattern, '/'),
        ).'$/i';

        return (bool) preg_match($regex, $value);
    }
}

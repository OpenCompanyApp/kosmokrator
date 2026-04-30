<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Permission;

use Kosmokrator\Tool\Permission\Check\BlockedPathCheck;
use Kosmokrator\Tool\Permission\Check\DenyPatternCheck;
use Kosmokrator\Tool\Permission\Check\ModeOverrideCheck;
use Kosmokrator\Tool\Permission\Check\ProjectBoundaryCheck;
use Kosmokrator\Tool\Permission\Check\RuleCheck;
use Kosmokrator\Tool\Permission\Check\SessionGrantCheck;

/**
 * Central permission decision engine: evaluates every tool call against a chain
 * of PermissionCheck stages to produce a PermissionResult (Allow / Ask / Deny).
 *
 * The chain order is: blocked paths -> deny patterns -> project boundary ->
 * session grants -> rules -> mode overrides.
 * The first check that returns a non-null result halts the chain.
 *
 * Lives in the tool-call hot path — called before every tool execution.
 */
class PermissionEvaluator
{
    private PermissionMode $permissionMode = PermissionMode::Guardian;

    /** @var PermissionCheck[] */
    private readonly array $chain;

    /**
     * @param  PermissionRule[]  $rules
     * @param  string[]  $blockedPaths  Glob patterns for paths that should be denied
     * @param  ProjectBoundaryCheck|null  $boundaryCheck  Optional project boundary enforcement
     */
    public function __construct(
        private readonly array $rules,
        private readonly SessionGrants $grants,
        private readonly array $blockedPaths = [],
        private readonly ?GuardianEvaluator $guardian = null,
        ?ProjectBoundaryCheck $boundaryCheck = null,
    ) {
        $this->chain = array_values(array_filter([
            new BlockedPathCheck($this->blockedPaths),
            new DenyPatternCheck($this->rules),
            $boundaryCheck,
            new SessionGrantCheck($this->grants),
            new RuleCheck($this->rules),
            new ModeOverrideCheck(
                $this->rules,
                fn (): PermissionMode => $this->permissionMode,
                $this->guardian,
            ),
        ]));
    }

    /**
     * Run the full evaluation chain for a tool call and return the final decision.
     *
     * @param  string  $toolName  Name of the tool being called
     * @param  array  $args  Named arguments passed to the tool
     */
    public function evaluate(string $toolName, array $args): PermissionResult
    {
        foreach ($this->chain as $check) {
            $result = $check->evaluate($toolName, $args);
            if ($result !== null) {
                return $result;
            }
        }

        // No check halted the chain — deny by default (fail closed)
        return new PermissionResult(
            PermissionAction::Deny,
            "Tool '{$toolName}' is not explicitly allowed by policy.",
        );
    }

    /** Switch the active permission mode at runtime. */
    public function setPermissionMode(PermissionMode $mode): void
    {
        $this->permissionMode = $mode;
    }

    /** Return the currently active permission mode. */
    public function getPermissionMode(): PermissionMode
    {
        return $this->permissionMode;
    }

    /** Mark a tool as session-approved so future calls skip the Ask prompt. */
    public function grantSession(string $toolName, array $args = []): void
    {
        $this->grants->grant($toolName, $args);
    }

    /** Clear all session grants. */
    public function resetGrants(): void
    {
        $this->grants->reset();
    }

    /** Delegate mutative-command check to the Guardian evaluator. */
    public function isMutativeCommand(string $command): bool
    {
        return $this->guardian?->isMutativeCommand($command) ?? false;
    }
}

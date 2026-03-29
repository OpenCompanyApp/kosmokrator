<?php

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionRule;
use Kosmokrator\Tool\Permission\SessionGrants;
use PHPUnit\Framework\TestCase;

class PermissionEvaluatorTest extends TestCase
{
    private SessionGrants $grants;

    protected function setUp(): void
    {
        $this->grants = new SessionGrants();
    }

    public function test_tool_not_in_rules_returns_allow(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants);

        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('file_read', ['path' => '/tmp/test']));
    }

    public function test_tool_in_approval_required_returns_ask(): void
    {
        $rules = [
            new PermissionRule('file_write', PermissionAction::Ask),
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('file_write', ['path' => '/tmp/test', 'content' => 'hello']));
        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'ls -la']));
    }

    public function test_unmatched_tool_still_allowed(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('grep', ['pattern' => 'foo']));
    }

    public function test_bash_blocked_command_returns_deny(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['rm -rf /', 'rm -rf ~', 'mkfs*']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf /']));
        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf ~']));
        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'mkfs.ext4 /dev/sda']));
    }

    public function test_bash_safe_command_returns_ask(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['rm -rf /']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'git status']));
    }

    public function test_session_grant_overrides_ask(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'ls']));

        $evaluator->grantSession('bash');

        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('bash', ['command' => 'ls']));
    }

    public function test_reset_grants_clears_session_memory(): void
    {
        $rules = [
            new PermissionRule('file_edit', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $evaluator->grantSession('file_edit');
        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('file_edit', []));

        $evaluator->resetGrants();
        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('file_edit', []));
    }

    public function test_deny_pattern_matching_is_case_insensitive(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['RM -RF /']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf /']));
    }

    public function test_auto_approve_overrides_ask(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
            new PermissionRule('file_write', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'ls']));

        $evaluator->setAutoApprove(true);
        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('bash', ['command' => 'ls']));
        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('file_write', ['path' => '/tmp/x']));

        $evaluator->setAutoApprove(false);
        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'ls']));
    }

    public function test_auto_approve_does_not_override_deny(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['rm -rf /']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $evaluator->setAutoApprove(true);
        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf /']));
        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('bash', ['command' => 'git status']));
    }

    public function test_deny_pattern_with_wildcard(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['rm -rf *']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf /home/user']));
        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf .']));
        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'rm file.txt']));
    }
}

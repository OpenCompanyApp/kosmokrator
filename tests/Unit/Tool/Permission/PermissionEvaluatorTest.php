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

    // --- Blocked paths ---

    public function test_blocked_path_denies_file_read(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env', '.git/*']);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_read', ['path' => '/app/.env']));
    }

    public function test_blocked_path_denies_file_write(): void
    {
        $rules = [new PermissionRule('file_write', PermissionAction::Ask)];
        $evaluator = new PermissionEvaluator($rules, $this->grants, ['*.env']);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_write', ['path' => '.env', 'content' => 'SECRET=x']));
    }

    public function test_blocked_path_denies_file_edit(): void
    {
        $rules = [new PermissionRule('file_edit', PermissionAction::Ask)];
        $evaluator = new PermissionEvaluator($rules, $this->grants, ['.git/*']);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_edit', ['path' => '.git/config', 'old_string' => 'a', 'new_string' => 'b']));
    }

    public function test_blocked_path_matches_basename(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env']);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_read', ['path' => '/deeply/nested/.env']));
    }

    public function test_blocked_path_does_not_match_safe_paths(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env', '.git/*']);

        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('file_read', ['path' => 'src/App.php']));
        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('grep', ['pattern' => 'foo', 'path' => 'src/']));
    }

    public function test_blocked_path_overrides_session_grant(): void
    {
        $rules = [new PermissionRule('file_read', PermissionAction::Ask)];
        $evaluator = new PermissionEvaluator($rules, $this->grants, ['*.env']);
        $evaluator->grantSession('file_read');

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_read', ['path' => '.env']));
    }

    public function test_blocked_path_overrides_auto_approve(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env']);
        $evaluator->setAutoApprove(true);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_read', ['path' => '.env']));
    }

    public function test_tool_without_path_arg_unaffected_by_blocked_paths(): void
    {
        $rules = [new PermissionRule('bash', PermissionAction::Ask)];
        $evaluator = new PermissionEvaluator($rules, $this->grants, ['*.env']);

        // bash has 'command' not 'path', so blocked_paths should not affect it
        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'cat .env']));
    }
}

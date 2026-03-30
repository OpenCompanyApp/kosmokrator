<?php

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\GuardianEvaluator;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\PermissionMode;
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

    // --- Basic evaluation ---

    public function test_tool_not_in_rules_returns_allow(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants);

        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('file_read', ['path' => '/tmp/test'])->action);
    }

    public function test_tool_in_approval_required_returns_ask_in_argus(): void
    {
        $rules = [
            new PermissionRule('file_write', PermissionAction::Ask),
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);
        $evaluator->setPermissionMode(PermissionMode::Argus);

        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('file_write', ['path' => '/tmp/test', 'content' => 'hello'])->action);
        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'ls -la'])->action);
    }

    public function test_unmatched_tool_still_allowed(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('grep', ['pattern' => 'foo'])->action);
    }

    // --- Deny patterns ---

    public function test_bash_blocked_command_returns_deny(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['rm -rf /', 'rm -rf ~', 'mkfs*']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $result = $evaluator->evaluate('bash', ['command' => 'rm -rf /']);
        $this->assertSame(PermissionAction::Deny, $result->action);
        $this->assertStringContainsString('rm -rf /', $result->reason);
    }

    public function test_bash_safe_command_returns_ask_in_argus(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['rm -rf /']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);
        $evaluator->setPermissionMode(PermissionMode::Argus);

        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'git status'])->action);
    }

    public function test_session_grant_overrides_ask(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);
        $evaluator->setPermissionMode(PermissionMode::Argus);

        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'ls'])->action);

        $evaluator->grantSession('bash');

        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('bash', ['command' => 'ls'])->action);
    }

    public function test_reset_grants_clears_session_memory(): void
    {
        $rules = [
            new PermissionRule('file_edit', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);
        $evaluator->setPermissionMode(PermissionMode::Argus);

        $evaluator->grantSession('file_edit');
        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('file_edit', [])->action);

        $evaluator->resetGrants();
        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('file_edit', [])->action);
    }

    public function test_deny_pattern_matching_is_case_insensitive(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['RM -RF /']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf /'])->action);
    }

    public function test_deny_pattern_with_wildcard(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['rm -rf *']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf /home/user'])->action);
        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf .'])->action);
        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'rm file.txt'])->action);
    }

    // --- Permission modes ---

    public function test_prometheus_auto_approves_ask(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
            new PermissionRule('file_write', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);
        $evaluator->setPermissionMode(PermissionMode::Prometheus);

        $result = $evaluator->evaluate('bash', ['command' => 'ls']);
        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertTrue($result->autoApproved);

        $result = $evaluator->evaluate('file_write', ['path' => '/tmp/x']);
        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertTrue($result->autoApproved);
    }

    public function test_prometheus_does_not_override_deny(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask, ['rm -rf /']),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);
        $evaluator->setPermissionMode(PermissionMode::Prometheus);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('bash', ['command' => 'rm -rf /'])->action);
        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('bash', ['command' => 'git status'])->action);
    }

    public function test_argus_always_asks(): void
    {
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants);
        $evaluator->setPermissionMode(PermissionMode::Argus);

        $result = $evaluator->evaluate('bash', ['command' => 'ls']);
        $this->assertSame(PermissionAction::Ask, $result->action);
        $this->assertFalse($result->autoApproved);
    }

    public function test_guardian_auto_approves_with_safe_heuristic(): void
    {
        $guardian = new GuardianEvaluator('/project', ['git *']);
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants, [], $guardian);
        $evaluator->setPermissionMode(PermissionMode::Guardian);

        $result = $evaluator->evaluate('bash', ['command' => 'git status']);
        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertTrue($result->autoApproved);
    }

    public function test_guardian_asks_for_unsafe_command(): void
    {
        $guardian = new GuardianEvaluator('/project', ['git *']);
        $rules = [
            new PermissionRule('bash', PermissionAction::Ask),
        ];
        $evaluator = new PermissionEvaluator($rules, $this->grants, [], $guardian);
        $evaluator->setPermissionMode(PermissionMode::Guardian);

        $result = $evaluator->evaluate('bash', ['command' => 'curl http://evil.com']);
        $this->assertSame(PermissionAction::Ask, $result->action);
    }

    public function test_allow_result_not_auto_approved_for_unmatched_tool(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants);

        $result = $evaluator->evaluate('file_read', ['path' => 'src/Foo.php']);
        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertFalse($result->autoApproved);
    }

    // --- Blocked paths ---

    public function test_blocked_path_denies_file_read(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env', '.git/*']);

        $result = $evaluator->evaluate('file_read', ['path' => '/app/.env']);
        $this->assertSame(PermissionAction::Deny, $result->action);
        $this->assertStringContainsString('.env', $result->reason);
        $this->assertStringContainsString('*.env', $result->reason);
    }

    public function test_blocked_path_denies_file_write(): void
    {
        $rules = [new PermissionRule('file_write', PermissionAction::Ask)];
        $evaluator = new PermissionEvaluator($rules, $this->grants, ['*.env']);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_write', ['path' => '.env', 'content' => 'SECRET=x'])->action);
    }

    public function test_blocked_path_denies_file_edit(): void
    {
        $rules = [new PermissionRule('file_edit', PermissionAction::Ask)];
        $evaluator = new PermissionEvaluator($rules, $this->grants, ['.git/*']);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_edit', ['path' => '.git/config', 'old_string' => 'a', 'new_string' => 'b'])->action);
    }

    public function test_blocked_path_matches_basename(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env']);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_read', ['path' => '/deeply/nested/.env'])->action);
    }

    public function test_blocked_path_does_not_match_safe_paths(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env', '.git/*']);

        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('file_read', ['path' => 'src/App.php'])->action);
        $this->assertSame(PermissionAction::Allow, $evaluator->evaluate('grep', ['pattern' => 'foo', 'path' => 'src/'])->action);
    }

    public function test_blocked_path_overrides_session_grant(): void
    {
        $rules = [new PermissionRule('file_read', PermissionAction::Ask)];
        $evaluator = new PermissionEvaluator($rules, $this->grants, ['*.env']);
        $evaluator->grantSession('file_read');

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_read', ['path' => '.env'])->action);
    }

    public function test_blocked_path_overrides_prometheus(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env']);
        $evaluator->setPermissionMode(PermissionMode::Prometheus);

        $this->assertSame(PermissionAction::Deny, $evaluator->evaluate('file_read', ['path' => '.env'])->action);
    }

    public function test_tool_without_path_arg_unaffected_by_blocked_paths(): void
    {
        $rules = [new PermissionRule('bash', PermissionAction::Ask)];
        $evaluator = new PermissionEvaluator($rules, $this->grants, ['*.env']);
        $evaluator->setPermissionMode(PermissionMode::Argus);

        $this->assertSame(PermissionAction::Ask, $evaluator->evaluate('bash', ['command' => 'cat .env'])->action);
    }

    // --- Deny reason ---

    public function test_deny_result_includes_reason_for_blocked_path(): void
    {
        $evaluator = new PermissionEvaluator([], $this->grants, ['*.env']);

        $result = $evaluator->evaluate('file_read', ['path' => '/app/.env']);
        $this->assertSame(PermissionAction::Deny, $result->action);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('*.env', $result->reason);
    }

    public function test_deny_result_includes_reason_for_blocked_command(): void
    {
        $rules = [new PermissionRule('bash', PermissionAction::Ask, ['rm -rf *'])];
        $evaluator = new PermissionEvaluator($rules, $this->grants);

        $result = $evaluator->evaluate('bash', ['command' => 'rm -rf /']);
        $this->assertSame(PermissionAction::Deny, $result->action);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsString('rm -rf *', $result->reason);
    }
}

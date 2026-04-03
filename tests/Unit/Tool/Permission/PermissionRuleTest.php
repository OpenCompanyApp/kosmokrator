<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionResult;
use Kosmokrator\Tool\Permission\PermissionRule;
use PHPUnit\Framework\TestCase;

class PermissionRuleTest extends TestCase
{
    // --- evaluate() ---

    public function test_evaluate_returns_null_when_tool_name_does_not_match(): void
    {
        $rule = new PermissionRule('bash', PermissionAction::Ask);

        $result = $rule->evaluate('file_read', ['path' => '/tmp/test']);

        $this->assertNull($result);
    }

    public function test_evaluate_returns_result_with_rule_action_when_tool_name_matches(): void
    {
        $rule = new PermissionRule('bash', PermissionAction::Ask);

        $result = $rule->evaluate('bash', ['command' => 'ls']);

        $this->assertInstanceOf(PermissionResult::class, $result);
        $this->assertSame(PermissionAction::Ask, $result->action);
        $this->assertNull($result->reason);
    }

    public function test_deny_patterns_match_command_arg(): void
    {
        $rule = new PermissionRule('bash', PermissionAction::Allow, ['rm *']);

        $result = $rule->evaluate('bash', ['command' => 'rm -rf /']);

        $this->assertSame(PermissionAction::Deny, $result->action);
        $this->assertSame("Command matches blocked pattern 'rm *'", $result->reason);
    }

    public function test_deny_patterns_match_input_arg(): void
    {
        $rule = new PermissionRule('bash', PermissionAction::Allow, ['rm *']);

        $result = $rule->evaluate('bash', ['input' => 'rm -rf /']);

        $this->assertSame(PermissionAction::Deny, $result->action);
        $this->assertSame("Command matches blocked pattern 'rm *'", $result->reason);
    }

    public function test_deny_patterns_are_case_insensitive(): void
    {
        $rule = new PermissionRule('bash', PermissionAction::Allow, ['RM *']);

        $result = $rule->evaluate('bash', ['command' => 'rm -rf /']);

        $this->assertSame(PermissionAction::Deny, $result->action);
    }

    public function test_empty_deny_patterns_skip_check(): void
    {
        $rule = new PermissionRule('bash', PermissionAction::Allow);

        $result = $rule->evaluate('bash', ['command' => 'rm -rf /']);

        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertNull($result->reason);
    }

    public function test_empty_command_skips_deny_check(): void
    {
        $rule = new PermissionRule('bash', PermissionAction::Allow, ['rm *']);

        $result = $rule->evaluate('bash', ['command' => '']);

        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertNull($result->reason);
    }

    public function test_missing_command_and_input_skips_deny_check(): void
    {
        $rule = new PermissionRule('bash', PermissionAction::Allow, ['rm *']);

        $result = $rule->evaluate('bash', []);

        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertNull($result->reason);
    }

    // --- matchesGlob() ---

    public function test_matches_glob_with_wildcard(): void
    {
        $this->assertTrue(PermissionRule::matchesGlob('rm -rf /', 'rm *'));
        $this->assertTrue(PermissionRule::matchesGlob('delete everything', 'delete*'));
        $this->assertTrue(PermissionRule::matchesGlob('foo bar baz', 'foo*baz'));
        $this->assertFalse(PermissionRule::matchesGlob('ls -la', 'rm *'));
    }

    public function test_matches_glob_with_question_mark(): void
    {
        $this->assertTrue(PermissionRule::matchesGlob('abc', 'a?c'));
        $this->assertTrue(PermissionRule::matchesGlob('rm -rf /', 'rm ?rf /'));
        $this->assertFalse(PermissionRule::matchesGlob('abbc', 'a?c'));
    }

    public function test_matches_glob_exact_match(): void
    {
        $this->assertTrue(PermissionRule::matchesGlob('ls', 'ls'));
        $this->assertTrue(PermissionRule::matchesGlob('rm -rf /', 'rm -rf /'));
    }

    public function test_matches_glob_no_match(): void
    {
        $this->assertFalse(PermissionRule::matchesGlob('ls -la', 'rm -rf'));
        $this->assertFalse(PermissionRule::matchesGlob('hello', 'world'));
    }

    public function test_matches_glob_is_case_insensitive(): void
    {
        $this->assertTrue(PermissionRule::matchesGlob('RM -RF /', 'rm *'));
        $this->assertTrue(PermissionRule::matchesGlob('rm -rf /', 'RM *'));
    }
}

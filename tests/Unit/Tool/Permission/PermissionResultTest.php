<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionResult;
use PHPUnit\Framework\TestCase;

class PermissionResultTest extends TestCase
{
    public function test_constructor_with_action_only_uses_defaults(): void
    {
        $result = new PermissionResult(PermissionAction::Allow);

        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertNull($result->reason);
        $this->assertFalse($result->autoApproved);
    }

    public function test_constructor_with_all_arguments(): void
    {
        $result = new PermissionResult(
            action: PermissionAction::Deny,
            reason: 'Tool not allowed',
            autoApproved: true,
        );

        $this->assertSame(PermissionAction::Deny, $result->action);
        $this->assertSame('Tool not allowed', $result->reason);
        $this->assertTrue($result->autoApproved);
    }

    public function test_action_property_is_accessible(): void
    {
        $result = new PermissionResult(PermissionAction::Ask);

        $this->assertInstanceOf(PermissionAction::class, $result->action);
        $this->assertSame(PermissionAction::Ask, $result->action);
    }

    public function test_reason_can_be_null(): void
    {
        $result = new PermissionResult(PermissionAction::Allow, reason: null);

        $this->assertNull($result->reason);
    }

    public function test_reason_can_be_a_string(): void
    {
        $result = new PermissionResult(PermissionAction::Ask, reason: 'Requires user confirmation');

        $this->assertSame('Requires user confirmation', $result->reason);
    }

    public function test_auto_approved_defaults_to_false(): void
    {
        $result = new PermissionResult(PermissionAction::Allow);

        $this->assertFalse($result->autoApproved);
    }

    public function test_auto_approved_can_be_true(): void
    {
        $result = new PermissionResult(PermissionAction::Allow, autoApproved: true);

        $this->assertTrue($result->autoApproved);
    }

    public function test_with_action_allow(): void
    {
        $result = new PermissionResult(PermissionAction::Allow, 'Allowed by config', true);

        $this->assertSame(PermissionAction::Allow, $result->action);
        $this->assertSame('Allowed by config', $result->reason);
        $this->assertTrue($result->autoApproved);
    }

    public function test_with_action_ask(): void
    {
        $result = new PermissionResult(PermissionAction::Ask, 'Needs approval');

        $this->assertSame(PermissionAction::Ask, $result->action);
        $this->assertSame('Needs approval', $result->reason);
        $this->assertFalse($result->autoApproved);
    }

    public function test_with_action_deny(): void
    {
        $result = new PermissionResult(PermissionAction::Deny, 'Blocked by policy');

        $this->assertSame(PermissionAction::Deny, $result->action);
        $this->assertSame('Blocked by policy', $result->reason);
        $this->assertFalse($result->autoApproved);
    }
}

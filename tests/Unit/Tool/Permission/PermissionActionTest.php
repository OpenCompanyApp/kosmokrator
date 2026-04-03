<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\PermissionAction;
use PHPUnit\Framework\TestCase;

class PermissionActionTest extends TestCase
{
    public function test_allow_has_correct_value(): void
    {
        $this->assertSame('allow', PermissionAction::Allow->value);
    }

    public function test_ask_has_correct_value(): void
    {
        $this->assertSame('ask', PermissionAction::Ask->value);
    }

    public function test_deny_has_correct_value(): void
    {
        $this->assertSame('deny', PermissionAction::Deny->value);
    }

    public function test_from_returns_correct_cases(): void
    {
        $this->assertSame(PermissionAction::Allow, PermissionAction::from('allow'));
        $this->assertSame(PermissionAction::Ask, PermissionAction::from('ask'));
        $this->assertSame(PermissionAction::Deny, PermissionAction::from('deny'));
    }

    public function test_from_throws_for_invalid_value(): void
    {
        $this->expectException(\ValueError::class);
        PermissionAction::from('invalid');
    }

    public function test_try_from_returns_correct_cases(): void
    {
        $this->assertSame(PermissionAction::Allow, PermissionAction::tryFrom('allow'));
        $this->assertSame(PermissionAction::Ask, PermissionAction::tryFrom('ask'));
        $this->assertSame(PermissionAction::Deny, PermissionAction::tryFrom('deny'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(PermissionAction::tryFrom('nonexistent'));
    }

    public function test_cases_returns_all_three_cases(): void
    {
        $cases = PermissionAction::cases();

        $this->assertCount(3, $cases);
        $this->assertSame(PermissionAction::Allow, $cases[0]);
        $this->assertSame(PermissionAction::Ask, $cases[1]);
        $this->assertSame(PermissionAction::Deny, $cases[2]);
    }
}

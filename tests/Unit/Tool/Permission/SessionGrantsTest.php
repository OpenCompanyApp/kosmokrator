<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\SessionGrants;
use PHPUnit\Framework\TestCase;

class SessionGrantsTest extends TestCase
{
    public function test_initially_no_grants(): void
    {
        $grants = new SessionGrants();

        $this->assertFalse($grants->isGranted('bash'));
    }

    public function test_grant_adds_tool(): void
    {
        $grants = new SessionGrants();
        $grants->grant('bash');

        $this->assertTrue($grants->isGranted('bash'));
    }

    public function test_is_granted_returns_false_for_non_granted_tool(): void
    {
        $grants = new SessionGrants();
        $grants->grant('bash');

        $this->assertFalse($grants->isGranted('file_write'));
    }

    public function test_multiple_grants_work(): void
    {
        $grants = new SessionGrants();
        $grants->grant('bash');
        $grants->grant('file_write');
        $grants->grant('grep');

        $this->assertTrue($grants->isGranted('bash'));
        $this->assertTrue($grants->isGranted('file_write'));
        $this->assertTrue($grants->isGranted('grep'));
        $this->assertFalse($grants->isGranted('file_read'));
    }

    public function test_reset_clears_all_grants(): void
    {
        $grants = new SessionGrants();
        $grants->grant('bash');
        $grants->grant('file_write');

        $grants->reset();

        $this->assertFalse($grants->isGranted('bash'));
        $this->assertFalse($grants->isGranted('file_write'));
    }

    public function test_grant_same_tool_twice_is_idempotent(): void
    {
        $grants = new SessionGrants();
        $grants->grant('bash');
        $grants->grant('bash');

        $this->assertTrue($grants->isGranted('bash'));

        $grants->reset();

        $this->assertFalse($grants->isGranted('bash'));
    }
}

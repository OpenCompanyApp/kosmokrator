<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\SessionGrants;
use PHPUnit\Framework\TestCase;

class SessionGrantsTest extends TestCase
{
    public function test_initially_no_grants(): void
    {
        $grants = new SessionGrants;

        $this->assertFalse($grants->isGranted('bash'));
    }

    public function test_grant_adds_tool(): void
    {
        $grants = new SessionGrants;
        $grants->grant('bash', ['command' => 'git status']);

        $this->assertTrue($grants->isGranted('bash', ['command' => 'git status']));
    }

    public function test_is_granted_returns_false_for_non_granted_tool(): void
    {
        $grants = new SessionGrants;
        $grants->grant('bash', ['command' => 'git status']);

        $this->assertFalse($grants->isGranted('file_write', ['path' => 'a.txt']));
    }

    public function test_grant_is_scoped_to_exact_arguments(): void
    {
        $grants = new SessionGrants;
        $grants->grant('bash', ['command' => 'git status']);

        $this->assertTrue($grants->isGranted('bash', ['command' => 'git status']));
        $this->assertFalse($grants->isGranted('bash', ['command' => 'rm -rf src']));
    }

    public function test_multiple_grants_work(): void
    {
        $grants = new SessionGrants;
        $grants->grant('bash', ['command' => 'git status']);
        $grants->grant('file_write', ['path' => 'a.txt', 'content' => 'a']);
        $grants->grant('grep');

        $this->assertTrue($grants->isGranted('bash', ['command' => 'git status']));
        $this->assertTrue($grants->isGranted('file_write', ['content' => 'a', 'path' => 'a.txt']));
        $this->assertTrue($grants->isGranted('grep'));
        $this->assertFalse($grants->isGranted('file_read'));
    }

    public function test_reset_clears_all_grants(): void
    {
        $grants = new SessionGrants;
        $grants->grant('bash', ['command' => 'git status']);
        $grants->grant('file_write', ['path' => 'a.txt']);

        $grants->reset();

        $this->assertFalse($grants->isGranted('bash', ['command' => 'git status']));
        $this->assertFalse($grants->isGranted('file_write', ['path' => 'a.txt']));
    }

    public function test_grant_same_tool_twice_is_idempotent(): void
    {
        $grants = new SessionGrants;
        $args = ['command' => 'git status'];
        $grants->grant('bash', $args);
        $grants->grant('bash', $args);

        $this->assertTrue($grants->isGranted('bash', $args));

        $grants->reset();

        $this->assertFalse($grants->isGranted('bash', $args));
    }
}

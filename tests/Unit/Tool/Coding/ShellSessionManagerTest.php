<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\ShellSessionManager;
use Kosmokrator\Tool\Coding\ShellWriteTool;
use Kosmokrator\Tool\Permission\GuardianEvaluator;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\Permission\SessionGrants;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ShellSessionManagerTest extends TestCase
{
    public function test_start_returns_initial_output_and_auto_cleans_after_final_drain(): void
    {
        $manager = new ShellSessionManager(new NullLogger, 200, 5, 5);

        $result = $manager->start('printf hello', waitMs: 200);

        $this->assertStringContainsString('Session sh_', $result['output']);
        $this->assertStringContainsString('hello', $result['output']);

        $this->expectException(\RuntimeException::class);
        $manager->read($result['id'], 0);
    }

    public function test_interactive_session_round_trip_and_kill(): void
    {
        $manager = new ShellSessionManager(new NullLogger, 100, 5, 5);

        $start = $manager->start('cat', waitMs: 20);
        $this->assertStringContainsString('(no new output yet)', $start['output']);

        $echoed = $manager->write($start['id'], 'hello', true, 100);
        $this->assertStringContainsString('hello', $echoed);

        $killed = $manager->kill($start['id']);
        $this->assertStringContainsString('Session '.$start['id'].' killed.', $killed);
    }

    public function test_read_only_session_blocks_mutative_input(): void
    {
        $manager = new ShellSessionManager(new NullLogger, 50, 5, 5);
        $session = $manager->start('cat', readOnly: true, waitMs: 10);

        $permissions = new PermissionEvaluator([], new SessionGrants, [], new GuardianEvaluator(getcwd(), ['git *']));
        $tool = new ShellWriteTool($manager, $permissions);

        $result = $tool->execute([
            'session_id' => $session['id'],
            'input' => 'touch forbidden.txt',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('read-only shell session', $result->output);
    }
}

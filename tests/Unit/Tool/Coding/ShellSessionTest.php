<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Amp\Process\Process;
use Kosmokrator\Tool\Coding\ShellSession;
use PHPUnit\Framework\TestCase;

class ShellSessionTest extends TestCase
{
    private function createProcessStub(): Process
    {
        $ref = new \ReflectionClass(Process::class);
        /** @var Process $process */
        $process = $ref->newInstanceWithoutConstructor();

        return $process;
    }

    private function createSession(
        string $id = 'sh_test',
        string $command = 'true',
        string $cwd = '/tmp',
        bool $readOnly = false,
        float $startedAt = 1000.0,
        int $timeoutSeconds = 30,
    ): ShellSession {
        return new ShellSession(
            $id,
            $this->createProcessStub(),
            $command,
            $cwd,
            $readOnly,
            $startedAt,
            $timeoutSeconds,
        );
    }

    public function test_constructor_sets_public_properties(): void
    {
        $process = $this->createProcessStub();
        $session = new ShellSession(
            'sh_abc',
            $process,
            'echo hello',
            '/home/user',
            true,
            1234.5,
            60,
        );

        $this->assertSame('sh_abc', $session->id);
        $this->assertSame($process, $session->process);
        $this->assertSame('echo hello', $session->command);
        $this->assertSame('/home/user', $session->cwd);
        $this->assertTrue($session->readOnly);
        $this->assertSame(1234.5, $session->startedAt);
        $this->assertSame(60, $session->timeoutSeconds);
    }

    public function test_constructor_sets_last_active_at_to_started_at(): void
    {
        $session = $this->createSession(startedAt: 42.0);
        $this->assertSame(42.0, $session->lastActiveAt);
    }

    public function test_append_output_adds_to_buffer(): void
    {
        $session = $this->createSession();
        $session->appendOutput('hello');
        $this->assertTrue($session->hasUnreadOutput());
        $this->assertSame('hello', $session->readUnread());
    }

    public function test_append_output_ignores_empty_string(): void
    {
        $session = $this->createSession();
        $session->appendOutput('');
        $this->assertFalse($session->hasUnreadOutput());
    }

    public function test_append_output_concatenates_chunks(): void
    {
        $session = $this->createSession();
        $session->appendOutput('hello ');
        $session->appendOutput('world');
        $this->assertSame('hello world', $session->readUnread());
    }

    public function test_read_unread_returns_new_output_and_advances_cursor(): void
    {
        $session = $this->createSession();
        $session->appendOutput('part1');
        $this->assertSame('part1', $session->readUnread());

        $session->appendOutput('part2');
        $this->assertSame('part2', $session->readUnread());
    }

    public function test_has_unread_output_returns_true_when_unread_data_exists(): void
    {
        $session = $this->createSession();
        $session->appendOutput('data');
        $this->assertTrue($session->hasUnreadOutput());
    }

    public function test_has_unread_output_returns_false_when_all_read(): void
    {
        $session = $this->createSession();
        $session->appendOutput('data');
        $session->readUnread();
        $this->assertFalse($session->hasUnreadOutput());
    }

    public function test_has_unread_output_returns_false_initially(): void
    {
        $session = $this->createSession();
        $this->assertFalse($session->hasUnreadOutput());
    }

    public function test_append_system_line_adds_line_with_trailing_newline(): void
    {
        $session = $this->createSession();
        $session->appendSystemLine('Exit code: 0');
        $this->assertSame("Exit code: 0\n", $session->readUnread());
    }

    public function test_append_system_line_prepends_newline_when_buffer_does_not_end_with_one(): void
    {
        $session = $this->createSession();
        $session->appendOutput('some output');
        $session->appendSystemLine('Exit code: 0');

        $output = $session->readUnread();
        $this->assertSame("some output\nExit code: 0\n", $output);
    }

    public function test_append_system_line_does_not_prepend_newline_when_buffer_ends_with_one(): void
    {
        $session = $this->createSession();
        $session->appendOutput("some output\n");
        $session->appendSystemLine('Exit code: 0');

        $output = $session->readUnread();
        $this->assertSame("some output\nExit code: 0\n", $output);
    }

    public function test_append_system_line_on_empty_buffer_does_not_prepend_newline(): void
    {
        $session = $this->createSession();
        $session->appendSystemLine('System message');
        $this->assertSame("System message\n", $session->readUnread());
    }

    public function test_mark_exited_sets_exit_code(): void
    {
        $session = $this->createSession();
        $session->markExited(0);
        $this->assertSame(0, $session->exitCode());
    }

    public function test_mark_exited_preserves_nonzero_exit_code(): void
    {
        $session = $this->createSession();
        $session->markExited(127);
        $this->assertSame(127, $session->exitCode());
    }

    public function test_exit_code_returns_null_initially(): void
    {
        $session = $this->createSession();
        $this->assertNull($session->exitCode());
    }

    public function test_is_running_returns_false_after_mark_exited(): void
    {
        $session = $this->createSession();
        $session->markExited(0);
        // exitCode !== null short-circuits before accessing $process->isRunning()
        $this->assertFalse($session->isRunning());
    }

    public function test_is_drained_returns_true_when_exited_and_no_unread_output(): void
    {
        $session = $this->createSession();
        $session->appendOutput('done');
        $session->readUnread();
        $session->markExited(0);
        $this->assertTrue($session->isDrained());
    }

    public function test_is_drained_returns_false_when_still_has_output(): void
    {
        $session = $this->createSession();
        $session->appendOutput('pending');
        $session->markExited(0);
        $this->assertFalse($session->isDrained());
    }

    public function test_is_drained_returns_false_when_not_exited(): void
    {
        $session = $this->createSession();
        $this->assertFalse($session->isDrained());
    }

    public function test_was_killed_returns_false_initially(): void
    {
        $session = $this->createSession();
        $this->assertFalse($session->wasKilled());
    }

    public function test_mark_killed_sets_killed_flag(): void
    {
        $session = $this->createSession();
        $session->markKilled();
        $this->assertTrue($session->wasKilled());
    }

    public function test_set_timeout_timer_id_and_timeout_timer_id_round_trip(): void
    {
        $session = $this->createSession();

        $this->assertNull($session->timeoutTimerId());

        $session->setTimeoutTimerId('timer-123');
        $this->assertSame('timer-123', $session->timeoutTimerId());

        $session->setTimeoutTimerId(null);
        $this->assertNull($session->timeoutTimerId());
    }

    public function test_touch_updates_last_active_at(): void
    {
        $session = $this->createSession(startedAt: 1000.0);
        $this->assertSame(1000.0, $session->lastActiveAt);

        $before = microtime(true);
        $session->touch();
        $after = microtime(true);

        $this->assertGreaterThanOrEqual($before, $session->lastActiveAt);
        $this->assertLessThanOrEqual($after, $session->lastActiveAt);
    }

    public function test_append_output_touches_last_active_at(): void
    {
        $session = $this->createSession(startedAt: 1000.0);
        $session->appendOutput('data');
        $this->assertGreaterThan(1000.0, $session->lastActiveAt);
    }

    public function test_read_unread_touches_last_active_at(): void
    {
        $session = $this->createSession(startedAt: 1000.0);
        $session->appendOutput('data');
        $session->readUnread();
        $this->assertGreaterThan(1000.0, $session->lastActiveAt);
    }

    public function test_mark_exited_touches_last_active_at(): void
    {
        $session = $this->createSession(startedAt: 1000.0);
        $session->markExited(0);
        $this->assertGreaterThan(1000.0, $session->lastActiveAt);
    }
}

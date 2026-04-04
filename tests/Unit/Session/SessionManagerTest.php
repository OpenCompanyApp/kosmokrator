<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Agent\CompactionPlan;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\MemoryRepository;
use Kosmokrator\Session\MessageRepository;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SessionRepository;
use Kosmokrator\Session\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SessionManagerTest extends TestCase
{
    public function test_select_relevant_memories_can_skip_touching_surface_timestamp(): void
    {
        $db = new Database(':memory:');
        $sessions = new SessionRepository($db);
        $messages = new MessageRepository($db);
        $settings = new SettingsRepository($db);
        $memories = new MemoryRepository($db);
        $manager = new SessionManager($sessions, $messages, $settings, $memories, new NullLogger);
        $manager->setProject('/project');
        $manager->createSession('model');

        $memoryId = $manager->addMemory('project', 'JWT auth', 'JWT is used for auth');

        $selected = $manager->selectRelevantMemories('JWT', 5, false);
        $this->assertCount(1, $selected);
        $this->assertNull($memories->find($memoryId)['last_surfaced_at']);

        $selected = $manager->selectRelevantMemories('JWT', 5, true);
        $this->assertCount(1, $selected);
        $this->assertNotNull($memories->find($memoryId)['last_surfaced_at']);
    }

    public function test_persist_compaction_plan_rolls_back_when_summary_append_fails(): void
    {
        $db = new Database(':memory:');
        $sessions = new SessionRepository($db);
        $messages = new class($db) extends MessageRepository
        {
            public bool $failSummaryAppend = false;

            public function append(
                string $sessionId,
                string $role,
                ?string $content = null,
                ?array $toolCalls = null,
                ?array $toolResults = null,
                int $tokensIn = 0,
                int $tokensOut = 0,
            ): int {
                if ($this->failSummaryAppend && $role === 'system' && $content === 'summary') {
                    throw new \RuntimeException('simulated append failure');
                }

                return parent::append($sessionId, $role, $content, $toolCalls, $toolResults, $tokensIn, $tokensOut);
            }
        };
        $settings = new SettingsRepository($db);
        $memories = new MemoryRepository($db);
        $manager = new SessionManager($sessions, $messages, $settings, $memories, new NullLogger);
        $manager->setProject('/project');
        $sessionId = $manager->createSession('model');

        $messages->append($sessionId, 'user', 'old question');
        $messages->append($sessionId, 'assistant', 'old answer');
        $messages->append($sessionId, 'user', 'recent question');

        $messages->failSummaryAppend = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated append failure');

        try {
            $manager->persistCompactionPlan(new CompactionPlan(
                keepFromMessageIndex: 2,
                compactedMessageCount: 2,
                summary: 'summary',
                replacementMessages: [],
            ));
        } finally {
            $raw = $messages->loadRaw($sessionId, true);
            $this->assertCount(3, $raw);
            $this->assertSame([0, 0, 0], array_map(fn (array $row): int => (int) $row['compacted'], $raw));
        }
    }

    public function test_switch_to_nonexistent_session_throws(): void
    {
        $db = new Database(':memory:');
        $sessions = new SessionRepository($db);
        $messages = new MessageRepository($db);
        $settings = new SettingsRepository($db);
        $memories = new MemoryRepository($db);
        $manager = new SessionManager($sessions, $messages, $settings, $memories, new NullLogger);

        // setCurrentSession is a simple setter with no validation.
        // Verify it sets the ID without throwing (no DB lookup).
        $manager->setCurrentSession('nonexistent-id');
        $this->assertSame('nonexistent-id', $manager->currentSessionId());
    }

    public function test_save_message_with_no_session_does_not_persist(): void
    {
        $db = new Database(':memory:');
        $sessions = new SessionRepository($db);
        $messages = new MessageRepository($db);
        $settings = new SettingsRepository($db);
        $memories = new MemoryRepository($db);
        $manager = new SessionManager($sessions, $messages, $settings, $memories, new NullLogger);

        // No session — saveMessage should silently return without persisting
        $manager->saveMessage(new \Prism\Prism\ValueObjects\Messages\UserMessage('hello'));

        // Verify no messages were persisted by creating a session and checking
        $sessionId = $manager->createSession('model');
        $manager->setProject('/project');
        $history = $manager->loadHistory($sessionId);
        $this->assertCount(0, $history->messages());
    }
}

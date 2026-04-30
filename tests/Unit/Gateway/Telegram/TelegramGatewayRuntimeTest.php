<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Illuminate\Container\Container;
use Kosmokrator\Gateway\GatewayApprovalStore;
use Kosmokrator\Gateway\GatewayCheckpointStore;
use Kosmokrator\Gateway\GatewayMessageStore;
use Kosmokrator\Gateway\GatewayPendingInputStore;
use Kosmokrator\Gateway\GatewaySessionStore;
use Kosmokrator\Gateway\Telegram\TelegramGatewayConfig;
use Kosmokrator\Gateway\Telegram\TelegramGatewayRuntime;
use Kosmokrator\Session\Database;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TelegramGatewayRuntimeTest extends TestCase
{
    public function test_register_bot_commands_syncs_native_telegram_commands(): void
    {
        $runtime = new TelegramGatewayRuntime(
            container: new Container,
            client: $client = new FakeTelegramClient,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db = new Database(':memory:')),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: new FakeTelegramWorkerLauncher,
        );

        $runtime->registerBotCommands();

        $this->assertSame([
            ['command' => 'help', 'description' => 'Show gateway help'],
            ['command' => 'status', 'description' => 'Show linked session status'],
            ['command' => 'new', 'description' => 'Start a fresh chat session'],
            ['command' => 'resume', 'description' => 'Resume the linked session'],
            ['command' => 'approve', 'description' => 'Approve the latest tool request'],
            ['command' => 'deny', 'description' => 'Deny the latest tool request'],
            ['command' => 'cancel', 'description' => 'Cancel the active run'],
            ['command' => 'compact', 'description' => 'Force context compaction'],
            ['command' => 'edit', 'description' => 'Switch to edit mode'],
            ['command' => 'plan', 'description' => 'Switch to plan mode'],
            ['command' => 'ask', 'description' => 'Switch to ask mode'],
            ['command' => 'guardian', 'description' => 'Switch to Guardian mode'],
            ['command' => 'argus', 'description' => 'Switch to Argus mode'],
            ['command' => 'prometheus', 'description' => 'Switch to Prometheus mode'],
            ['command' => 'memories', 'description' => 'List stored memories'],
            ['command' => 'sessions', 'description' => 'List recent sessions'],
            ['command' => 'agents', 'description' => 'Show swarm summary'],
            ['command' => 'rename', 'description' => 'Rename the current session'],
            ['command' => 'forget', 'description' => 'Delete a memory by ID'],
        ], $client->botCommands);
    }

    public function test_process_updates_handles_help_command_without_running_agent(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'text' => '/help',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);

        $this->assertCount(1, $client->sent);
        $this->assertStringContainsString('KosmoKrator Telegram gateway', $client->sent[0]['text']);
        $this->assertSame([], $launcher->launched);
    }

    public function test_process_updates_launches_worker_for_normal_message(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 1,
            'message' => [
                'message_id' => 11,
                'text' => 'hello there',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);

        $this->assertCount(1, $launcher->launched);
        $this->assertSame('hello there', $launcher->launched[0]->text);
    }

    public function test_status_reports_active_run_details(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $sessions = new GatewaySessionStore($db);
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-123', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $sessions->save('telegram', 'telegram:123', 'sess-123', '123', null, '5');
        $checkpoints = new GatewayCheckpointStore($db);
        $checkpoints->set('telegram', 'last_update_id', '42');
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: $sessions,
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: $checkpoints,
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 1,
            'message' => [
                'message_id' => 11,
                'text' => 'hello there',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);
        $runtime->processUpdates([[
            'update_id' => 2,
            'message' => [
                'message_id' => 12,
                'text' => '/status',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);

        $this->assertCount(1, $client->sent);
        $this->assertStringContainsString('Bot: @kosmokrator_bot', $client->sent[0]['text']);
        $this->assertStringContainsString('Session: sess-123', $client->sent[0]['text']);
        $this->assertStringContainsString('Running: yes', $client->sent[0]['text']);
        $this->assertStringContainsString('Worker PID: 4243', $client->sent[0]['text']);
        $this->assertStringContainsString('Checkpoint: 42', $client->sent[0]['text']);
    }

    public function test_cancel_terminates_active_worker(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 1,
            'message' => [
                'message_id' => 11,
                'text' => 'hello there',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);
        $runtime->processUpdates([[
            'update_id' => 2,
            'message' => [
                'message_id' => 12,
                'text' => '/cancel',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);

        $this->assertNotNull($launcher->lastHandle);
        $this->assertTrue($launcher->lastHandle->terminated);
        $this->assertCount(1, $client->sent);
        $this->assertSame('Cancelling the active run…', $client->sent[0]['text']);
    }

    public function test_callback_query_approves_pending_request(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $approvals = new GatewayApprovalStore($db);
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $approval = $approvals->createPending('telegram', 'telegram:123', 'sess-1', 'bash', ['command' => 'ls'], '123');
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: $approvals,
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: new FakeTelegramWorkerLauncher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'ga:allow:'.$approval->id,
                'from' => ['id' => 5, 'username' => 'rutger'],
                'message' => [
                    'message_id' => 99,
                    'chat' => ['id' => 123, 'type' => 'private'],
                ],
            ],
        ]]);

        $resolved = $approvals->find($approval->id);
        $this->assertSame('approved', $resolved?->status);
        $this->assertCount(1, $client->callbackAnswers);
        $this->assertSame('Approved.', $client->callbackAnswers[0]['text']);
        $this->assertCount(1, $client->edited);
        $this->assertSame('<b>Approved</b> <code>bash</code>.', $client->edited[0]['text']);
        $this->assertSame('HTML', $client->edited[0]['parse_mode']);
    }

    public function test_callback_query_can_switch_to_prometheus_for_pending_request(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $approvals = new GatewayApprovalStore($db);
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $approval = $approvals->createPending('telegram', 'telegram:123', 'sess-1', 'bash', ['command' => 'ls'], '123');
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: $approvals,
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: new FakeTelegramWorkerLauncher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'ga:prometheus:'.$approval->id,
                'from' => ['id' => 5, 'username' => 'rutger'],
                'message' => [
                    'message_id' => 99,
                    'chat' => ['id' => 123, 'type' => 'private'],
                ],
            ],
        ]]);

        $resolved = $approvals->find($approval->id);
        $this->assertSame('prometheus', $resolved?->status);
        $this->assertSame('Switched To Prometheus.', $client->callbackAnswers[0]['text']);
    }

    public function test_group_approval_callback_requires_requester_or_authorized_user(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $approvals = new GatewayApprovalStore($db);
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $approval = $approvals->createPending('telegram', 'telegram:-1001', 'sess-1', 'bash', ['command' => 'ls'], '-1001', requesterUserId: '5');
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: $approvals,
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: new FakeTelegramWorkerLauncher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'ga:allow:'.$approval->id,
                'from' => ['id' => 6, 'username' => 'mallory'],
                'message' => [
                    'message_id' => 99,
                    'chat' => ['id' => -1001, 'type' => 'supergroup'],
                ],
            ],
        ]]);

        $this->assertSame('pending', $approvals->find($approval->id)?->status);
        $this->assertSame('Not authorized.', $client->callbackAnswers[0]['text']);
        $this->assertSame([], $client->edited);
    }

    public function test_group_control_callback_requires_authorized_user(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'gc:cmd:prometheus',
                'from' => ['id' => 6, 'username' => 'mallory'],
                'message' => [
                    'message_id' => 99,
                    'chat' => ['id' => -1001, 'type' => 'supergroup'],
                ],
            ],
        ]]);

        $this->assertSame([], $launcher->launched);
        $this->assertSame('Not authorized.', $client->callbackAnswers[0]['text']);
    }

    public function test_admin_user_can_use_group_control_callback(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20, adminUsers: ['admin']),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'gc:cmd:prometheus',
                'from' => ['id' => 6, 'username' => 'admin'],
                'message' => [
                    'message_id' => 99,
                    'chat' => ['id' => -1001, 'type' => 'supergroup'],
                ],
            ],
        ]]);

        $this->assertCount(1, $launcher->launched);
        $this->assertSame('/prometheus', $launcher->launched[0]->text);
    }

    public function test_status_includes_inline_control_keyboard(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: new FakeTelegramWorkerLauncher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 2,
            'message' => [
                'message_id' => 12,
                'text' => '/status',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);

        $keyboard = $client->sent[0]['reply_markup']['inline_keyboard'] ?? [];
        $this->assertSame('Edit', $keyboard[0][0]['text'] ?? null);
        $this->assertSame('Prometheus', $keyboard[1][2]['text'] ?? null);
        $this->assertSame('Compact', $keyboard[2][0]['text'] ?? null);
    }

    public function test_control_callback_launches_slash_command_as_new_turn(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: new GatewayPendingInputStore($db),
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'gc:cmd:edit',
                'from' => ['id' => 5, 'username' => 'rutger'],
                'message' => [
                    'message_id' => 99,
                    'chat' => ['id' => 123, 'type' => 'private'],
                ],
            ],
        ]]);

        $this->assertCount(1, $launcher->launched);
        $this->assertSame('/edit', $launcher->launched[0]->text);
        $this->assertSame('Working…', $client->callbackAnswers[0]['text']);
    }

    public function test_second_message_is_queued_and_runs_after_active_route_finishes(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $pendingInputs = new GatewayPendingInputStore($db);
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: $pendingInputs,
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 1,
            'message' => [
                'message_id' => 11,
                'text' => 'first',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);
        $runtime->processUpdates([[
            'update_id' => 2,
            'message' => [
                'message_id' => 12,
                'text' => 'second',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);

        $this->assertCount(1, $launcher->launched);
        $this->assertCount(1, $client->sent);
        $this->assertSame('Queued for the next turn in this chat.', $client->sent[0]['text']);
        $this->assertSame(1, $pendingInputs->count('telegram', 'telegram:123'));

        $this->assertNotNull($launcher->lastHandle);
        $launcher->lastHandle->running = false;

        $runtime->processUpdates([]);

        $this->assertCount(2, $launcher->launched);
        $this->assertSame('second', $launcher->launched[1]->text);
        $this->assertSame(0, $pendingInputs->count('telegram', 'telegram:123'));
    }

    public function test_new_clears_queued_inputs_for_route(): void
    {
        $container = new Container;
        $db = new Database(':memory:');
        $client = new FakeTelegramClient;
        $launcher = new FakeTelegramWorkerLauncher;
        $pendingInputs = new GatewayPendingInputStore($db);
        $runtime = new TelegramGatewayRuntime(
            container: $container,
            client: $client,
            config: new TelegramGatewayConfig(true, 'token', 'thread', [], [], true, [], 20),
            sessionLinks: new GatewaySessionStore($db),
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            checkpoints: new GatewayCheckpointStore($db),
            pendingInputs: $pendingInputs,
            log: new NullLogger,
            launcher: $launcher,
        );
        $runtime->setBotUsername('kosmokrator_bot');

        $runtime->processUpdates([[
            'update_id' => 1,
            'message' => [
                'message_id' => 11,
                'text' => 'first',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);
        $runtime->processUpdates([[
            'update_id' => 2,
            'message' => [
                'message_id' => 12,
                'text' => 'second',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);
        $runtime->processUpdates([[
            'update_id' => 3,
            'message' => [
                'message_id' => 13,
                'text' => '/new',
                'chat' => ['id' => 123, 'type' => 'private'],
                'from' => ['id' => 5, 'username' => 'rutger'],
            ],
        ]]);

        $this->assertSame(0, $pendingInputs->count('telegram', 'telegram:123'));
        $this->assertCount(2, $client->sent);
        $this->assertSame('Started a fresh session for this chat. Your next message will create a new Kosmo session.', $client->sent[1]['text']);
    }
}

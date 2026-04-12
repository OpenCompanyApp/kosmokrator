<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Gateway\GatewayApprovalStore;
use Kosmokrator\Gateway\GatewayMessageStore;
use Kosmokrator\Gateway\Telegram\TelegramGatewayRenderer;
use Kosmokrator\Session\Database;
use PHPUnit\Framework\TestCase;

final class TelegramGatewayRendererTest extends TestCase
{
    public function test_show_notice_edits_existing_thinking_message(): void
    {
        $db = new Database(':memory:');
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $client = new FakeTelegramClient;
        $renderer = new TelegramGatewayRenderer(
            client: $client,
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            routeKey: 'telegram:123',
            sessionId: 'sess-1',
            chatId: '123',
            threadId: null,
            approvalCallback: static fn (): string => 'deny',
        );

        $renderer->setPhase(AgentPhase::Thinking);
        $renderer->showNotice('Retrying in 5s (attempt 2)');

        $this->assertCount(1, $client->sent);
        $this->assertSame('Thinking…', $client->sent[0]['text']);
        $this->assertCount(2, $client->edited);
        $this->assertSame('Thinking…', $client->edited[0]['text']);
        $this->assertSame("Thinking…\n\nRetrying in 5s (attempt 2)", $client->edited[1]['text']);
        $this->assertNotEmpty($client->chatActions);
    }

    public function test_ask_tool_permission_sends_inline_buttons(): void
    {
        $db = new Database(':memory:');
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $client = new FakeTelegramClient;
        $renderer = new TelegramGatewayRenderer(
            client: $client,
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            routeKey: 'telegram:123',
            sessionId: 'sess-1',
            chatId: '123',
            threadId: null,
            approvalCallback: static fn (): string => 'deny',
        );

        $renderer->askToolPermission('bash', ['command' => 'rm -rf /tmp/x']);

        $this->assertCount(1, $client->sent);
        $this->assertNotNull($client->sent[0]['reply_markup']);
        $keyboard = $client->sent[0]['reply_markup']['inline_keyboard'] ?? [];
        $this->assertSame('Approve', $keyboard[0][0]['text'] ?? null);
        $this->assertSame('Always', $keyboard[0][1]['text'] ?? null);
        $this->assertSame('Guardian', $keyboard[1][0]['text'] ?? null);
        $this->assertSame('Prometheus', $keyboard[1][1]['text'] ?? null);
        $this->assertSame('Deny', $keyboard[2][0]['text'] ?? null);
    }

    public function test_stream_complete_uses_separate_status_and_answer_messages(): void
    {
        $db = new Database(':memory:');
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $client = new FakeTelegramClient;
        $renderer = new TelegramGatewayRenderer(
            client: $client,
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            routeKey: 'telegram:123',
            sessionId: 'sess-1',
            chatId: '123',
            threadId: null,
            approvalCallback: static fn (): string => 'deny',
        );

        $path = tempnam(sys_get_temp_dir(), 'kosmo-photo-');
        $photo = $path.'.png';
        rename($path, $photo);
        file_put_contents($photo, 'png');

        try {
            $renderer->setPhase(AgentPhase::Thinking);
            $renderer->streamChunk("See attached\nMEDIA:{$photo}");
            $renderer->streamComplete();

            $this->assertCount(1, $client->photos);
            $this->assertSame($photo, $client->photos[0]['path']);
            $this->assertCount(2, $client->sent);
            $this->assertSame('Thinking…', $client->sent[0]['text']);
            $this->assertSame('See attached', $client->sent[1]['text']);
            $this->assertSame('HTML', $client->sent[1]['parse_mode']);
            $answerEdit = $client->edited[1]['text'] ?? '';
            $this->assertSame('See attached', $answerEdit);
            $this->assertSame('HTML', $client->edited[1]['parse_mode']);
            $this->assertSame('Done', $client->edited[array_key_last($client->edited)]['text']);
        } finally {
            @unlink($photo);
        }
    }

    public function test_tool_execution_uses_separate_tool_message(): void
    {
        $db = new Database(':memory:');
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $client = new FakeTelegramClient;
        $renderer = new TelegramGatewayRenderer(
            client: $client,
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            routeKey: 'telegram:123',
            sessionId: 'sess-1',
            chatId: '123',
            threadId: null,
            approvalCallback: static fn (): string => 'deny',
        );

        $renderer->setPhase(AgentPhase::Thinking);
        $renderer->showToolCall('grep', ['pattern' => 'telegram']);
        $renderer->showToolExecuting('grep');

        $this->assertCount(2, $client->sent);
        $this->assertSame('Thinking…', $client->sent[0]['text']);
        $this->assertStringContainsString('<b>Tool</b> <code>grep</code>', $client->sent[1]['text']);
        $this->assertSame('HTML', $client->sent[1]['parse_mode']);
        $this->assertSame('Preparing tool: grep', $client->edited[1]['text']);
        $this->assertStringContainsString('<b>Running tool</b> <code>grep</code>', $client->edited[2]['text']);
    }

    public function test_stream_complete_splits_large_messages_into_multiple_chunks(): void
    {
        $db = new Database(':memory:');
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $client = new FakeTelegramClient;
        $renderer = new TelegramGatewayRenderer(
            client: $client,
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            routeKey: 'telegram:123',
            sessionId: 'sess-1',
            chatId: '123',
            threadId: null,
            approvalCallback: static fn (): string => 'deny',
        );

        $renderer->setPhase(AgentPhase::Thinking);
        $renderer->streamChunk(str_repeat("alpha beta gamma\n", 350));
        $renderer->streamComplete();

        $this->assertGreaterThanOrEqual(3, count($client->sent));
        $this->assertSame('HTML', $client->sent[1]['parse_mode']);
        $this->assertSame('HTML', $client->sent[2]['parse_mode']);
    }

    public function test_stream_complete_formats_code_blocks_and_tables_as_html(): void
    {
        $db = new Database(':memory:');
        $db->connection()->prepare('INSERT INTO sessions (id, project, title, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['sess-1', null, null, 'test/model', date(DATE_ATOM), date(DATE_ATOM)]);
        $client = new FakeTelegramClient;
        $renderer = new TelegramGatewayRenderer(
            client: $client,
            messages: new GatewayMessageStore($db),
            approvals: new GatewayApprovalStore($db),
            routeKey: 'telegram:123',
            sessionId: 'sess-1',
            chatId: '123',
            threadId: null,
            approvalCallback: static fn (): string => 'deny',
        );

        $renderer->setPhase(AgentPhase::Thinking);
        $renderer->streamChunk("# Heading\n\n| A | B |\n| - | - |\n| 1 | 2 |\n\n```php\necho 'hi';\n```");
        $renderer->streamComplete();

        $payload = $client->sent[1]['text'] ?? '';
        $this->assertStringContainsString('<b>Heading</b>', $payload);
        $this->assertStringContainsString('<pre><code>| A | B |', $payload);
        $this->assertStringContainsString('<pre><code>echo', $payload);
    }
}

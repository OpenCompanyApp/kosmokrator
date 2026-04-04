<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Illuminate\Config\Repository;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\SubagentOrchestrator;
use Kosmokrator\Agent\SubagentStats;
use Kosmokrator\Command\Slash\AgentsCommand;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\TestCase;

class AgentsCommandTest extends TestCase
{
    private AgentsCommand $command;

    protected function setUp(): void
    {
        $this->command = new AgentsCommand;
    }

    // ---------------------------------------------------------------
    // Metadata tests
    // ---------------------------------------------------------------

    public function test_name(): void
    {
        $this->assertSame('/agents', $this->command->name());
    }

    public function test_aliases(): void
    {
        $this->assertSame(['/swarm'], $this->command->aliases());
    }

    public function test_description(): void
    {
        $this->assertSame('Show swarm progress dashboard', $this->command->description());
    }

    public function test_immediate(): void
    {
        $this->assertTrue($this->command->immediate());
    }

    // ---------------------------------------------------------------
    // execute() tests
    // ---------------------------------------------------------------

    public function test_execute_without_orchestrator_shows_notice(): void
    {
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('No subagent orchestrator available.');

        $ctx = $this->makeContext(ui: $ui, orchestrator: null);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_with_orchestrator_but_no_stats_shows_notice(): void
    {
        $orchestrator = $this->createMock(SubagentOrchestrator::class);
        $orchestrator->method('allStats')->willReturn([]);

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showNotice')
            ->with('No agents have been spawned yet.');

        $ctx = $this->makeContext(ui: $ui, orchestrator: $orchestrator);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_with_stats_shows_dashboard(): void
    {
        $stat = $this->makeStat('a1', status: 'done');
        $stats = ['a1' => $stat];

        $orchestrator = $this->createMock(SubagentOrchestrator::class);
        $orchestrator->method('allStats')->willReturn($stats);

        $llm = $this->createStub(LlmClientInterface::class);
        $llm->method('getModel')->willReturn('test-model');

        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showAgentsDashboard')
            ->with(
                $this->callback(fn (array $summary) => $summary['total'] === 1 && $summary['done'] === 1),
                $this->identicalTo($stats),
                $this->isType('callable'),
            );

        $ctx = $this->makeContext(ui: $ui, orchestrator: $orchestrator, llm: $llm);

        $result = $this->command->execute('', $ctx);

        $this->assertSame(SlashCommandAction::Continue, $result->action);
    }

    public function test_execute_refresh_callback_returns_updated_summary(): void
    {
        $stat1 = $this->makeStat('a1', status: 'done');
        $stat2 = $this->makeStat('a2', status: 'running');

        $callCount = 0;
        $orchestrator = $this->createMock(SubagentOrchestrator::class);
        $orchestrator->method('allStats')->willReturnCallback(function () use (&$callCount, $stat1, $stat2) {
            $callCount++;

            return $callCount === 1 ? ['a1' => $stat1] : ['a1' => $stat1, 'a2' => $stat2];
        });

        $llm = $this->createStub(LlmClientInterface::class);
        $llm->method('getModel')->willReturn('test-model');

        $capturedRefresh = null;
        $ui = $this->createMock(UIManager::class);
        $ui->expects($this->once())
            ->method('showAgentsDashboard')
            ->willReturnCallback(function ($summary, $stats, $refresh) use (&$capturedRefresh) {
                $capturedRefresh = $refresh;
            });

        $ctx = $this->makeContext(ui: $ui, orchestrator: $orchestrator, llm: $llm);
        $this->command->execute('', $ctx);

        // Trigger the refresh callback
        $refreshed = $capturedRefresh();
        $this->assertSame(2, $refreshed['summary']['total']);
        $this->assertSame(1, $refreshed['summary']['running']);
        $this->assertCount(2, $refreshed['stats']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — empty stats
    // ---------------------------------------------------------------

    public function test_build_summary_empty_stats_returns_zeros(): void
    {
        $result = AgentsCommand::buildSummary([], null, 'model');

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['done']);
        $this->assertSame(0, $result['running']);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['cancelled']);
        $this->assertSame(0, $result['retrying']);
        $this->assertSame(0, $result['retriedAndRecovered']);
        $this->assertSame(0, $result['totalRetries']);
        $this->assertSame(0, $result['tokensIn']);
        $this->assertSame(0, $result['tokensOut']);
        $this->assertSame(0, $result['totalTools']);
        $this->assertSame(0.0, $result['cost']);
        $this->assertSame(0.0, $result['avgCost']);
        $this->assertSame(0, $result['elapsed']);
        $this->assertSame(0, $result['rate']);
        $this->assertSame(0, $result['eta']);
        $this->assertSame([], $result['active']);
        $this->assertSame([], $result['failures']);
        $this->assertSame([], $result['byType']);
        $this->assertSame('model', $result['model']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — status counts
    // ---------------------------------------------------------------

    public function test_build_summary_counts_statuses(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'done'),
            'b' => $this->makeStat('b', status: 'done'),
            'c' => $this->makeStat('c', status: 'running'),
            'd' => $this->makeStat('d', status: 'queued'),
            'e' => $this->makeStat('e', status: 'queued_global'),
            'f' => $this->makeStat('f', status: 'waiting'),
            'g' => $this->makeStat('g', status: 'failed'),
            'h' => $this->makeStat('h', status: 'cancelled'),
            'i' => $this->makeStat('i', status: 'retrying'),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertSame(9, $result['total']);
        $this->assertSame(2, $result['done']);
        $this->assertSame(1, $result['running']);
        $this->assertSame(3, $result['queued']); // queued + queued_global + waiting
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(1, $result['retrying']);
    }

    public function test_build_summary_unknown_status_ignored(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'unknown_status'),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['done']);
        $this->assertSame(0, $result['running']);
        $this->assertSame(0, $result['failed']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — token and tool sums
    // ---------------------------------------------------------------

    public function test_build_summary_sums_tokens_and_tools(): void
    {
        $stats = [
            'a' => $this->makeStat('a', tokensIn: 100, tokensOut: 50, toolCalls: 3),
            'b' => $this->makeStat('b', tokensIn: 200, tokensOut: 75, toolCalls: 7),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertSame(300, $result['tokensIn']);
        $this->assertSame(125, $result['tokensOut']);
        $this->assertSame(10, $result['totalTools']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — retriedAndRecovered
    // ---------------------------------------------------------------

    public function test_build_summary_retried_and_recovered(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'done', retries: 2),
            'b' => $this->makeStat('b', status: 'done', retries: 0),
            'c' => $this->makeStat('c', status: 'failed', retries: 3), // failed, doesn't count
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertSame(1, $result['retriedAndRecovered']);
        $this->assertSame(5, $result['totalRetries']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — byType breakdown
    // ---------------------------------------------------------------

    public function test_build_summary_by_type_breakdown(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'done', agentType: 'explore', tokensIn: 100, tokensOut: 50),
            'b' => $this->makeStat('b', status: 'running', agentType: 'explore', tokensIn: 80, tokensOut: 40),
            'c' => $this->makeStat('c', status: 'failed', agentType: 'general', tokensIn: 200, tokensOut: 100),
            'd' => $this->makeStat('d', status: 'queued', agentType: 'general', tokensIn: 0, tokensOut: 0),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertArrayHasKey('explore', $result['byType']);
        $this->assertArrayHasKey('general', $result['byType']);

        $explore = $result['byType']['explore'];
        $this->assertSame(1, $explore['done']);
        $this->assertSame(1, $explore['running']);
        $this->assertSame(0, $explore['failed']);
        $this->assertSame(180, $explore['tokensIn']);
        $this->assertSame(90, $explore['tokensOut']);

        $general = $result['byType']['general'];
        $this->assertSame(0, $general['done']);
        $this->assertSame(1, $general['failed']);
        $this->assertSame(1, $general['queued']);
        $this->assertSame(200, $general['tokensIn']);
        $this->assertSame(100, $general['tokensOut']);
    }

    public function test_build_summary_by_type_empty_agent_type_becomes_unknown(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'done', agentType: ''),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertArrayHasKey('unknown', $result['byType']);
        $this->assertSame(1, $result['byType']['unknown']['done']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — active agents sorted by elapsed desc
    // ---------------------------------------------------------------

    public function test_build_summary_active_agents_sorted_by_elapsed_desc(): void
    {
        $now = microtime(true);
        $stats = [
            'a' => $this->makeStat('a', status: 'running', startTime: $now - 10.0, endTime: 0.0),
            'b' => $this->makeStat('b', status: 'running', startTime: $now - 30.0, endTime: 0.0),
            'c' => $this->makeStat('c', status: 'retrying', startTime: $now - 20.0, endTime: 0.0),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertCount(3, $result['active']);
        // Longest elapsed first
        $this->assertSame('b', $result['active'][0]->id);
        $this->assertSame('c', $result['active'][1]->id);
        $this->assertSame('a', $result['active'][2]->id);
    }

    public function test_build_summary_active_excludes_non_running(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'done'),
            'b' => $this->makeStat('b', status: 'failed'),
            'c' => $this->makeStat('c', status: 'queued'),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertSame([], $result['active']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — failures sorted by endTime desc
    // ---------------------------------------------------------------

    public function test_build_summary_failures_sorted_by_end_time_desc(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'failed', endTime: 1000.0),
            'b' => $this->makeStat('b', status: 'failed', endTime: 3000.0),
            'c' => $this->makeStat('c', status: 'failed', endTime: 2000.0),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertCount(3, $result['failures']);
        // Most recent endTime first
        $this->assertSame('b', $result['failures'][0]->id);
        $this->assertSame('c', $result['failures'][1]->id);
        $this->assertSame('a', $result['failures'][2]->id);
    }

    public function test_build_summary_failures_excludes_non_failed(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'done', endTime: 5000.0),
            'b' => $this->makeStat('b', status: 'running'),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertSame([], $result['failures']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — ETA and rate calculations
    // ---------------------------------------------------------------

    public function test_build_summary_rate_and_eta_with_done_agents(): void
    {
        $now = microtime(true);
        $startTime = $now - 120.0; // 2 minutes ago

        $stats = [];
        // 4 done agents
        for ($i = 0; $i < 4; $i++) {
            $stats["d{$i}"] = $this->makeStat("d{$i}", status: 'done', startTime: $startTime);
        }
        // 2 still running (should factor into remaining)
        for ($i = 0; $i < 2; $i++) {
            $stats["r{$i}"] = $this->makeStat("r{$i}", status: 'running', startTime: $startTime);
        }

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        // rate = done / (elapsed / 60) = 4 / (120/60) = 4 / 2 = 2 per minute
        $this->assertEqualsWithDelta(2.0, $result['rate'], 0.5);

        // remaining = total - done = 6 - 4 = 2
        // eta = remaining / rate * 60 = 2 / 2 * 60 = 60
        $this->assertEqualsWithDelta(60.0, $result['eta'], 5.0);
    }

    public function test_build_summary_rate_zero_when_no_done(): void
    {
        $now = microtime(true);
        $stats = [
            'a' => $this->makeStat('a', status: 'running', startTime: $now - 60.0),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertSame(0, $result['rate']);
        $this->assertSame(0, $result['eta']);
    }

    public function test_build_summary_eta_excludes_failed_and_cancelled(): void
    {
        $now = microtime(true);
        $startTime = $now - 60.0;

        $stats = [
            'a' => $this->makeStat('a', status: 'done', startTime: $startTime),
            'b' => $this->makeStat('b', status: 'failed', startTime: $startTime),
            'c' => $this->makeStat('c', status: 'cancelled', startTime: $startTime),
            'd' => $this->makeStat('d', status: 'queued', startTime: $startTime),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        // remaining = total - done - failed - cancelled = 4 - 1 - 1 - 1 = 1
        // rate = 1 / (60/60) = 1
        // eta = 1 / 1 * 60 = 60
        $this->assertEqualsWithDelta(60.0, $result['eta'], 5.0);
    }

    // ---------------------------------------------------------------
    // buildSummary() — cost estimation
    // ---------------------------------------------------------------

    public function test_build_summary_cost_with_model_catalog(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'done', tokensIn: 1000, tokensOut: 500),
            'b' => $this->makeStat('b', status: 'done', tokensIn: 2000, tokensOut: 1000),
        ];

        $models = $this->createMock(ModelCatalog::class);
        $models->expects($this->once())
            ->method('estimateCost')
            ->with('test-model', 3000, 1500)
            ->willReturn(0.42);

        $result = AgentsCommand::buildSummary($stats, $models, 'test-model');

        $this->assertSame(0.42, $result['cost']);
        // avgCost = cost / done = 0.42 / 2 = 0.21
        $this->assertSame(0.21, $result['avgCost']);
    }

    public function test_build_summary_cost_zero_when_no_models(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'done', tokensIn: 1000, tokensOut: 500),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'test-model');

        $this->assertSame(0.0, $result['cost']);
        $this->assertSame(0.0, $result['avgCost']);
    }

    public function test_build_summary_avg_cost_zero_when_no_done(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'running', tokensIn: 1000, tokensOut: 500),
        ];

        $models = $this->createMock(ModelCatalog::class);
        $models->method('estimateCost')->willReturn(0.1);

        $result = AgentsCommand::buildSummary($stats, $models, 'test-model');

        $this->assertSame(0.1, $result['cost']);
        $this->assertSame(0.0, $result['avgCost']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — elapsed calculation
    // ---------------------------------------------------------------

    public function test_build_summary_elapsed_from_earliest_start(): void
    {
        $now = microtime(true);
        $stats = [
            'a' => $this->makeStat('a', status: 'done', startTime: $now - 100.0),
            'b' => $this->makeStat('b', status: 'running', startTime: $now - 50.0),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        // Elapsed should be based on earliest start (a)
        $this->assertEqualsWithDelta(100.0, $result['elapsed'], 2.0);
    }

    public function test_build_summary_elapsed_zero_when_no_start_times(): void
    {
        $stats = [
            'a' => $this->makeStat('a', status: 'queued', startTime: 0.0),
        ];

        $result = AgentsCommand::buildSummary($stats, null, 'm');

        $this->assertSame(0, $result['elapsed']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — model passthrough
    // ---------------------------------------------------------------

    public function test_build_summary_passes_through_model(): void
    {
        $result = AgentsCommand::buildSummary([], null, 'claude-sonnet-4');

        $this->assertSame('claude-sonnet-4', $result['model']);
    }

    // ---------------------------------------------------------------
    // buildSummary() — comprehensive integration scenario
    // ---------------------------------------------------------------

    public function test_build_summary_comprehensive_scenario(): void
    {
        $now = microtime(true);

        $stats = [
            'a' => $this->makeStat('a', status: 'done', agentType: 'general',
                tokensIn: 5000, tokensOut: 2000, toolCalls: 10, retries: 1,
                startTime: $now - 300.0, endTime: $now - 200.0),
            'b' => $this->makeStat('b', status: 'done', agentType: 'general',
                tokensIn: 3000, tokensOut: 1500, toolCalls: 5, retries: 0,
                startTime: $now - 250.0, endTime: $now - 150.0),
            'c' => $this->makeStat('c', status: 'failed', agentType: 'explore',
                tokensIn: 1000, tokensOut: 500, toolCalls: 3, retries: 2,
                startTime: $now - 200.0, endTime: $now - 100.0),
            'd' => $this->makeStat('d', status: 'running', agentType: 'explore',
                tokensIn: 2000, tokensOut: 800, toolCalls: 4, retries: 0,
                startTime: $now - 50.0, endTime: 0.0),
            'e' => $this->makeStat('e', status: 'retrying', agentType: 'general',
                tokensIn: 1000, tokensOut: 400, toolCalls: 2, retries: 1,
                startTime: $now - 40.0, endTime: 0.0),
            'f' => $this->makeStat('f', status: 'cancelled', agentType: 'explore',
                tokensIn: 0, tokensOut: 0, toolCalls: 0, retries: 0,
                startTime: $now - 100.0, endTime: $now - 90.0),
            'g' => $this->makeStat('g', status: 'queued', agentType: 'general',
                tokensIn: 0, tokensOut: 0, toolCalls: 0, retries: 0,
                startTime: 0.0, endTime: 0.0),
        ];

        $models = $this->createMock(ModelCatalog::class);
        $models->method('estimateCost')->willReturn(1.23);

        $result = AgentsCommand::buildSummary($stats, $models, 'test-model');

        // Counts
        $this->assertSame(7, $result['total']);
        $this->assertSame(2, $result['done']);
        $this->assertSame(1, $result['running']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(1, $result['retrying']);

        // Retries
        $this->assertSame(4, $result['totalRetries']);
        $this->assertSame(1, $result['retriedAndRecovered']); // only 'a' is done with retries > 0

        // Token and tool sums
        $this->assertSame(12000, $result['tokensIn']);
        $this->assertSame(5200, $result['tokensOut']);
        $this->assertSame(24, $result['totalTools']);

        // Cost
        $this->assertSame(1.23, $result['cost']);
        $this->assertSame(1.23 / 2, $result['avgCost']);

        // Active (running + retrying), sorted by elapsed desc
        $this->assertCount(2, $result['active']);
        $this->assertSame('d', $result['active'][0]->id); // started at -50 (longer elapsed)
        $this->assertSame('e', $result['active'][1]->id); // started at -40

        // Failures sorted by endTime desc
        $this->assertCount(1, $result['failures']);
        $this->assertSame('c', $result['failures'][0]->id);

        // byType
        // a=done, b=done => 2 done for general
        $this->assertSame(2, $result['byType']['general']['done']);
        $this->assertSame(0, $result['byType']['general']['running']);
        $this->assertSame(1, $result['byType']['general']['queued']); // g
        $this->assertSame(9000, $result['byType']['general']['tokensIn']);

        $this->assertSame(0, $result['byType']['explore']['done']);
        $this->assertSame(1, $result['byType']['explore']['running']);
        $this->assertSame(1, $result['byType']['explore']['failed']);
        $this->assertSame(3000, $result['byType']['explore']['tokensIn']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeStat(
        string $id,
        string $status = 'queued',
        int $tokensIn = 0,
        int $tokensOut = 0,
        int $toolCalls = 0,
        int $retries = 0,
        string $agentType = '',
        float $startTime = 0.0,
        float $endTime = 0.0,
    ): SubagentStats {
        $stat = new SubagentStats($id);
        $stat->status = $status;
        $stat->tokensIn = $tokensIn;
        $stat->tokensOut = $tokensOut;
        $stat->toolCalls = $toolCalls;
        $stat->retries = $retries;
        $stat->agentType = $agentType;
        $stat->startTime = $startTime;
        $stat->endTime = $endTime;

        return $stat;
    }

    private function makeContext(
        ?UIManager $ui = null,
        ?SubagentOrchestrator $orchestrator = null,
        ?LlmClientInterface $llm = null,
    ): SlashCommandContext {
        return new SlashCommandContext(
            ui: $ui ?? $this->createStub(UIManager::class),
            agentLoop: $this->createStub(AgentLoop::class),
            permissions: $this->createStub(PermissionEvaluator::class),
            sessionManager: $this->createStub(SessionManager::class),
            llm: $llm ?? $this->createStub(LlmClientInterface::class),
            taskStore: $this->createStub(TaskStore::class),
            config: $this->createStub(Repository::class),
            settings: $this->createStub(SettingsRepository::class),
            orchestrator: $orchestrator,
        );
    }
}

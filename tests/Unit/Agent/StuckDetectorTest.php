<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\ConversationHistory;
use Kosmokrator\Agent\StuckDetector;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\ToolCall;

class StuckDetectorTest extends TestCase
{
    private StuckDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new StuckDetector;
    }

    public function test_diverse_calls_return_ok(): void
    {
        $calls = [
            new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "foo"}'),
            new ToolCall(id: 'tc_2', name: 'glob', arguments: '{"pattern": "*.php"}'),
        ];

        $this->assertSame('ok', $this->detector->check($calls));
    }

    public function test_three_identical_calls_trigger_nudge(): void
    {
        $call = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "foo"}');

        // First two rounds: ok
        $this->assertSame('ok', $this->detector->check([$call]));
        $this->assertSame('ok', $this->detector->check([$call]));

        // Third identical call: nudge
        $this->assertSame('nudge', $this->detector->check([$call]));
    }

    public function test_full_escalation_sequence(): void
    {
        $call = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "same"}');

        // Fill window to trigger nudge (3 identical)
        $this->detector->check([$call]);
        $this->detector->check([$call]);
        $this->assertSame('nudge', $this->detector->check([$call]));

        // 2 more turns at escalation 1 → final notice
        $this->assertSame('ok', $this->detector->check([$call])); // turnsSinceEscalation = 1
        $this->assertSame('final_notice', $this->detector->check([$call])); // turnsSinceEscalation = 2

        // 2 more turns at escalation 2 → force return
        $this->assertSame('ok', $this->detector->check([$call])); // turnsSinceEscalation = 1
        $this->assertSame('force_return', $this->detector->check([$call])); // turnsSinceEscalation = 2
    }

    public function test_recovery_resets_escalation(): void
    {
        $same = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "same"}');

        // Trigger nudge
        $this->detector->check([$same]);
        $this->detector->check([$same]);
        $this->assertSame('nudge', $this->detector->check([$same]));

        for ($i = 0; $i < 8; $i++) {
            $unique = new ToolCall(id: "tc_{$i}", name: 'tool_'.$i, arguments: json_encode(['pattern' => "unique_{$i}"]));
            $this->detector->check([$unique]);
        }

        $this->assertSame(0, $this->detector->getEscalation());
    }

    public function test_reset_clears_all_state(): void
    {
        $call = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "foo"}');

        // Build up state
        $this->detector->check([$call]);
        $this->detector->check([$call]);
        $this->detector->check([$call]);
        $this->assertSame(1, $this->detector->getEscalation());
        $this->assertNotEmpty($this->detector->getWindow());

        // Reset
        $this->detector->reset();
        $this->assertSame(0, $this->detector->getEscalation());
        $this->assertEmpty($this->detector->getWindow());

        // Same calls should require 3 rounds again before nudge
        $this->assertSame('ok', $this->detector->check([$call]));
        $this->assertSame('ok', $this->detector->check([$call]));
        $this->assertSame('nudge', $this->detector->check([$call]));
    }

    public function test_window_size_limits_history(): void
    {
        // Fill window with diverse calls, then repeat one
        for ($i = 0; $i < 8; $i++) {
            $call = new ToolCall(id: "tc_{$i}", name: 'tool_'.$i, arguments: json_encode(['pattern' => "unique_{$i}"]));
            $this->detector->check([$call]);
        }

        // Window should be exactly 8
        $this->assertCount(8, $this->detector->getWindow());

        // Adding more should push old ones out
        $newCall = new ToolCall(id: 'tc_new', name: 'grep', arguments: '{"pattern": "new"}');
        $this->detector->check([$newCall]);
        $this->assertCount(8, $this->detector->getWindow());
    }

    public function test_extract_last_assistant_text(): void
    {
        $history = new ConversationHistory;
        $history->addUser('question');
        $history->addAssistant('first answer');
        $history->addUser('follow up');
        $history->addAssistant('second answer');

        $this->assertSame('second answer', $this->detector->extractLastAssistantText($history));
    }

    public function test_extract_last_assistant_text_empty_history(): void
    {
        $history = new ConversationHistory;
        $this->assertSame('(no response generated)', $this->detector->extractLastAssistantText($history));
    }

    public function test_custom_window_size_and_threshold(): void
    {
        $detector = new StuckDetector(windowSize: 4, repetitionThreshold: 2);
        $call = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "foo"}');

        // With threshold=2, just 2 identical calls should trigger nudge
        $this->assertSame('ok', $detector->check([$call]));
        $this->assertSame('nudge', $detector->check([$call]));
    }

    public function test_multi_tool_batch_fills_window(): void
    {
        $calls = [];
        for ($i = 0; $i < 4; $i++) {
            $calls[] = new ToolCall(id: "tc_{$i}", name: 'grep', arguments: '{"pattern": "same"}');
        }

        // A single batch of 4 identical calls should trigger nudge (3+ in window)
        $this->assertSame('nudge', $this->detector->check($calls));
    }

    public function test_large_batch_detects_repeated_prefix_before_final_window_slice(): void
    {
        $calls = [
            new ToolCall(id: 'tc_same_1', name: 'grep', arguments: '{"pattern": "same"}'),
            new ToolCall(id: 'tc_same_2', name: 'grep', arguments: '{"pattern": "same"}'),
            new ToolCall(id: 'tc_same_3', name: 'grep', arguments: '{"pattern": "same"}'),
        ];
        for ($i = 0; $i < 8; $i++) {
            $calls[] = new ToolCall(id: "tc_unique_{$i}", name: 'tool_'.$i, arguments: json_encode(['pattern' => "unique_{$i}"]));
        }

        $this->assertSame('nudge', $this->detector->check($calls));
        $this->assertCount(8, $this->detector->getWindow());
    }

    public function test_latest_tied_signature_is_treated_as_stuck(): void
    {
        $detector = new StuckDetector(windowSize: 6, repetitionThreshold: 2, cooldownThreshold: 10);
        $A = new ToolCall(id: 'tc_a', name: 'grep', arguments: '{"pattern": "a"}');
        $B = new ToolCall(id: 'tc_b', name: 'glob', arguments: '{"pattern": "b"}');

        $this->assertSame('ok', $detector->check([$A]));
        $this->assertSame('nudge', $detector->check([$A]));
        $this->assertSame('ok', $detector->check([$B]));
        $this->assertSame('ok', $detector->check([$B]));
        $this->assertSame('final_notice', $detector->check([$B]));
    }

    public function test_cooldown_does_not_reset_while_repeated_pattern_remains_in_window(): void
    {
        $A = new ToolCall(id: 'tc_a', name: 'grep', arguments: '{"pattern": "a"}');
        $B = new ToolCall(id: 'tc_b', name: 'glob', arguments: '{"pattern": "b"}');

        $this->assertSame('ok', $this->detector->check([$A]));
        $this->assertSame('ok', $this->detector->check([$A]));
        $this->assertSame('nudge', $this->detector->check([$A]));
        $this->assertSame('ok', $this->detector->check([$B]));
        $this->assertSame('ok', $this->detector->check([$B]));

        $this->assertSame(1, $this->detector->getEscalation());
    }

    public function test_oscillation_pattern_detected(): void
    {
        // Small window + quick cooldown so As age out and Bs trigger a second nudge
        $detector = new StuckDetector(windowSize: 4, repetitionThreshold: 3, cooldownThreshold: 1);
        $A = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "same"}');
        $B = new ToolCall(id: 'tc_2', name: 'glob', arguments: '{"pattern": "*.txt"}');

        $this->assertSame('ok', $detector->check([$A]));      // window=[A]
        $this->assertSame('ok', $detector->check([$A]));      // window=[A,A]
        $this->assertSame('nudge', $detector->check([$A]));   // window=[A,A,A] → A=3, nudge #1
        $this->assertSame('ok', $detector->check([$B]));      // window=[A,A,A,B] → A=3, still stuck, turnsSince=1
        $this->assertSame('ok', $detector->check([$B]));      // window=[A,A,B,B] → max=2, diverse → reset (cooldownThreshold=1)
        $this->assertSame('nudge', $detector->check([$B]));   // window=[A,B,B,B] → B=3, nudge #2
    }

    public function test_single_diverse_call_does_not_fully_reset_escalation(): void
    {
        // Small window so As age out after B calls, default cooldownThreshold=2
        $detector = new StuckDetector(windowSize: 4, repetitionThreshold: 3);
        $A = new ToolCall(id: 'tc_1', name: 'grep', arguments: '{"pattern": "same"}');
        $B = new ToolCall(id: 'tc_2', name: 'glob', arguments: '{"pattern": "*.txt"}');

        $this->assertSame('ok', $detector->check([$A]));      // window=[A]
        $this->assertSame('ok', $detector->check([$A]));      // window=[A,A]
        $this->assertSame('nudge', $detector->check([$A]));   // window=[A,A,A] → nudge, escalation=1
        $this->assertSame('ok', $detector->check([$B]));      // window=[A,A,A,B] → A=3 remains in window, no cooldown
        $this->assertSame(1, $detector->getEscalation());
        $this->assertSame('ok', $detector->check([$B]));      // window=[A,A,B,B] → max=2, cooldown=1
        $this->assertSame(1, $detector->getEscalation());     // Not reset — need 2 truly diverse windows

        $C = new ToolCall(id: 'tc_3', name: 'bash', arguments: '{"command": "pwd"}');
        $this->assertSame('ok', $detector->check([$C]));      // window=[A,B,B,C] → max=2, cooldown=2 → reset
        $this->assertSame(0, $detector->getEscalation());
    }
}

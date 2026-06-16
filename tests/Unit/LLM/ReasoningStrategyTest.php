<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\ReasoningStrategy;
use PHPUnit\Framework\TestCase;

final class ReasoningStrategyTest extends TestCase
{
    public function test_glm_high_effort_uses_high_thinking_mode(): void
    {
        $this->assertSame([
            'thinking' => [
                'type' => 'enabled',
                'reasoning_effort' => 'high',
            ],
        ], ReasoningStrategy::requestParams('z', 'high'));
    }

    public function test_glm_low_and_medium_effort_map_to_high_thinking_mode(): void
    {
        $this->assertSame('high', ReasoningStrategy::requestParams('z', 'low')['thinking']['reasoning_effort']);
        $this->assertSame('high', ReasoningStrategy::requestParams('z-api', 'medium')['thinking']['reasoning_effort']);
    }

    public function test_glm_max_effort_uses_max_thinking_mode(): void
    {
        $this->assertSame([
            'thinking' => [
                'type' => 'enabled',
                'reasoning_effort' => 'max',
            ],
        ], ReasoningStrategy::requestParams('z', 'max'));
    }

    public function test_glm_off_explicitly_disables_thinking(): void
    {
        $this->assertSame([
            'thinking' => ['type' => 'disabled'],
        ], ReasoningStrategy::requestParams('z', 'off'));
    }

    public function test_openai_max_effort_is_clamped_to_high(): void
    {
        $this->assertSame(['reasoning_effort' => 'high'], ReasoningStrategy::requestParams('openai', 'max'));
    }
}

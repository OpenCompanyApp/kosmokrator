<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use OpenCompany\PrismRelay\Caching\PromptCachePlanner;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

final class PromptCachePlannerTest extends TestCase
{
    public function test_ephemeral_provider_marks_last_mapped_tool_schema_cacheable(): void
    {
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'read']],
            ['type' => 'function', 'function' => ['name' => 'edit']],
        ];

        $plan = PromptCachePlanner::plan(
            provider: 'openrouter',
            systemPrompts: [new SystemMessage('system')],
            messages: [],
            tools: $tools,
        );

        $this->assertArrayNotHasKey('cache_control', $plan->tools[0]);
        $this->assertSame(['type' => 'ephemeral'], $plan->tools[1]['cache_control']);
    }

    public function test_ephemeral_provider_marks_last_prism_tool_cacheable_without_mutating_original(): void
    {
        $first = (new Tool)->as('read')->for('Read')->using(fn () => '');
        $second = (new Tool)->as('edit')->for('Edit')->using(fn () => '');

        $plan = PromptCachePlanner::plan(
            provider: 'anthropic',
            systemPrompts: [new SystemMessage('system')],
            messages: [],
            tools: [$first, $second],
        );

        $this->assertSame([], $first->providerOptions());
        $this->assertSame([], $second->providerOptions());
        $this->assertSame([], $plan->tools[0]->providerOptions());
        $this->assertSame(['cacheType' => 'ephemeral'], $plan->tools[1]->providerOptions());
        $this->assertNotSame($second, $plan->tools[1]);
    }

    public function test_non_ephemeral_provider_keeps_tools_without_explicit_cache_marker(): void
    {
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'read']],
        ];

        $plan = PromptCachePlanner::plan(
            provider: 'openai',
            systemPrompts: [new SystemMessage('system')],
            messages: [],
            tools: $tools,
        );

        $this->assertSame($tools, $plan->tools);
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\PromptFrameBuilder;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

final class PromptFrameBuilderTest extends TestCase
{
    public function test_empty_string_returns_empty_array(): void
    {
        $result = PromptFrameBuilder::splitSystemPrompt('');

        $this->assertSame([], $result);
    }

    public function test_prompt_without_volatile_marker_returns_single_system_message(): void
    {
        $prompt = 'You are a helpful assistant. Follow all instructions carefully.';

        $result = PromptFrameBuilder::splitSystemPrompt($prompt);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(SystemMessage::class, $result[0]);
        $this->assertSame($prompt, $result[0]->content);
    }

    public function test_prompt_with_marker_splits_into_two_system_messages(): void
    {
        $stable = 'You are a helpful assistant.';
        $volatile = '- Do something';
        $prompt = $stable."\n\n## Current Tasks\n".$volatile;

        $result = PromptFrameBuilder::splitSystemPrompt($prompt);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(SystemMessage::class, $result[0]);
        $this->assertInstanceOf(SystemMessage::class, $result[1]);
    }

    public function test_stable_prefix_content_is_correct(): void
    {
        $stable = 'You are a helpful assistant.';
        $volatile = '- Do something';
        $prompt = $stable."\n\n## Current Tasks\n".$volatile;

        $result = PromptFrameBuilder::splitSystemPrompt($prompt);

        $this->assertSame($stable, $result[0]->content);
    }

    public function test_volatile_tail_content_is_correct(): void
    {
        $stable = 'You are a helpful assistant.';
        $volatile = '- Do something';
        $prompt = $stable."\n\n## Current Tasks\n".$volatile;

        $result = PromptFrameBuilder::splitSystemPrompt($prompt);

        // substr($splitOffset + 2) skips the leading "\n\n" of the marker
        $this->assertSame("## Current Tasks\n".$volatile, $result[1]->content);
    }

    public function test_multiple_markers_only_first_one_splits(): void
    {
        $stable = 'You are a helpful assistant.';
        $volatile = "- Task one\n\n## Current Tasks\n- Task two";
        $prompt = $stable."\n\n## Current Tasks\n".$volatile;

        $result = PromptFrameBuilder::splitSystemPrompt($prompt);

        $this->assertCount(2, $result);
        $this->assertSame($stable, $result[0]->content);
        $this->assertSame("## Current Tasks\n".$volatile, $result[1]->content);
    }

    public function test_marker_at_beginning_of_string_returns_single_message(): void
    {
        // Marker starts at offset 0, which triggers the $splitOffset <= 0 guard
        $prompt = "\n\n## Current Tasks\n- Do something";

        $result = PromptFrameBuilder::splitSystemPrompt($prompt);

        $this->assertCount(1, $result);
        $this->assertSame($prompt, $result[0]->content);
    }
}

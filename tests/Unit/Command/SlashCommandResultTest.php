<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandResult;
use PHPUnit\Framework\TestCase;

class SlashCommandResultTest extends TestCase
{
    public function test_continue_returns_result_with_continue_action_and_null_input(): void
    {
        $result = SlashCommandResult::continue();

        $this->assertSame(SlashCommandAction::Continue, $result->action);
        $this->assertNull($result->input);
    }

    public function test_quit_returns_result_with_quit_action_and_null_input(): void
    {
        $result = SlashCommandResult::quit();

        $this->assertSame(SlashCommandAction::Quit, $result->action);
        $this->assertNull($result->input);
    }

    public function test_inject_returns_result_with_inject_action_and_set_input(): void
    {
        $result = SlashCommandResult::inject('hello world');

        $this->assertSame(SlashCommandAction::Inject, $result->action);
        $this->assertSame('hello world', $result->input);
    }

    public function test_class_is_readonly(): void
    {
        $ref = new \ReflectionClass(SlashCommandResult::class);

        $this->assertTrue($ref->isReadOnly(), 'SlashCommandResult should be a readonly class');
    }

    public function test_constructor_is_private(): void
    {
        $ref = new \ReflectionClass(SlashCommandResult::class);
        $constructor = $ref->getConstructor();

        $this->assertTrue($constructor->isPrivate(), 'Constructor should be private');
    }

    public function test_action_property_is_public(): void
    {
        $ref = new \ReflectionClass(SlashCommandResult::class);
        $prop = $ref->getProperty('action');

        $this->assertTrue($prop->isPublic(), 'action property should be public');
    }

    public function test_input_property_is_public(): void
    {
        $ref = new \ReflectionClass(SlashCommandResult::class);
        $prop = $ref->getProperty('input');

        $this->assertTrue($prop->isPublic(), 'input property should be public');
    }

    public function test_inject_with_empty_string(): void
    {
        $result = SlashCommandResult::inject('');

        $this->assertSame(SlashCommandAction::Inject, $result->action);
        $this->assertSame('', $result->input);
    }
}

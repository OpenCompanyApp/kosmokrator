<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandResult;
use PHPUnit\Framework\TestCase;

class SlashCommandResultTest extends TestCase
{
    public function testContinueReturnsResultWithContinueActionAndNullInput(): void
    {
        $result = SlashCommandResult::continue();

        $this->assertSame(SlashCommandAction::Continue, $result->action);
        $this->assertNull($result->input);
    }

    public function testQuitReturnsResultWithQuitActionAndNullInput(): void
    {
        $result = SlashCommandResult::quit();

        $this->assertSame(SlashCommandAction::Quit, $result->action);
        $this->assertNull($result->input);
    }

    public function testInjectReturnsResultWithInjectActionAndSetInput(): void
    {
        $result = SlashCommandResult::inject('hello world');

        $this->assertSame(SlashCommandAction::Inject, $result->action);
        $this->assertSame('hello world', $result->input);
    }

    public function testClassIsReadonly(): void
    {
        $ref = new \ReflectionClass(SlashCommandResult::class);

        $this->assertTrue($ref->isReadOnly(), 'SlashCommandResult should be a readonly class');
    }

    public function testConstructorIsPrivate(): void
    {
        $ref = new \ReflectionClass(SlashCommandResult::class);
        $constructor = $ref->getConstructor();

        $this->assertTrue($constructor->isPrivate(), 'Constructor should be private');
    }

    public function testActionPropertyIsPublic(): void
    {
        $ref = new \ReflectionClass(SlashCommandResult::class);
        $prop = $ref->getProperty('action');

        $this->assertTrue($prop->isPublic(), 'action property should be public');
    }

    public function testInputPropertyIsPublic(): void
    {
        $ref = new \ReflectionClass(SlashCommandResult::class);
        $prop = $ref->getProperty('input');

        $this->assertTrue($prop->isPublic(), 'input property should be public');
    }

    public function testInjectWithEmptyString(): void
    {
        $result = SlashCommandResult::inject('');

        $this->assertSame(SlashCommandAction::Inject, $result->action);
        $this->assertSame('', $result->input);
    }
}

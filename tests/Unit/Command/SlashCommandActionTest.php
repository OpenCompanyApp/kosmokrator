<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Kosmokrator\Command\SlashCommandAction;
use PHPUnit\Framework\TestCase;

class SlashCommandActionTest extends TestCase
{
    public function testCasesReturnsAllThreeCases(): void
    {
        $cases = SlashCommandAction::cases();

        $this->assertCount(3, $cases);
        $this->assertSame(SlashCommandAction::Continue, $cases[0]);
        $this->assertSame(SlashCommandAction::Quit, $cases[1]);
        $this->assertSame(SlashCommandAction::Inject, $cases[2]);
    }

    public function testContinueCaseExists(): void
    {
        $case = SlashCommandAction::Continue;

        $this->assertInstanceOf(SlashCommandAction::class, $case);
        $this->assertSame('Continue', $case->name);
    }

    public function testQuitCaseExists(): void
    {
        $case = SlashCommandAction::Quit;

        $this->assertInstanceOf(SlashCommandAction::class, $case);
        $this->assertSame('Quit', $case->name);
    }

    public function testInjectCaseExists(): void
    {
        $case = SlashCommandAction::Inject;

        $this->assertInstanceOf(SlashCommandAction::class, $case);
        $this->assertSame('Inject', $case->name);
    }

    public function testMatchExpressionOnContinue(): void
    {
        $result = match (SlashCommandAction::Continue) {
            SlashCommandAction::Continue => 'continue',
            SlashCommandAction::Quit => 'quit',
            SlashCommandAction::Inject => 'inject',
        };

        $this->assertSame('continue', $result);
    }

    public function testMatchExpressionOnQuit(): void
    {
        $result = match (SlashCommandAction::Quit) {
            SlashCommandAction::Continue => 'continue',
            SlashCommandAction::Quit => 'quit',
            SlashCommandAction::Inject => 'inject',
        };

        $this->assertSame('quit', $result);
    }

    public function testMatchExpressionOnInject(): void
    {
        $result = match (SlashCommandAction::Inject) {
            SlashCommandAction::Continue => 'continue',
            SlashCommandAction::Quit => 'quit',
            SlashCommandAction::Inject => 'inject',
        };

        $this->assertSame('inject', $result);
    }

    public function testEnumIsNotBacked(): void
    {
        $ref = new \ReflectionEnum(SlashCommandAction::class);

        $this->assertFalse($ref->isBacked(), 'SlashCommandAction should be a plain (non-backed) enum');
    }
}

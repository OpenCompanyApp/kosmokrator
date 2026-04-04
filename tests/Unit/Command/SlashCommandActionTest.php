<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Kosmokrator\Command\SlashCommandAction;
use PHPUnit\Framework\TestCase;

class SlashCommandActionTest extends TestCase
{
    public function test_cases_returns_all_three_cases(): void
    {
        $cases = SlashCommandAction::cases();

        $this->assertCount(3, $cases);
        $this->assertSame(SlashCommandAction::Continue, $cases[0]);
        $this->assertSame(SlashCommandAction::Quit, $cases[1]);
        $this->assertSame(SlashCommandAction::Inject, $cases[2]);
    }

    public function test_continue_case_exists(): void
    {
        $case = SlashCommandAction::Continue;

        $this->assertInstanceOf(SlashCommandAction::class, $case);
        $this->assertSame('Continue', $case->name);
    }

    public function test_quit_case_exists(): void
    {
        $case = SlashCommandAction::Quit;

        $this->assertInstanceOf(SlashCommandAction::class, $case);
        $this->assertSame('Quit', $case->name);
    }

    public function test_inject_case_exists(): void
    {
        $case = SlashCommandAction::Inject;

        $this->assertInstanceOf(SlashCommandAction::class, $case);
        $this->assertSame('Inject', $case->name);
    }

    public function test_match_expression_on_continue(): void
    {
        $result = match (SlashCommandAction::Continue) {
            SlashCommandAction::Continue => 'continue',
            SlashCommandAction::Quit => 'quit',
            SlashCommandAction::Inject => 'inject',
        };

        $this->assertSame('continue', $result);
    }

    public function test_match_expression_on_quit(): void
    {
        $result = match (SlashCommandAction::Quit) {
            SlashCommandAction::Continue => 'continue',
            SlashCommandAction::Quit => 'quit',
            SlashCommandAction::Inject => 'inject',
        };

        $this->assertSame('quit', $result);
    }

    public function test_match_expression_on_inject(): void
    {
        $result = match (SlashCommandAction::Inject) {
            SlashCommandAction::Continue => 'continue',
            SlashCommandAction::Quit => 'quit',
            SlashCommandAction::Inject => 'inject',
        };

        $this->assertSame('inject', $result);
    }

    public function test_enum_is_not_backed(): void
    {
        $ref = new \ReflectionEnum(SlashCommandAction::class);

        $this->assertFalse($ref->isBacked(), 'SlashCommandAction should be a plain (non-backed) enum');
    }
}

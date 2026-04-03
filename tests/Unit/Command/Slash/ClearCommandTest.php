<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command\Slash;

use Kosmokrator\Command\Slash\ClearCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandResult;
use PHPUnit\Framework\TestCase;

class ClearCommandTest extends TestCase
{
    private ClearCommand $command;

    protected function setUp(): void
    {
        $this->command = new ClearCommand;
    }

    public function test_name(): void
    {
        $this->assertSame('/clear', $this->command->name());
    }

    public function test_aliases(): void
    {
        $this->assertSame([], $this->command->aliases());
    }

    public function test_description(): void
    {
        $this->assertSame('Clear the terminal screen', $this->command->description());
    }

    public function test_immediate(): void
    {
        $this->assertFalse($this->command->immediate());
    }

    public function test_execute_outputs_ansi_escape_codes_and_returns_continue(): void
    {
        $ctx = $this->createMock(SlashCommandContext::class);

        ob_start();
        $result = $this->command->execute('', $ctx);
        $output = ob_get_clean();

        $this->assertSame("\033[2J\033[H", $output);
        $expected = SlashCommandResult::continue();
        $this->assertSame(SlashCommandAction::Continue, $result->action);
        $this->assertNull($result->input);
    }
}

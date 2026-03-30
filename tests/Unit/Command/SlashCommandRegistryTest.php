<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandRegistry;
use Kosmokrator\Command\SlashCommandResult;
use PHPUnit\Framework\TestCase;

class SlashCommandRegistryTest extends TestCase
{
    private SlashCommandRegistry $registry;

    private SlashCommand $command;

    protected function setUp(): void
    {
        $this->registry = new SlashCommandRegistry();

        $this->command = new class implements SlashCommand {
            public function name(): string
            {
                return '/test';
            }

            public function aliases(): array
            {
                return ['/t'];
            }

            public function description(): string
            {
                return 'Test command';
            }

            public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
            {
                return SlashCommandResult::continue();
            }
        };

        $this->registry->register($this->command);
    }

    public function test_resolve_by_name(): void
    {
        $resolved = $this->registry->resolve('/test');

        $this->assertSame($this->command, $resolved);
    }

    public function test_resolve_by_alias(): void
    {
        $resolved = $this->registry->resolve('/t');

        $this->assertSame($this->command, $resolved);
    }

    public function test_resolve_with_args(): void
    {
        $resolved = $this->registry->resolve('/test foo bar');

        $this->assertSame($this->command, $resolved);
    }

    public function test_resolve_unknown_returns_null(): void
    {
        $resolved = $this->registry->resolve('/unknown');

        $this->assertNull($resolved);
    }

    public function test_extract_args(): void
    {
        $forgetCommand = new class implements SlashCommand {
            public function name(): string
            {
                return '/forget';
            }

            public function aliases(): array
            {
                return [];
            }

            public function description(): string
            {
                return 'Forget command';
            }

            public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
            {
                return SlashCommandResult::continue();
            }
        };

        $this->registry->register($forgetCommand);

        $args = $this->registry->extractArgs('/forget 42', $forgetCommand);

        $this->assertSame('42', $args);
    }

    public function test_extract_args_no_args(): void
    {
        $forgetCommand = new class implements SlashCommand {
            public function name(): string
            {
                return '/forget';
            }

            public function aliases(): array
            {
                return [];
            }

            public function description(): string
            {
                return 'Forget command';
            }

            public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
            {
                return SlashCommandResult::continue();
            }
        };

        $this->registry->register($forgetCommand);

        $args = $this->registry->extractArgs('/forget', $forgetCommand);

        $this->assertSame('', $args);
    }

    public function test_all_returns_registered_commands(): void
    {
        $all = $this->registry->all();

        $this->assertCount(1, $all);
        $this->assertSame($this->command, $all[0]);
    }
}

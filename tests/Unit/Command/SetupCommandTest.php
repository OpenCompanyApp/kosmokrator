<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Container\Container;
use Kosmokrator\Command\SetupCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SetupCommandTest extends TestCase
{
    private SetupCommand $command;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $container = new Container;

        $this->command = new SetupCommand($container);

        $app = new Application;
        $app->addCommand($this->command);
        $this->tester = new CommandTester($this->command);
    }

    public function test_command_name_is_setup(): void
    {
        $this->assertSame('setup', $this->command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $this->assertSame('Configure KosmoKrator (API keys, provider, model)', $this->command->getDescription());
    }
}

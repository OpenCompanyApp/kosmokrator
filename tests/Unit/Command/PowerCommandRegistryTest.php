<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\Command\PowerCommandRegistry;
use Kosmokrator\UI\Ansi\AnsiAnimation;
use PHPUnit\Framework\TestCase;

class PowerCommandRegistryTest extends TestCase
{
    private PowerCommandRegistry $registry;

    private PowerCommand $unleash;

    private PowerCommand $trace;

    protected function setUp(): void
    {
        $this->registry = new PowerCommandRegistry;

        $this->unleash = $this->makeCommand(':unleash', [':swarm', ':nuke'], true);
        $this->trace = $this->makeCommand(':trace', [':debug'], true);

        $this->registry->register($this->unleash);
        $this->registry->register($this->trace);
    }

    public function test_resolve_by_name(): void
    {
        $this->assertSame($this->unleash, $this->registry->resolve(':unleash'));
    }

    public function test_resolve_by_alias(): void
    {
        $this->assertSame($this->unleash, $this->registry->resolve(':swarm'));
    }

    public function test_resolve_case_insensitive(): void
    {
        $this->assertSame($this->unleash, $this->registry->resolve(':UNLEASH'));
    }

    public function test_resolve_unknown_returns_null(): void
    {
        $this->assertNull($this->registry->resolve(':unknown'));
    }

    public function test_is_power_input(): void
    {
        $this->assertTrue($this->registry->isPowerInput(':unleash'));
        $this->assertTrue($this->registry->isPowerInput(':trace foo'));
        $this->assertFalse($this->registry->isPowerInput('/slash'));
        $this->assertFalse($this->registry->isPowerInput('plain text'));
    }

    public function test_parse_single_command(): void
    {
        $chain = $this->registry->parse(':unleash audit the codebase');

        $this->assertNotNull($chain);
        $this->assertCount(1, $chain);
        $this->assertSame($this->unleash, $chain[0][0]);
        $this->assertSame('audit the codebase', $chain[0][1]);
    }

    public function test_parse_single_command_no_args(): void
    {
        $deslop = $this->makeCommand(':deslop', [], false);
        $this->registry->register($deslop);

        $chain = $this->registry->parse(':deslop');

        $this->assertNotNull($chain);
        $this->assertCount(1, $chain);
        $this->assertSame($deslop, $chain[0][0]);
        $this->assertSame('', $chain[0][1]);
    }

    public function test_parse_combined_commands(): void
    {
        $chain = $this->registry->parse(':unleash audit :trace the auth bug');

        $this->assertNotNull($chain);
        $this->assertCount(2, $chain);
        $this->assertSame($this->unleash, $chain[0][0]);
        $this->assertSame('audit', $chain[0][1]);
        $this->assertSame($this->trace, $chain[1][0]);
        $this->assertSame('the auth bug', $chain[1][1]);
    }

    public function test_parse_combined_with_alias(): void
    {
        $chain = $this->registry->parse(':swarm audit :debug the bug');

        $this->assertNotNull($chain);
        $this->assertCount(2, $chain);
        $this->assertSame($this->unleash, $chain[0][0]);
        $this->assertSame($this->trace, $chain[1][0]);
    }

    public function test_parse_unknown_command_returns_null(): void
    {
        $chain = $this->registry->parse(':unknown something');

        $this->assertNull($chain);
    }

    public function test_parse_non_colon_input_returns_null(): void
    {
        $this->assertNull($this->registry->parse('hello'));
        $this->assertNull($this->registry->parse('/slash'));
        $this->assertNull($this->registry->parse(''));
    }

    public function test_parse_three_commands(): void
    {
        $deslop = $this->makeCommand(':deslop', [], false);
        $this->registry->register($deslop);

        $chain = $this->registry->parse(':unleash audit :trace bug :deslop');

        $this->assertNotNull($chain);
        $this->assertCount(3, $chain);
        $this->assertSame($this->unleash, $chain[0][0]);
        $this->assertSame('audit', $chain[0][1]);
        $this->assertSame($this->trace, $chain[1][0]);
        $this->assertSame('bug', $chain[1][1]);
        $this->assertSame($deslop, $chain[2][0]);
        $this->assertSame('', $chain[2][1]);
    }

    public function test_all_returns_registered_commands(): void
    {
        $all = $this->registry->all();

        $this->assertCount(2, $all);
    }

    public function test_colon_in_args_not_treated_as_command(): void
    {
        // A colon mid-word should not be treated as a command prefix
        $chain = $this->registry->parse(':unleash check http://example.com');

        $this->assertNotNull($chain);
        $this->assertCount(1, $chain);
        $this->assertSame('check http://example.com', $chain[0][1]);
    }

    private function makeCommand(string $name, array $aliases, bool $requiresArgs): PowerCommand
    {
        return new class($name, $aliases, $requiresArgs) implements PowerCommand
        {
            public function __construct(
                private readonly string $cmdName,
                private readonly array $cmdAliases,
                private readonly bool $cmdRequiresArgs,
            ) {}

            public function name(): string
            {
                return $this->cmdName;
            }

            public function aliases(): array
            {
                return $this->cmdAliases;
            }

            public function description(): string
            {
                return 'Test';
            }

            public function requiresArgs(): bool
            {
                return $this->cmdRequiresArgs;
            }

            public function animationClass(): string
            {
                return AnsiAnimation::class;
            }

            public function buildPrompt(string $args): string
            {
                return "prompt: {$args}";
            }
        };
    }
}

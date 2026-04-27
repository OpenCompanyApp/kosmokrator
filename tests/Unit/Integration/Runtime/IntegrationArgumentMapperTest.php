<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Integration\Runtime;

use Kosmokrator\Integration\Runtime\IntegrationArgumentMapper;
use PHPUnit\Framework\TestCase;

final class IntegrationArgumentMapperTest extends TestCase
{
    public function test_maps_kebab_cli_flags_to_snake_case_arguments(): void
    {
        $mapper = new IntegrationArgumentMapper;

        $args = $mapper->map([
            '--list-id=123',
            '--name',
            'Ship it',
            '--priority=2',
            '--archived=false',
            '--json',
        ]);

        $this->assertSame([
            'list_id' => 123,
            'name' => 'Ship it',
            'priority' => 2,
            'archived' => false,
        ], $args);
    }

    public function test_merges_json_payload_with_flag_overrides(): void
    {
        $mapper = new IntegrationArgumentMapper;

        $args = $mapper->map(
            ['--name=Override'],
            '{"list_id":"abc","name":"Original"}',
        );

        $this->assertSame([
            'list_id' => 'abc',
            'name' => 'Override',
        ], $args);
    }

    public function test_supports_repeated_arg_pairs(): void
    {
        $mapper = new IntegrationArgumentMapper;

        $args = $mapper->map(['--arg', 'list_id=123', '--arg', 'name=Ship it']);

        $this->assertSame([
            'list_id' => 123,
            'name' => 'Ship it',
        ], $args);
    }
}

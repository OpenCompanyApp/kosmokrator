<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Kosmokrator\Command\Concerns\InteractsWithMachineIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class MachineIOTest extends TestCase
{
    public function test_writes_pretty_json_with_throwing_encoder(): void
    {
        $helper = new class
        {
            use InteractsWithMachineIO;

            public function write(BufferedOutput $output, mixed $data): void
            {
                $this->writeJson($output, $data);
            }
        };
        $output = new BufferedOutput;

        $helper->write($output, ['success' => true, 'path' => '/tmp/kosmo']);

        $this->assertSame("{\n    \"success\": true,\n    \"path\": \"/tmp/kosmo\"\n}\n", $output->fetch());
    }

    public function test_reads_raw_flags_and_options_from_argv_input(): void
    {
        $helper = new class
        {
            use InteractsWithMachineIO;

            /**
             * @return array{json: bool, force: bool, account: ?string, missing: ?string}
             */
            public function inspect(): array
            {
                $tokens = $this->rawTokens(new ArgvInput([
                    'kosmo',
                    'integrations:call',
                    'clickup.get_task',
                    '--json',
                    '--account=prod',
                    '--force',
                ]));

                return [
                    'json' => $this->rawFlag($tokens, 'json'),
                    'force' => $this->rawFlag($tokens, 'force'),
                    'account' => $this->rawOption($tokens, 'account'),
                    'missing' => $this->rawOption($tokens, 'missing'),
                ];
            }
        };

        $this->assertSame([
            'json' => true,
            'force' => true,
            'account' => 'prod',
            'missing' => null,
        ], $helper->inspect());
    }

    public function test_bool_option_accepts_payload_values(): void
    {
        $helper = new class
        {
            use InteractsWithMachineIO;

            public function bool(mixed $payload): bool
            {
                return $this->boolOption(new ArrayInput([]), 'missing', $payload);
            }
        };

        $this->assertTrue($helper->bool(true));
        $this->assertTrue($helper->bool('true'));
        $this->assertTrue($helper->bool('on'));
        $this->assertFalse($helper->bool('off'));
    }
}

<?php

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\BashTool;
use PHPUnit\Framework\TestCase;

class BashToolHelloWorldTest extends TestCase
{
    public function test_echo_hello_world(): void
    {
        $tool = new BashTool;
        $result = $tool->execute(['command' => 'echo "Hello World"']);

        $this->assertTrue($result->success, 'Command should succeed');
        $this->assertStringContainsString('Hello World', $result->output, 'Output should contain Hello World');
        $this->assertStringContainsString('Exit code: 0', $result->output, 'Exit code should be 0');
    }
}

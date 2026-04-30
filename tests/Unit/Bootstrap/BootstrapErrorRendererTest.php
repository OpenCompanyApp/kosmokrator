<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Bootstrap;

use Kosmokrator\Bootstrap\BootstrapErrorRenderer;
use PHPUnit\Framework\TestCase;

final class BootstrapErrorRendererTest extends TestCase
{
    public function test_render_hides_stack_trace_by_default(): void
    {
        $stream = fopen('php://temp', 'w+');
        $error = new \RuntimeException("Provider failed\nStack trace:\n#0 /tmp/app.php(1): boot()");

        $status = BootstrapErrorRenderer::render($error, $stream);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame(1, $status);
        $this->assertIsString($output);
        $this->assertStringContainsString('Kosmo failed to start.', $output);
        $this->assertStringContainsString('Reason: Provider failed', $output);
        $this->assertStringContainsString('kosmo smoke:startup --json', $output);
        $this->assertStringNotContainsString('Stack trace', $output);
        $this->assertStringNotContainsString('#0', $output);
    }
}

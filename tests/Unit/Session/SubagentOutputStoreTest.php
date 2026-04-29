<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Session;

use Kosmokrator\Session\SubagentOutputStore;
use PHPUnit\Framework\TestCase;

final class SubagentOutputStoreTest extends TestCase
{
    public function test_write_returns_ref_bytes_and_preview_and_read_returns_full_output(): void
    {
        $root = sys_get_temp_dir().'/kosmo-output-store-'.bin2hex(random_bytes(4));
        $store = new SubagentOutputStore($root);
        $output = "# Report\n\n---\n\n- First useful line with details\n\nSecond line";

        $written = $store->write('session/one', 'agent:one', $output);

        $this->assertSame(strlen($output), $written['bytes']);
        $this->assertSame('First useful line with details', $written['preview']);
        $this->assertStringStartsWith($root, $written['ref']);
        $this->assertFileExists($written['ref']);
        $this->assertSame($output, $store->read($written['ref']));
    }

    public function test_write_sanitizes_session_and_agent_names(): void
    {
        $root = sys_get_temp_dir().'/kosmo-output-store-'.bin2hex(random_bytes(4));
        $store = new SubagentOutputStore($root);

        $written = $store->write('../session', '../agent', 'done');

        $this->assertStringStartsWith($root, $written['ref']);
        $this->assertStringNotContainsString('..', substr($written['ref'], strlen($root)));
        $this->assertSame('done', $store->read($written['ref']));
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding\Patch;

use Kosmokrator\Tool\Coding\Patch\PatchOperation;
use PHPUnit\Framework\TestCase;

class PatchOperationTest extends TestCase
{
    public function test_constructor_with_required_args_only(): void
    {
        $op = new PatchOperation('add', '/tmp/file.txt');

        $this->assertSame('add', $op->kind);
        $this->assertSame('/tmp/file.txt', $op->path);
    }

    public function test_default_values(): void
    {
        $op = new PatchOperation('update', '/tmp/file.txt');

        $this->assertSame([], $op->bodyLines);
        $this->assertNull($op->moveTo);
    }

    public function test_constructor_with_all_args(): void
    {
        $op = new PatchOperation(
            'update',
            '/tmp/old.txt',
            ['+hello', '-world'],
            '/tmp/new.txt',
        );

        $this->assertSame('update', $op->kind);
        $this->assertSame('/tmp/old.txt', $op->path);
        $this->assertSame(['+hello', '-world'], $op->bodyLines);
        $this->assertSame('/tmp/new.txt', $op->moveTo);
    }

    public function test_kind_add(): void
    {
        $op = new PatchOperation('add', '/tmp/new.txt', ['line1']);

        $this->assertSame('add', $op->kind);
    }

    public function test_kind_update(): void
    {
        $op = new PatchOperation('update', '/tmp/existing.txt');

        $this->assertSame('update', $op->kind);
    }

    public function test_kind_delete(): void
    {
        $op = new PatchOperation('delete', '/tmp/gone.txt');

        $this->assertSame('delete', $op->kind);
    }

    public function test_body_lines_can_be_populated(): void
    {
        $lines = ['@@ -1,3 +1,3 @@', '-old', '+new'];
        $op = new PatchOperation('update', '/tmp/file.txt', $lines);

        $this->assertSame($lines, $op->bodyLines);
    }

    public function test_body_lines_can_be_empty(): void
    {
        $op = new PatchOperation('delete', '/tmp/file.txt', []);

        $this->assertSame([], $op->bodyLines);
    }

    public function test_move_to_can_be_string_path(): void
    {
        $op = new PatchOperation('update', '/tmp/old.txt', [], '/tmp/renamed.txt');

        $this->assertSame('/tmp/renamed.txt', $op->moveTo);
    }

    public function test_move_to_can_be_null(): void
    {
        $op = new PatchOperation('update', '/tmp/file.txt', [], null);

        $this->assertNull($op->moveTo);
    }

    public function test_all_properties_are_readonly_accessible(): void
    {
        $op = new PatchOperation('add', '/tmp/a.txt', ['content'], '/tmp/b.txt');

        // Verify all properties are accessible via public readonly
        $this->assertSame('add', $op->kind);
        $this->assertSame('/tmp/a.txt', $op->path);
        $this->assertSame(['content'], $op->bodyLines);
        $this->assertSame('/tmp/b.txt', $op->moveTo);
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Coding;

use Kosmokrator\Tool\Coding\Patch\PatchParser;
use PHPUnit\Framework\TestCase;

class PatchParserTest extends TestCase
{
    public function test_parses_add_update_delete_and_move_operations(): void
    {
        $parser = new PatchParser;

        $operations = $parser->parse(<<<'PATCH'
*** Begin Patch
*** Add File: new.txt
+hello
*** Update File: old.txt
*** Move to: moved.txt
@@
-before
+after
*** Delete File: gone.txt
*** End Patch
PATCH);

        $this->assertCount(3, $operations);
        $this->assertSame('add', $operations[0]->kind);
        $this->assertSame('new.txt', $operations[0]->path);
        $this->assertSame(['hello'], $operations[0]->bodyLines);

        $this->assertSame('update', $operations[1]->kind);
        $this->assertSame('old.txt', $operations[1]->path);
        $this->assertSame('moved.txt', $operations[1]->moveTo);

        $this->assertSame('delete', $operations[2]->kind);
        $this->assertSame('gone.txt', $operations[2]->path);
    }

    public function test_extract_target_paths_includes_move_destination(): void
    {
        $parser = new PatchParser;

        $paths = $parser->extractTargetPaths(<<<'PATCH'
*** Begin Patch
*** Update File: old.txt
*** Move to: moved.txt
@@
-before
+after
*** End Patch
PATCH);

        $this->assertSame(['old.txt', 'moved.txt'], $paths);
    }

    public function test_rejects_missing_begin_marker(): void
    {
        $parser = new PatchParser;

        $this->expectException(\InvalidArgumentException::class);
        $parser->parse("*** Update File: foo\n*** End Patch");
    }
}

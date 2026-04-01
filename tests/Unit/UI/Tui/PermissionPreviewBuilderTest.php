<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\PermissionPreviewBuilder;
use PHPUnit\Framework\TestCase;

final class PermissionPreviewBuilderTest extends TestCase
{
    public function test_builds_bash_preview_with_command_scope_and_expected_result(): void
    {
        $preview = (new PermissionPreviewBuilder)->build('bash', [
            'command' => 'vendor/bin/phpunit tests/Unit/UI/TuiRendererTest.php',
        ]);

        $this->assertSame('Invocation Request', $preview['title']);
        $this->assertSame('Bash', $preview['tool_label']);
        $this->assertSame('Command', $preview['sections'][0]['label']);
        $this->assertSame('vendor/bin/phpunit tests/Unit/UI/TuiRendererTest.php', $preview['sections'][0]['lines'][0]);
        $this->assertSame('Scope', $preview['sections'][1]['label']);
        $this->assertStringContainsString('shell access', $preview['sections'][1]['lines'][0]);
        $this->assertSame('Expected result', $preview['sections'][2]['label']);
        $this->assertStringContainsString('tests', $preview['sections'][2]['lines'][0]);
    }

    public function test_builds_apply_patch_preview_with_files_and_patch_excerpt(): void
    {
        $patch = <<<'PATCH'
*** Begin Patch
*** Update File: src/UI/Tui/TuiRenderer.php
@@
- old line
+ new line
*** Add File: src/UI/Tui/Widget/PermissionPromptWidget.php
+ new file body
*** End Patch
PATCH;

        $preview = (new PermissionPreviewBuilder)->build('apply_patch', ['patch' => $patch]);

        $this->assertSame('Edit Approval', $preview['title']);
        $this->assertSame('Files', $preview['sections'][0]['label']);
        $this->assertSame('src/UI/Tui/TuiRenderer.php', $preview['sections'][0]['lines'][0]);
        $this->assertSame('src/UI/Tui/Widget/PermissionPromptWidget.php', $preview['sections'][0]['lines'][1]);
        $this->assertSame('Scope', $preview['sections'][1]['label']);
        $this->assertSame('writes workspace files', $preview['sections'][1]['lines'][0]);
        $this->assertSame('Preview', $preview['sections'][2]['label']);
        $this->assertContains('@@', $preview['sections'][2]['lines']);
        $this->assertContains('- old line', $preview['sections'][2]['lines']);
        $this->assertContains('+ new line', $preview['sections'][2]['lines']);
    }
}

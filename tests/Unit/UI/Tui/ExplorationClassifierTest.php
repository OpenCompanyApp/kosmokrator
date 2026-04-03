<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui;

use Kosmokrator\UI\Tui\ExplorationClassifier;
use PHPUnit\Framework\TestCase;

final class ExplorationClassifierTest extends TestCase
{
    public function test_groups_core_read_only_tools_into_omens(): void
    {
        $this->assertTrue(ExplorationClassifier::isOmensTool('file_read', ['path' => 'src/UI/Tui/TuiRenderer.php']));
        $this->assertTrue(ExplorationClassifier::isOmensTool('glob', ['pattern' => '*.php']));
        $this->assertTrue(ExplorationClassifier::isOmensTool('grep', ['pattern' => 'showToolCall']));
        $this->assertTrue(ExplorationClassifier::isOmensTool('memory_search', ['query' => 'tui']));
    }

    public function test_groups_simple_shell_probes_into_omens(): void
    {
        $this->assertTrue(ExplorationClassifier::isOmensTool('bash', ['command' => 'rg -n "showToolCall" src/UI']));
        $this->assertTrue(ExplorationClassifier::isOmensTool('bash', ['command' => 'git status --short']));
        $this->assertTrue(ExplorationClassifier::isOmensTool('bash', ['command' => 'sed -n \'1,120p\' src/UI/Tui/TuiRenderer.php']));
    }

    public function test_keeps_non_exploratory_bash_outside_omens(): void
    {
        $this->assertFalse(ExplorationClassifier::isOmensTool('bash', ['command' => 'vendor/bin/phpunit tests/Unit/UI/Tui/PermissionPreviewBuilderTest.php']));
        $this->assertFalse(ExplorationClassifier::isOmensTool('bash', ['command' => 'rg -n "showToolCall" src/UI | head']));
        $this->assertFalse(ExplorationClassifier::isOmensTool('bash', ['command' => '']));
    }
}

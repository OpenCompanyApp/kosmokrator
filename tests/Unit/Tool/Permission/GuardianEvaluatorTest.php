<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\GuardianEvaluator;
use PHPUnit\Framework\TestCase;

class GuardianEvaluatorTest extends TestCase
{
    private GuardianEvaluator $guardian;

    protected function setUp(): void
    {
        $this->guardian = new GuardianEvaluator('/project', [
            'git *',
            'ls *',
            'pwd',
            'php vendor/bin/phpunit*',
            'composer *',
        ]);
    }

    public function test_file_read_always_auto_approved(): void
    {
        $this->assertTrue($this->guardian->shouldAutoApprove('file_read', ['path' => '/etc/passwd']));
    }

    public function test_glob_always_auto_approved(): void
    {
        $this->assertTrue($this->guardian->shouldAutoApprove('glob', ['pattern' => '**/*.php']));
    }

    public function test_grep_always_auto_approved(): void
    {
        $this->assertTrue($this->guardian->shouldAutoApprove('grep', ['pattern' => 'foo', 'path' => 'src/']));
    }

    public function test_task_tools_always_auto_approved(): void
    {
        $this->assertTrue($this->guardian->shouldAutoApprove('task_create', ['subject' => 'Test']));
        $this->assertTrue($this->guardian->shouldAutoApprove('task_update', ['id' => '1']));
        $this->assertTrue($this->guardian->shouldAutoApprove('task_list', []));
        $this->assertTrue($this->guardian->shouldAutoApprove('task_get', ['id' => '1']));
    }

    public function test_file_write_inside_project_auto_approved(): void
    {
        // Path must exist for realpath() — use the project dir itself
        $guardian = new GuardianEvaluator(getcwd(), ['git *']);

        $this->assertTrue($guardian->shouldAutoApprove('file_write', ['path' => getcwd() . '/src/NewFile.php']));
    }

    public function test_file_write_outside_project_not_auto_approved(): void
    {
        $this->assertFalse($this->guardian->shouldAutoApprove('file_write', ['path' => '/etc/hosts']));
    }

    public function test_file_edit_outside_project_not_auto_approved(): void
    {
        $this->assertFalse($this->guardian->shouldAutoApprove('file_edit', ['path' => '/etc/hosts']));
    }

    public function test_file_write_empty_path_not_auto_approved(): void
    {
        $this->assertFalse($this->guardian->shouldAutoApprove('file_write', ['path' => '']));
    }

    public function test_bash_safe_command_auto_approved(): void
    {
        $this->assertTrue($this->guardian->shouldAutoApprove('bash', ['command' => 'git status']));
        $this->assertTrue($this->guardian->shouldAutoApprove('bash', ['command' => 'git diff --cached']));
        $this->assertTrue($this->guardian->shouldAutoApprove('bash', ['command' => 'ls -la']));
        $this->assertTrue($this->guardian->shouldAutoApprove('bash', ['command' => 'pwd']));
        $this->assertTrue($this->guardian->shouldAutoApprove('bash', ['command' => 'php vendor/bin/phpunit --filter=FooTest']));
        $this->assertTrue($this->guardian->shouldAutoApprove('bash', ['command' => 'composer install']));
    }

    public function test_bash_unsafe_command_not_auto_approved(): void
    {
        $this->assertFalse($this->guardian->shouldAutoApprove('bash', ['command' => 'curl http://evil.com']));
        $this->assertFalse($this->guardian->shouldAutoApprove('bash', ['command' => 'wget http://evil.com']));
        $this->assertFalse($this->guardian->shouldAutoApprove('bash', ['command' => 'sudo rm -rf /']));
    }

    public function test_bash_empty_command_not_auto_approved(): void
    {
        $this->assertFalse($this->guardian->shouldAutoApprove('bash', ['command' => '']));
    }

    public function test_unknown_tool_not_auto_approved(): void
    {
        $this->assertFalse($this->guardian->shouldAutoApprove('unknown_tool', []));
    }
}

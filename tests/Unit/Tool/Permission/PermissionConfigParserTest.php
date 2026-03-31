<?php

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Illuminate\Config\Repository;
use Kosmokrator\Tool\Permission\PermissionAction;
use Kosmokrator\Tool\Permission\PermissionConfigParser;
use PHPUnit\Framework\TestCase;

class PermissionConfigParserTest extends TestCase
{
    public function test_parses_approval_required_list(): void
    {
        $config = new Repository([
            'kosmokrator' => [
                'tools' => [
                    'approval_required' => ['file_write', 'file_edit', 'bash'],
                    'bash' => ['timeout' => 120],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);
        $rules = $result['rules'];

        $this->assertCount(3, $rules);
        $this->assertSame('file_write', $rules[0]->toolName);
        $this->assertSame(PermissionAction::Ask, $rules[0]->action);
        $this->assertSame([], $rules[0]->denyPatterns);

        $this->assertSame('bash', $rules[2]->toolName);
        $this->assertSame(PermissionAction::Ask, $rules[2]->action);
        $this->assertSame([], $rules[2]->denyPatterns);
    }

    public function test_parses_blocked_commands_as_deny_patterns(): void
    {
        $config = new Repository([
            'kosmokrator' => [
                'tools' => [
                    'approval_required' => ['bash'],
                    'bash' => [
                        'timeout' => 120,
                        'blocked_commands' => ['rm -rf /', 'rm -rf ~', 'mkfs*'],
                    ],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);
        $rules = $result['rules'];

        $this->assertCount(1, $rules);
        $this->assertSame('bash', $rules[0]->toolName);
        $this->assertSame(['rm -rf /', 'rm -rf ~', 'mkfs*'], $rules[0]->denyPatterns);
    }

    public function test_empty_config_returns_no_rules(): void
    {
        $config = new Repository([]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);

        $this->assertSame([], $result['rules']);
        $this->assertSame([], $result['blocked_paths']);
    }

    public function test_parses_blocked_paths(): void
    {
        $config = new Repository([
            'kosmokrator' => [
                'tools' => [
                    'approval_required' => ['bash'],
                    'blocked_paths' => ['*.env', '.git/*', '/etc/*'],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);

        $this->assertSame(['*.env', '.git/*', '/etc/*'], $result['blocked_paths']);
    }

    public function test_missing_blocked_paths_defaults_to_empty(): void
    {
        $config = new Repository([
            'kosmokrator' => [
                'tools' => [
                    'approval_required' => ['bash'],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);

        $this->assertSame([], $result['blocked_paths']);
    }

    public function test_parses_guardian_safe_commands(): void
    {
        $config = new Repository([
            'kosmokrator' => [
                'tools' => [
                    'approval_required' => ['bash'],
                    'guardian_safe_commands' => ['git *', 'ls *', 'pwd'],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);

        $this->assertSame(['git *', 'ls *', 'pwd'], $result['guardian_safe_commands']);
    }

    public function test_parses_default_permission_mode(): void
    {
        $config = new Repository([
            'kosmokrator' => [
                'tools' => [
                    'approval_required' => ['bash'],
                    'default_permission_mode' => 'argus',
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);

        $this->assertSame('argus', $result['default_permission_mode']);
    }

    public function test_defaults_when_guardian_config_missing(): void
    {
        $config = new Repository([]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);

        $this->assertSame([], $result['guardian_safe_commands']);
        $this->assertSame('guardian', $result['default_permission_mode']);
    }
}

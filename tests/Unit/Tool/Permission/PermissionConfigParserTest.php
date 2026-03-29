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

        $parser = new PermissionConfigParser();
        $rules = $parser->parse($config);

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

        $parser = new PermissionConfigParser();
        $rules = $parser->parse($config);

        $this->assertCount(1, $rules);
        $this->assertSame('bash', $rules[0]->toolName);
        $this->assertSame(['rm -rf /', 'rm -rf ~', 'mkfs*'], $rules[0]->denyPatterns);
    }

    public function test_empty_config_returns_no_rules(): void
    {
        $config = new Repository([]);

        $parser = new PermissionConfigParser();
        $rules = $parser->parse($config);

        $this->assertSame([], $rules);
    }
}

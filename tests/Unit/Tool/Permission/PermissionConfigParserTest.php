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
            'kosmo' => [
                'tools' => [
                    'approval_required' => ['file_write', 'file_edit', 'bash'],
                    'bash' => ['timeout' => 120],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);
        $rules = $result['rules'];

        // 14 default safe tools (Allow) + 3 approval_required (Ask)
        $askRules = array_values(array_filter($rules, fn ($r) => $r->action === PermissionAction::Ask));
        $this->assertCount(3, $askRules);
        $this->assertSame('file_write', $askRules[0]->toolName);
        $this->assertSame([], $askRules[0]->denyPatterns);
        $this->assertSame('bash', $askRules[2]->toolName);
        $this->assertContains('rm -rf *', $askRules[2]->denyPatterns);
    }

    public function test_parses_blocked_commands_as_deny_patterns(): void
    {
        $config = new Repository([
            'kosmo' => [
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

        $askRules = array_values(array_filter($rules, fn ($r) => $r->action === PermissionAction::Ask));
        $this->assertCount(1, $askRules);
        $this->assertSame('bash', $askRules[0]->toolName);
        $this->assertContains('rm -rf *', $askRules[0]->denyPatterns);
        $this->assertContains('rm -rf /', $askRules[0]->denyPatterns);
        $this->assertContains('rm -rf ~', $askRules[0]->denyPatterns);
        $this->assertContains('mkfs*', $askRules[0]->denyPatterns);
    }

    public function test_shell_start_and_shell_write_also_receive_blocked_command_patterns(): void
    {
        $config = new Repository([
            'kosmo' => [
                'tools' => [
                    'approval_required' => ['shell_start', 'shell_write'],
                    'bash' => [
                        'blocked_commands' => ['rm -rf /', 'mkfs*'],
                    ],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);
        $rules = $result['rules'];

        $askRules = array_values(array_filter($rules, fn ($r) => $r->action === PermissionAction::Ask));
        $this->assertCount(2, $askRules);
        $this->assertContains('rm -rf *', $askRules[0]->denyPatterns);
        $this->assertContains('rm -rf /', $askRules[0]->denyPatterns);
        $this->assertContains('mkfs*', $askRules[0]->denyPatterns);
        $this->assertSame($askRules[0]->denyPatterns, $askRules[1]->denyPatterns);
    }

    public function test_default_blocked_commands_are_applied_when_config_omits_them(): void
    {
        $config = new Repository([
            'kosmo' => [
                'tools' => [
                    'approval_required' => ['bash'],
                    'bash' => ['timeout' => 120],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);
        $askRules = array_values(array_filter($result['rules'], fn ($r) => $r->action === PermissionAction::Ask));

        $this->assertContains('rm -rf *', $askRules[0]->denyPatterns);
        $this->assertContains('git reset --hard*', $askRules[0]->denyPatterns);
        $this->assertContains('kubectl delete *', $askRules[0]->denyPatterns);
    }

    public function test_empty_config_returns_default_safe_rules(): void
    {
        $config = new Repository([]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);

        // Default safe tools are still included even with empty config
        $this->assertNotEmpty($result['rules']);
        $allowRules = array_filter($result['rules'], fn ($r) => $r->action === PermissionAction::Allow);
        $this->assertNotEmpty($allowRules);
        $this->assertSame([], $result['blocked_paths']);
    }

    public function test_parses_blocked_paths(): void
    {
        $config = new Repository([
            'kosmo' => [
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
            'kosmo' => [
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
            'kosmo' => [
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
            'kosmo' => [
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

    public function test_safe_tools_parsed_as_allow_rules(): void
    {
        $config = new Repository([
            'kosmo' => [
                'tools' => [
                    'safe_tools' => ['file_read', 'glob', 'grep'],
                    'approval_required' => ['bash'],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);
        $rules = $result['rules'];

        $allowRules = array_values(array_filter($rules, fn ($r) => $r->action === PermissionAction::Allow));
        $this->assertCount(3, $allowRules);
        $this->assertSame('file_read', $allowRules[0]->toolName);
        $this->assertSame('glob', $allowRules[1]->toolName);
        $this->assertSame('grep', $allowRules[2]->toolName);
    }

    public function test_safe_tools_come_before_ask_rules(): void
    {
        $config = new Repository([
            'kosmo' => [
                'tools' => [
                    'safe_tools' => ['file_read'],
                    'approval_required' => ['file_write'],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);
        $rules = $result['rules'];

        $this->assertCount(2, $rules);
        $this->assertSame(PermissionAction::Allow, $rules[0]->action);
        $this->assertSame('file_read', $rules[0]->toolName);
        $this->assertSame(PermissionAction::Ask, $rules[1]->action);
        $this->assertSame('file_write', $rules[1]->toolName);
    }

    public function test_custom_safe_tools_override_defaults(): void
    {
        $config = new Repository([
            'kosmo' => [
                'tools' => [
                    'safe_tools' => ['my_custom_tool'],
                ],
            ],
        ]);

        $parser = new PermissionConfigParser;
        $result = $parser->parse($config);
        $rules = $result['rules'];

        $this->assertCount(1, $rules);
        $this->assertSame('my_custom_tool', $rules[0]->toolName);
        $this->assertSame(PermissionAction::Allow, $rules[0]->action);
    }
}

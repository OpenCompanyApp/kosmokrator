<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Kosmokrator\Lua\LuaSandboxService;
use Lua\Sandbox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class McpCommandsTest extends TestCase
{
    private string $home;

    private string $project;

    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/kosmo-mcp-command-test-'.bin2hex(random_bytes(4));
        $this->home = $base.'/home';
        $this->project = $base.'/project';
        $this->root = dirname(__DIR__, 3);
        mkdir($this->home, 0777, true);
        mkdir($this->project, 0777, true);
    }

    public function test_headless_mcp_add_discover_trust_call_and_lua(): void
    {
        $server = $this->root.'/tests/fixtures/mcp/fake_stdio_server.php';

        $add = $this->runKosmo([
            'mcp:add', 'fake',
            '--project',
            '--type=stdio',
            '--command=php',
            "--arg={$server}",
            '--read=allow',
            '--write=allow',
            '--json',
        ]);
        $this->assertSame(0, $add['exit']);
        $this->assertTrue($add['json']['success']);
        $this->assertFileExists($this->project.'/.mcp.json');

        $toolsDenied = $this->runKosmo(['mcp:tools', 'fake', '--json']);
        $this->assertSame(1, $toolsDenied['exit']);
        $this->assertFalse($toolsDenied['json']['success']);
        $this->assertStringContainsString('not trusted', $toolsDenied['json']['error']);

        $trust = $this->runKosmo(['mcp:trust', 'fake', '--project', '--json']);
        $this->assertSame(0, $trust['exit']);
        $this->assertTrue($trust['json']['success']);

        $tools = $this->runKosmo(['mcp:tools', 'fake', '--json']);
        $this->assertSame(0, $tools['exit']);
        $this->assertSame('fake.echo', $tools['json']['tools'][0]['function']);

        $call = $this->runKosmo(['mcp:call', 'fake.echo', '--message=hello', '--json']);
        $this->assertSame(0, $call['exit']);
        $this->assertSame('hello', $call['json']['data']);

        $dynamic = $this->runKosmo(['mcp:fake', 'echo', '--message=dynamic', '--json']);
        $this->assertSame(0, $dynamic['exit']);
        $this->assertSame('dynamic', $dynamic['json']['data']);

        if (! $this->luaSandboxCapturesOutput()) {
            $this->markTestSkipped('Lua sandbox extension is not available.');
        }

        $lua = $this->runKosmo(['mcp:lua', '--eval', 'dump(app.mcp.fake.echo({message="lua"}))', '--json']);
        $this->assertSame(0, $lua['exit']);
        $this->assertSame('lua', $lua['json']['output']);

        $integrationLua = $this->runKosmo(['integrations:lua', '--eval', 'dump(app.mcp.fake.echo({message="shared"}))', '--json']);
        $this->assertSame(0, $integrationLua['exit']);
        $this->assertSame('shared', $integrationLua['json']['output']);
    }

    public function test_force_bypasses_headless_trust_and_permission_policy(): void
    {
        $server = $this->root.'/tests/fixtures/mcp/fake_stdio_server.php';
        file_put_contents($this->project.'/.mcp.json', json_encode([
            'mcpServers' => [
                'fake' => ['command' => 'php', 'args' => [$server]],
            ],
        ], JSON_PRETTY_PRINT));

        $blocked = $this->runKosmo(['mcp:call', 'fake.echo', '--message=nope', '--json']);
        $this->assertSame(1, $blocked['exit']);
        $this->assertStringContainsString('not trusted', $blocked['json']['error']);

        $forced = $this->runKosmo(['mcp:call', 'fake.echo', '--message=ok', '--force', '--json']);
        $this->assertSame(0, $forced['exit']);
        $this->assertSame('ok', $forced['json']['data']);
        $this->assertTrue($forced['json']['meta']['permission_bypassed']);

        if (! $this->luaSandboxCapturesOutput()) {
            $this->markTestSkipped('Lua sandbox extension is not available.');
        }

        $luaBlocked = $this->runKosmo(['mcp:lua', '--eval', 'dump(mcp.schema("fake.echo"))', '--json']);
        $this->assertSame(1, $luaBlocked['exit']);
        $this->assertStringContainsString('not trusted', $luaBlocked['json']['error']);

        $luaForced = $this->runKosmo(['mcp:lua', '--eval', 'dump(app.mcp.fake.echo({message="lua-force"}))', '--force', '--json']);
        $this->assertSame(0, $luaForced['exit']);
        $this->assertSame('lua-force', $luaForced['json']['output']);
    }

    public function test_import_and_export_support_mcpservers_and_vscode_servers_shapes(): void
    {
        $server = $this->root.'/tests/fixtures/mcp/fake_stdio_server.php';
        $source = $this->project.'/vscode-mcp.json';
        file_put_contents($source, json_encode([
            'servers' => [
                'fake' => ['type' => 'stdio', 'command' => 'php', 'args' => [$server]],
            ],
        ], JSON_PRETTY_PRINT));

        $import = $this->runKosmo(['mcp:import', $source, '--project', '--json']);
        $this->assertSame(0, $import['exit']);
        $this->assertSame(['fake'], $import['json']['imported']);

        $export = $this->runKosmo(['mcp:export', '--format=vscode', '--json']);
        $this->assertSame(0, $export['exit']);
        $this->assertArrayHasKey('servers', $export['json']['config']);
        $this->assertArrayHasKey('fake', $export['json']['config']['servers']);
    }

    public function test_gateway_export_and_install_write_claude_compatible_stdio_config(): void
    {
        $export = $this->runKosmo([
            'mcp:gateway:export',
            '--integration=plane',
            '--upstream=context7',
            '--write=deny',
            '--json',
        ]);

        $this->assertSame(0, $export['exit'], $export['output']);
        $server = $export['json']['config']['mcpServers']['kosmo'] ?? null;
        $this->assertIsArray($server);
        $this->assertSame('stdio', $server['type']);
        $this->assertContains('mcp:serve', $server['args']);
        $this->assertContains('--integration=plane', $server['args']);
        $this->assertContains('--upstream=context7', $server['args']);

        $install = $this->runKosmo([
            'mcp:gateway:install',
            '--integration=plane',
            '--upstream=context7',
            '--write=deny',
            '--json',
        ]);

        $this->assertSame(0, $install['exit'], $install['output']);
        $this->assertFileExists($this->project.'/.mcp.json');
        $installed = json_decode((string) file_get_contents($this->project.'/.mcp.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('mcp:serve', $installed['mcpServers']['kosmo']['args']);
        $this->assertContains('--integration=plane', $installed['mcpServers']['kosmo']['args']);
    }

    public function test_gateway_export_with_profile_does_not_override_profile_write_policy(): void
    {
        $export = $this->runKosmo([
            'mcp:gateway:export',
            '--profile=claude',
            '--json',
        ]);

        $this->assertSame(0, $export['exit'], $export['output']);
        $args = $export['json']['config']['mcpServers']['kosmo']['args'];
        $this->assertContains('--profile=claude', $args);
        $this->assertNotContains('--write=deny', $args);
    }

    public function test_gateway_serves_selected_hyphenated_upstream_mcp_tools_over_json_rpc(): void
    {
        $server = $this->root.'/tests/fixtures/mcp/fake_stdio_server.php';
        file_put_contents($this->project.'/.mcp.json', json_encode([
            'mcpServers' => [
                'fake-server' => ['command' => 'php', 'args' => [$server]],
                'kosmo' => ['command' => 'kosmo', 'args' => ['mcp:serve', '--upstream=fake-server']],
            ],
        ], JSON_PRETTY_PRINT));

        $process = new Process([
            'php', $this->root.'/bin/kosmo',
            'mcp:serve',
            '--upstream=fake-server',
            '--write=allow',
            '--force',
        ], $this->project, [
            'HOME' => $this->home,
        ]);
        $process->setInput(implode("\n", [
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp__fake_server__echo","arguments":{"message":"from gateway"}}}',
            '{"jsonrpc":"2.0","id":4,"method":"unknown/method"}',
            '',
        ]));
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $responses = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);

        $this->assertSame('kosmo', $responses[0]['result']['serverInfo']['name']);
        $toolNames = array_column($responses[1]['result']['tools'], 'name');
        $this->assertContains('mcp__fake_server__echo', $toolNames);
        $this->assertSame('from gateway', $responses[2]['result']['structuredContent']['value']);
        $this->assertSame(-32601, $responses[3]['error']['code']);
    }

    public function test_gateway_profile_write_policy_is_not_overridden_by_missing_cli_write_option(): void
    {
        $server = $this->root.'/tests/fixtures/mcp/fake_stdio_server.php';
        mkdir($this->project.'/.kosmo', 0777, true);
        file_put_contents($this->project.'/.mcp.json', json_encode([
            'mcpServers' => [
                'fake-server' => ['command' => 'php', 'args' => [$server]],
            ],
        ], JSON_PRETTY_PRINT));
        file_put_contents($this->project.'/.kosmo/config.yaml', <<<'YAML'
kosmo:
  mcp_gateway:
    profiles:
      claude:
        upstream_mcp:
          include: [fake-server]
        write_policy: allow
YAML);

        $process = new Process([
            'php', $this->root.'/bin/kosmo',
            'mcp:serve',
            '--profile=claude',
            '--force',
        ], $this->project, [
            'HOME' => $this->home,
        ]);
        $process->setInput(implode("\n", [
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            '',
        ]));
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $responses = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);

        $toolNames = array_column($responses[1]['result']['tools'], 'name');
        $this->assertContains('mcp__fake_server__create_issue', $toolNames);
    }

    public function test_parallel_headless_mcp_adds_do_not_lose_config_or_permissions(): void
    {
        $server = $this->root.'/tests/fixtures/mcp/fake_stdio_server.php';
        $processes = [];

        foreach (['one', 'two', 'three'] as $name) {
            $processes[$name] = new Process([
                'php', $this->root.'/bin/kosmo',
                'mcp:add', $name,
                '--project',
                '--type=stdio',
                '--command=php',
                "--arg={$server}",
                '--read=allow',
                '--write=allow',
                '--json',
            ], $this->project, [
                'HOME' => $this->home,
            ]);
            $processes[$name]->setTimeout(15);
            $processes[$name]->start();
        }

        foreach ($processes as $name => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $name.': '.$process->getOutput().$process->getErrorOutput());
        }

        $list = $this->runKosmo(['mcp:list', '--json']);
        $this->assertSame(0, $list['exit']);
        foreach (['one', 'two', 'three'] as $name) {
            $this->assertArrayHasKey($name, $list['json']['servers']);
            $this->assertSame('allow', $list['json']['servers'][$name]['permissions']['read']);
            $this->assertSame('allow', $list['json']['servers'][$name]['permissions']['write']);
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{exit: int, output: string, json: array<string, mixed>}
     */
    private function runKosmo(array $args): array
    {
        $process = new Process(array_merge(['php', $this->root.'/bin/kosmo'], $args), $this->project, [
            'HOME' => $this->home,
        ]);
        $process->setTimeout(15);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $decoded = json_decode($output, true);

        return [
            'exit' => $process->getExitCode() ?? 1,
            'output' => $output,
            'json' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function luaSandboxCapturesOutput(): bool
    {
        if (! class_exists(Sandbox::class)) {
            return false;
        }

        try {
            $result = (new LuaSandboxService)->execute('print("ok")');

            return $result->error === null && trim($result->output) === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }
}

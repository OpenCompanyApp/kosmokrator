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

    public function test_parallel_headless_mcp_adds_do_not_lose_config_or_permissions(): void
    {
        $server = $this->root.'/tests/fixtures/mcp/fake_stdio_server.php';
        $processes = [];

        foreach (['one', 'two', 'three'] as $name) {
            $processes[$name] = new Process([
                'php', $this->root.'/bin/kosmokrator',
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
        $process = new Process(array_merge(['php', $this->root.'/bin/kosmokrator'], $args), $this->project, [
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

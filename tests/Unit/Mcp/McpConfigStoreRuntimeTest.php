<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Mcp;

use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpServerConfig;
use PHPUnit\Framework\TestCase;

final class McpConfigStoreRuntimeTest extends TestCase
{
    public function test_runtime_servers_are_effective_without_writing_project_config(): void
    {
        $root = sys_get_temp_dir().'/kkr_mcp_runtime_'.uniqid();
        mkdir($root, 0755, true);

        try {
            $store = new McpConfigStore($root.'/home');
            $store->setProjectRoot($root);
            $store->addRuntimeServer(McpServerConfig::fromArray('editor', [
                'command' => 'php',
                'args' => ['server.php'],
            ], 'acp'));

            $servers = $store->effectiveServers();

            $this->assertArrayHasKey('editor', $servers);
            $this->assertSame('acp', $servers['editor']->source);
            $this->assertFileDoesNotExist($root.'/.mcp.json');
        } finally {
            @rmdir($root);
        }
    }

    public function test_runtime_servers_can_be_replaced_for_active_session_scope(): void
    {
        $store = new McpConfigStore(sys_get_temp_dir().'/kkr_mcp_runtime_home_'.uniqid());
        $store->setRuntimeServers([
            'editor' => McpServerConfig::fromArray('editor', [
                'command' => 'php',
                'args' => ['server.php'],
            ], 'acp'),
        ]);

        $this->assertArrayHasKey('editor', $store->effectiveServers());

        $store->setRuntimeServers([]);

        $this->assertArrayNotHasKey('editor', $store->effectiveServers());
    }
}

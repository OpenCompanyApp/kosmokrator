<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Sdk;

use Kosmokrator\Sdk\Config\McpConfigurator;
use Kosmokrator\Sdk\Config\ProviderConfigurator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ConfiguratorTest extends TestCase
{
    private string $home;

    private string $project;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/kk-sdk-config-home-'.bin2hex(random_bytes(4));
        $this->project = sys_get_temp_dir().'/kk-sdk-config-project-'.bin2hex(random_bytes(4));
        mkdir($this->home, 0755, true);
        mkdir($this->project, 0755, true);
        putenv("HOME={$this->home}");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->home);
        $this->removeDir($this->project);
    }

    public function test_provider_configurator_writes_project_config(): void
    {
        ProviderConfigurator::forProject($this->project)
            ->configure('openai', apiKey: 'sk-test', model: 'gpt-test', baseUrl: 'https://example.test/v1');

        $data = Yaml::parseFile($this->project.'/.kosmokrator/config.yaml');

        $this->assertSame('openai', $data['kosmokrator']['agent']['default_provider']);
        $this->assertSame('gpt-test', $data['kosmokrator']['agent']['default_model']);
        $this->assertSame('sk-test', $data['prism']['providers']['openai']['api_key']);
        $this->assertSame('https://example.test/v1', $data['prism']['providers']['openai']['url']);
    }

    public function test_mcp_configurator_writes_project_mcp_json_and_permissions(): void
    {
        McpConfigurator::forProject($this->project)
            ->addStdioServer('fake', 'php', ['server.php'], ['TOKEN' => 'x'], ['read' => 'allow'], trust: true);

        $mcp = json_decode((string) file_get_contents($this->project.'/.mcp.json'), true);
        $settings = Yaml::parseFile($this->project.'/.kosmokrator/config.yaml');

        $this->assertSame('php', $mcp['mcpServers']['fake']['command']);
        $this->assertSame(['server.php'], $mcp['mcpServers']['fake']['args']);
        $this->assertSame('allow', $settings['mcp']['servers']['fake']['permissions']['read']);
        $this->assertArrayHasKey('fingerprint', $settings['mcp']['trust']['fake']);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}

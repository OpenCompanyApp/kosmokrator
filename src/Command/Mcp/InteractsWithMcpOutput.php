<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithMachineIO;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Input\InputInterface;

trait InteractsWithMcpOutput
{
    use InteractsWithMachineIO;

    protected function projectRoot(): string
    {
        return InstructionLoader::gitRoot() ?? getcwd() ?: sys_get_temp_dir();
    }

    protected function configureProjectRoot(McpConfigStore $store, ?SettingsManager $settings = null): void
    {
        $root = $this->projectRoot();
        $store->setProjectRoot($root);
        $settings?->setProjectRoot($root);
    }

    protected function scope(InputInterface $input): string
    {
        return $input->getOption('global') ? 'global' : 'project';
    }
}

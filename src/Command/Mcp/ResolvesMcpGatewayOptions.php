<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Kosmokrator\Mcp\Server\McpGatewayProfile;
use Kosmokrator\Mcp\Server\McpGatewayProfileFactory;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

trait ResolvesMcpGatewayOptions
{
    protected function configureGatewayOptions(Command $command): Command
    {
        return $command
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Optional mcp_gateway.profiles.<name> config profile')
            ->addOption('integration', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Integration provider to expose; repeatable')
            ->addOption('integrations', null, InputOption::VALUE_REQUIRED, 'Comma-separated integration providers to expose')
            ->addOption('upstream', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Configured MCP server to proxy; repeatable')
            ->addOption('upstreams', null, InputOption::VALUE_REQUIRED, 'Comma-separated configured MCP servers to proxy')
            ->addOption('write', null, InputOption::VALUE_REQUIRED, 'Write tool policy: deny or allow')
            ->addOption('max-result-chars', null, InputOption::VALUE_REQUIRED, 'Maximum text characters returned for a tool result')
            ->addOption('resources', null, InputOption::VALUE_NONE, 'Expose selected upstream MCP resources')
            ->addOption('no-resources', null, InputOption::VALUE_NONE, 'Do not expose upstream MCP resources')
            ->addOption('prompts', null, InputOption::VALUE_NONE, 'Expose selected upstream MCP prompts')
            ->addOption('no-prompts', null, InputOption::VALUE_NONE, 'Do not expose upstream MCP prompts');
    }

    protected function profileFromInput(InputInterface $input, SettingsManager $settings, bool $force = false): McpGatewayProfile
    {
        return (new McpGatewayProfileFactory($settings))->create(
            profileName: is_string($input->getOption('profile')) ? $input->getOption('profile') : null,
            integrationValues: $this->selectionValues($input, 'integration', 'integrations'),
            upstreamValues: $this->selectionValues($input, 'upstream', 'upstreams'),
            writePolicy: is_string($input->getOption('write')) ? $input->getOption('write') : null,
            exposeResources: $this->exposeFlag($input, 'resources'),
            exposePrompts: $this->exposeFlag($input, 'prompts'),
            maxResultChars: is_numeric($input->getOption('max-result-chars')) ? (int) $input->getOption('max-result-chars') : null,
            force: $force,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatewayServerConfig(InputInterface $input): array
    {
        [$command, $baseArgs] = $this->kosmokratorExecutable();
        $args = array_merge($baseArgs, ['mcp:serve']);

        if (is_string($input->getOption('profile')) && $input->getOption('profile') !== '') {
            $args[] = '--profile='.$input->getOption('profile');
        }
        foreach ($this->selectionValues($input, 'integration', 'integrations') as $integration) {
            $args[] = '--integration='.$integration;
        }
        foreach ($this->selectionValues($input, 'upstream', 'upstreams') as $upstream) {
            $args[] = '--upstream='.$upstream;
        }

        $hasProfile = is_string($input->getOption('profile')) && $input->getOption('profile') !== '';
        if (is_string($input->getOption('write')) && in_array($input->getOption('write'), ['allow', 'deny'], true)) {
            $args[] = '--write='.$input->getOption('write');
        } elseif (! $hasProfile) {
            $args[] = '--write=deny';
        }

        if ($input->getOption('no-resources')) {
            $args[] = '--no-resources';
        }
        if ($input->getOption('no-prompts')) {
            $args[] = '--no-prompts';
        }

        $maxChars = (int) ($input->getOption('max-result-chars') ?: 50000);
        if ($maxChars !== 50000) {
            $args[] = '--max-result-chars='.$maxChars;
        }

        return [
            'type' => 'stdio',
            'command' => $command,
            'args' => $args,
        ];
    }

    /**
     * @return list<string>
     */
    private function selectionValues(InputInterface $input, string $repeatable, string $csv): array
    {
        $values = [];
        $repeatableValues = $input->getOption($repeatable);
        if (is_array($repeatableValues)) {
            $values = array_merge($values, array_map('strval', $repeatableValues));
        }
        if (is_string($input->getOption($csv)) && $input->getOption($csv) !== '') {
            $values[] = $input->getOption($csv);
        }

        $names = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    private function exposeFlag(InputInterface $input, string $name): ?bool
    {
        if ($input->getOption('no-'.$name)) {
            return false;
        }

        if ($input->getOption($name)) {
            return true;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function kosmokratorExecutable(): array
    {
        $argv0 = $_SERVER['argv'][0] ?? 'kosmokrator';
        $path = is_string($argv0) ? (realpath($argv0) ?: $argv0) : 'kosmokrator';

        if (is_file($path) && is_executable($path)) {
            return [$path, []];
        }

        return [PHP_BINARY, [$path]];
    }
}

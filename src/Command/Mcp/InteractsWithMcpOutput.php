<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait InteractsWithMcpOutput
{
    protected function writeJson(OutputInterface $output, mixed $data): void
    {
        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

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

    /**
     * @return list<string>
     */
    protected function rawTokens(InputInterface $input): array
    {
        return $input instanceof ArgvInput ? $input->getRawTokens(true) : [];
    }

    protected function readStdinIfPiped(): ?string
    {
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            return null;
        }

        $stdin = stream_get_contents(STDIN);

        return is_string($stdin) && trim($stdin) !== '' ? $stdin : null;
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function rawFlag(array $tokens, string $name): bool
    {
        return in_array("--{$name}", $tokens, true);
    }

    protected function scope(InputInterface $input): string
    {
        return $input->getOption('global') ? 'global' : 'project';
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait InteractsWithHeadlessOutput
{
    protected function writeJson(OutputInterface $output, mixed $data): void
    {
        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * @return array<string, mixed>
     */
    protected function stdinJson(): array
    {
        $stdin = stream_get_contents(STDIN);
        if (! is_string($stdin) || trim($stdin) === '') {
            return [];
        }

        $decoded = json_decode($stdin, true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid stdin JSON: '.json_last_error_msg());
        }

        return $decoded;
    }

    protected function scope(InputInterface $input, ?string $payloadScope = null): string
    {
        if ($input->hasOption('global') && $input->getOption('global')) {
            return 'global';
        }

        if ($input->hasOption('project') && $input->getOption('project')) {
            return 'project';
        }

        return $payloadScope === 'project' ? 'project' : 'global';
    }

    protected function boolOption(InputInterface $input, string $name, mixed $payloadValue = null): bool
    {
        if ($input->hasOption($name) && $input->getOption($name)) {
            return true;
        }

        return $payloadValue === true || $payloadValue === 'true' || $payloadValue === 'on';
    }
}

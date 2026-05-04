<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Concerns;

use Symfony\Component\Console\Input\InputInterface;

trait InteractsWithHeadlessOutput
{
    use InteractsWithMachineIO;

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
}

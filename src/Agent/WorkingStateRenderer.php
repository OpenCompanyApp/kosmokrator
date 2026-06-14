<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

final class WorkingStateRenderer
{
    public function render(WorkingStateSnapshot $snapshot): string
    {
        if ($snapshot->isEmpty()) {
            return '';
        }

        $lines = ['## Current Working State'];
        if ($snapshot->recentFiles !== []) {
            $lines[] = '';
            $lines[] = 'Recent files:';
            foreach ($snapshot->recentFiles as $file) {
                $lines[] = '- '.$file;
            }
        }
        if ($snapshot->taskTree !== '') {
            $lines[] = '';
            $lines[] = 'Active tasks:';
            $lines[] = $snapshot->taskTree;
        }
        if ($snapshot->backgroundItems !== []) {
            $lines[] = '';
            $lines[] = 'Background work:';
            foreach ($snapshot->backgroundItems as $item) {
                $lines[] = '- '.$item;
            }
        }

        return implode("\n", $lines);
    }
}

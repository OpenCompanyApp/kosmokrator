<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

readonly class WorkingStateSnapshot
{
    /**
     * @param  string[]  $recentFiles
     * @param  string[]  $backgroundItems
     */
    public function __construct(
        public array $recentFiles = [],
        public string $taskTree = '',
        public array $backgroundItems = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->recentFiles === [] && $this->taskTree === '' && $this->backgroundItems === [];
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Update;

interface UpdateCheckerInterface
{
    public function fetchLatestVersion(): ?string;

    public function clearCache(): void;
}

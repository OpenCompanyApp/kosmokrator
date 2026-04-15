<?php

declare(strict_types=1);

namespace Kosmokrator\Setup;

interface SetupFlowInterface
{
    public function needsProviderSetup(): bool;

    public function open(string $rendererPref = 'auto', bool $animated = false, bool $showIntro = false, ?string $notice = null): bool;
}

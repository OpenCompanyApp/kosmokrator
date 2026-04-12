<?php

declare(strict_types=1);

namespace Kosmokrator\Update;

interface SelfUpdaterInterface
{
    public function installationMethod(): string;

    public function sourceUpdateInstructions(): string;

    public function update(string $targetVersion): string;
}

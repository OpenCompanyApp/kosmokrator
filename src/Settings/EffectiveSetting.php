<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

final readonly class EffectiveSetting
{
    public function __construct(
        public string $id,
        public mixed $value,
        public string $source,
        public string $scope,
        public SettingDefinition $definition,
    ) {}
}

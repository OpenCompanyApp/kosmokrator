<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

/**
 * A fully resolved setting value, produced by SettingsManager::resolve().
 *
 * Bundles the resolved value together with provenance (source/scope) and the
 * original SettingDefinition, so consumers have everything in one place.
 */
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

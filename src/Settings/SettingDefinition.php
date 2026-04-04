<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

/**
 * Declares a single setting in the settings schema (type, allowed values, scopes, etc.).
 *
 * Instances are loaded from YAML by SettingsSchema and consumed by SettingsManager
 * to validate and resolve user-facing configuration values.
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public string $id,
        public string $path,
        public string $label,
        public string $description,
        public string $category,
        public string $type = 'text',
        public array $options = [],
        public string $effect = 'applies_now',
        public array $scopes = ['global', 'project'],
        public mixed $default = null,
    ) {}
}

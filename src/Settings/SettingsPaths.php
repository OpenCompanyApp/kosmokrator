<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

/**
 * Resolves the filesystem paths for global and project-level config files.
 *
 * Used by SettingsManager to locate and persist YAML configuration files
 * in XDG-style directories (global) or .kosmokrator/ (project).
 */
final class SettingsPaths
{
    public function __construct(
        private readonly ?string $projectRoot = null,
    ) {}

    /**
     * @return string|null Absolute path to the first existing global config, or null
     */
    public function globalReadPath(): ?string
    {
        foreach ($this->globalCandidates() as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return string Absolute path where the global config should be written
     */
    public function globalWritePath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return $home.'/.config/kosmokrator/config.yaml';
    }

    /**
     * @return string|null Absolute path to the first existing project config, or null
     */
    public function projectReadPath(): ?string
    {
        foreach ($this->projectCandidates() as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return string|null Absolute path for the project config file, or null when no project root is set
     */
    public function projectWritePath(): ?string
    {
        if ($this->projectRoot === null || $this->projectRoot === '') {
            return null;
        }

        return rtrim($this->projectRoot, '/').'/.kosmokrator/config.yaml';
    }

    /**
     * @return list<string> Candidate file paths for the global config (in priority order)
     */
    public function globalCandidates(): array
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return [
            $home.'/.config/kosmokrator/config.yaml',
            $home.'/.kosmokrator/config.yaml',
        ];
    }

    /**
     * @return list<string> Candidate file paths for the project-level config (in priority order)
     */
    public function projectCandidates(): array
    {
        if ($this->projectRoot === null || $this->projectRoot === '') {
            return [];
        }

        $root = rtrim($this->projectRoot, '/');

        return [
            $root.'/.kosmokrator/config.yaml',
            $root.'/.kosmokrator.yaml',
        ];
    }
}

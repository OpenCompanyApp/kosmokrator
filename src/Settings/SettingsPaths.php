<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

final class SettingsPaths
{
    public function __construct(
        private readonly ?string $projectRoot = null,
    ) {}

    public function globalReadPath(): ?string
    {
        foreach ($this->globalCandidates() as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function globalWritePath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return $home.'/.config/kosmokrator/config.yaml';
    }

    public function projectReadPath(): ?string
    {
        foreach ($this->projectCandidates() as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function projectWritePath(): ?string
    {
        if ($this->projectRoot === null || $this->projectRoot === '') {
            return null;
        }

        return rtrim($this->projectRoot, '/').'/.kosmokrator/config.yaml';
    }

    /**
     * @return list<string>
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
     * @return list<string>
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

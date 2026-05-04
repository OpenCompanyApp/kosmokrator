<?php

declare(strict_types=1);

namespace Kosmokrator\Settings;

/**
 * Resolves the filesystem paths for global and project-level config files.
 *
 * All global config lives under ~/.kosmo/. Project-level config
 * lives in .kosmo/ relative to the project root.
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
        $this->migrateGlobalConfigIfNeeded();

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
        return self::globalDirectory().'/config.yaml';
    }

    /**
     * @return string|null Absolute path to the first existing project config, or null
     */
    public function projectReadPath(): ?string
    {
        $this->migrateProjectConfigIfNeeded();

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

        $this->migrateProjectConfigIfNeeded();

        return self::projectDirectory($this->projectRoot).'/config.yaml';
    }

    /**
     * @return list<string> Candidate file paths for the global config (in priority order)
     */
    public function globalCandidates(): array
    {
        return [
            self::globalDirectory().'/config.yaml',
            self::globalDirectory(ConfigCompatibility::LEGACY_ROOT).'/config.yaml',
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

        return self::projectCandidatesForRoot($this->projectRoot);
    }

    /**
     * @return list<string> Candidate file paths in read priority order.
     */
    public static function projectCandidatesForRoot(string $root): array
    {
        $root = rtrim($root, '/');

        return [
            self::projectDirectory($root).'/config.yaml',
            $root.'/.kosmo.yaml',
            self::projectDirectory($root, ConfigCompatibility::LEGACY_ROOT).'/config.yaml',
            $root.'/.kosmokrator.yaml',
        ];
    }

    public static function homeDirectory(): string
    {
        return rtrim(getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir(), '/');
    }

    public static function globalDirectory(string $root = ConfigCompatibility::CANONICAL_ROOT, ?string $home = null): string
    {
        $home ??= self::homeDirectory();

        return rtrim($home, '/').'/.'.$root;
    }

    public static function projectDirectory(string $projectRoot, string $root = ConfigCompatibility::CANONICAL_ROOT): string
    {
        return rtrim($projectRoot, '/').'/.'.$root;
    }

    private function migrateGlobalConfigIfNeeded(): void
    {
        $new = self::globalDirectory().'/config.yaml';
        $old = self::globalDirectory(ConfigCompatibility::LEGACY_ROOT).'/config.yaml';

        if (file_exists($new) || ! file_exists($old)) {
            return;
        }

        $dir = dirname($new);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return;
        }

        @copy($old, $new);
    }

    private function migrateProjectConfigIfNeeded(): void
    {
        if ($this->projectRoot === null || $this->projectRoot === '') {
            return;
        }

        $new = self::projectDirectory($this->projectRoot).'/config.yaml';
        if (file_exists($new)) {
            return;
        }

        foreach (array_slice($this->projectCandidates(), 1) as $old) {
            if (! file_exists($old)) {
                continue;
            }

            $dir = dirname($new);
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                return;
            }

            @copy($old, $new);

            return;
        }
    }
}

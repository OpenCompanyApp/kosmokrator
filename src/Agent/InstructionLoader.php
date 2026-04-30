<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * Discovers and concatenates instruction files (global, project, directory) into a single
 * prompt suffix appended to the system prompt by AgentSessionBuilder.
 * Supports KOSMOKRATOR.md, AGENTS.md, and ~/.kosmo/instructions.md.
 * Legacy ~/.kosmokrator and .kosmokrator instruction paths are still read.
 *
 * @see AgentSessionBuilder
 */
class InstructionLoader
{
    /**
     * Discover and load instruction files in priority order.
     *
     * Search order:
     *  1. ~/.kosmo/instructions.md  (global user instructions)
     *  2. {git_root}/KOSMOKRATOR.md       (project-level, committed)
     *  3. {git_root}/.kosmo/instructions.md (project-level, gitignore-able)
     *  4. {git_root}/AGENTS.md            (cross-tool agent instructions)
     *  5. {cwd}/KOSMOKRATOR.md            (subdirectory override, if cwd ≠ git root)
     */
    public static function gather(): string
    {
        $sections = [];
        $cwd = getcwd();
        $gitRoot = self::gitRoot();
        $home = self::homeDir();

        // 1. Global user instructions
        if ($home !== null) {
            foreach ([$home.'/.kosmo/instructions.md', $home.'/.kosmokrator/instructions.md'] as $global) {
                $content = self::readFile($global);
                if ($content !== null) {
                    $sections[] = "# User Instructions\n".$content;
                    break;
                }
            }
        }

        // 2. Project KOSMOKRATOR.md at git root
        $projectRootFile = null;
        if ($gitRoot !== null) {
            $projectRootFile = $gitRoot.'/KOSMOKRATOR.md';
            $content = self::readFile($projectRootFile);
            if ($content !== null) {
                $sections[] = "# Project Instructions\n".$content;
            }
        }

        // 3. Project .kosmo/instructions.md at git root
        if ($gitRoot !== null) {
            foreach ([$gitRoot.'/.kosmo/instructions.md', $gitRoot.'/.kosmokrator/instructions.md'] as $path) {
                $content = self::readFile($path);
                if ($content !== null) {
                    $sections[] = "# Project Instructions\n".$content;
                    break;
                }
            }
        }

        // 4. AGENTS.md at git root
        if ($gitRoot !== null) {
            $content = self::readFile($gitRoot.'/AGENTS.md');
            if ($content !== null) {
                $sections[] = "# Agent Instructions\n".$content;
            }
        }

        // 5. Subdirectory KOSMOKRATOR.md (only if cwd differs from git root)
        if ($cwd !== $gitRoot) {
            $cwdFile = $cwd.'/KOSMOKRATOR.md';
            // Avoid loading the same file twice if git root detection failed
            if ($cwdFile !== $projectRootFile) {
                $content = self::readFile($cwdFile);
                if ($content !== null) {
                    $sections[] = "# Directory Instructions\n".$content;
                }
            }
        }

        if ($sections === []) {
            return '';
        }

        return "\n\n".implode("\n\n", $sections);
    }

    private static function readFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return null;
        }

        return trim($content);
    }

    /** Resolve the git repository root using `git rev-parse --show-toplevel`. */
    public static function gitRoot(): ?string
    {
        $result = trim((string) shell_exec('git rev-parse --show-toplevel 2>/dev/null'));

        return $result !== '' ? $result : null;
    }

    private static function homeDir(): ?string
    {
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            return $home;
        }

        // Windows fallback
        $home = getenv('USERPROFILE');

        return ($home !== false && $home !== '') ? $home : null;
    }
}

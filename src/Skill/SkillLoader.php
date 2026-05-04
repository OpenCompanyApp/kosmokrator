<?php

declare(strict_types=1);

namespace Kosmokrator\Skill;

use Kosmokrator\Security\PromptInjectionScanner;
use Kosmokrator\Settings\ConfigCompatibility;
use Kosmokrator\Settings\SettingsPaths;
use Symfony\Component\Yaml\Yaml;

class SkillLoader
{
    /** @var array<array{dir: string, scope: SkillScope}> Ordered lowest → highest precedence */
    private readonly array $sources;

    /**
     * @param  string  $projectRoot  Project root directory (for .kosmo/skills/ and .agents/skills/)
     * @param  string  $userSkillsDir  User-global skills directory (~/.kosmo/skills/)
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $userSkillsDir,
        private readonly ?PromptInjectionScanner $scanner = null,
    ) {
        // Lowest precedence first — later entries override earlier ones
        $this->sources = [
            ['dir' => $this->legacyUserSkillsDir(), 'scope' => SkillScope::User],
            ['dir' => $this->userSkillsDir, 'scope' => SkillScope::User],
            ['dir' => SettingsPaths::projectDirectory($this->projectRoot, ConfigCompatibility::LEGACY_ROOT).'/skills', 'scope' => SkillScope::Project],
            ['dir' => $this->projectRoot.'/.agents/skills', 'scope' => SkillScope::Project],
            ['dir' => SettingsPaths::projectDirectory($this->projectRoot).'/skills', 'scope' => SkillScope::Project],
        ];
    }

    /**
     * Load all skills from all discovery directories.
     *
     * Precedence (highest → lowest):
     *   1. .kosmo/skills/  (project canonical)
     *   2. .agents/skills/       (ecosystem standard)
     *   3. ~/.kosmo/skills/ (user global)
     *
     * @return array<string, Skill> name → Skill
     */
    public function loadAll(): array
    {
        $skills = [];

        foreach ($this->sources as $source) {
            foreach ($this->loadFrom($source['dir'], $source['scope']) as $skill) {
                $skills[$skill->name] = $skill;
            }
        }

        return $skills;
    }

    /**
     * @return Skill[]
     */
    public function loadFrom(string $dir, SkillScope $scope): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $skills = [];
        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $skillDir = $dir.'/'.$entry;
            if (! is_dir($skillDir)) {
                continue;
            }

            $skillFile = $skillDir.'/SKILL.md';
            if (! is_file($skillFile)) {
                continue;
            }

            $skill = $this->parse($skillFile, $scope);
            if ($skill !== null) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    public function parse(string $path, SkillScope $scope): ?Skill
    {
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        // Extract YAML frontmatter between --- delimiters
        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $matches)) {
            return null;
        }

        $frontmatter = $matches[1];
        $content = trim($matches[2]);

        try {
            $meta = Yaml::parse($frontmatter);
        } catch (\Throwable $e) {
            error_log("[SkillLoader] YAML parse failed for {$path}: {$e->getMessage()}");

            return null;
        }

        if (! is_array($meta)) {
            return null;
        }

        $name = $meta['name'] ?? null;
        $description = $meta['description'] ?? '';

        if (! is_string($name) || $name === '') {
            return null;
        }

        $scanner = $this->scanner ?? new PromptInjectionScanner;
        if (! $scanner->isSafe($content)) {
            return null;
        }

        return new Skill(
            name: $name,
            description: is_string($description) ? $description : '',
            content: $content,
            path: $path,
            scope: $scope,
        );
    }

    /**
     * The primary project skills directory (for $create scaffolding).
     */
    public function getProjectSkillsDir(): string
    {
        return SettingsPaths::projectDirectory($this->projectRoot).'/skills';
    }

    public function getUserSkillsDir(): string
    {
        return $this->userSkillsDir;
    }

    private function legacyUserSkillsDir(): string
    {
        $canonicalSegment = '/.'.ConfigCompatibility::CANONICAL_ROOT.'/';
        if (str_contains($this->userSkillsDir, $canonicalSegment)) {
            return str_replace($canonicalSegment, '/.'.ConfigCompatibility::LEGACY_ROOT.'/', $this->userSkillsDir);
        }

        return SettingsPaths::globalDirectory(ConfigCompatibility::LEGACY_ROOT).'/skills';
    }

    /**
     * All discovery directories in precedence order (highest first).
     *
     * @return string[]
     */
    public function getDiscoveryDirs(): array
    {
        return array_reverse(array_column($this->sources, 'dir'));
    }
}

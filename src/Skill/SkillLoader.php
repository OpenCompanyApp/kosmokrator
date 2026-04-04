<?php

declare(strict_types=1);

namespace Kosmokrator\Skill;

use Symfony\Component\Yaml\Yaml;

class SkillLoader
{
    public function __construct(
        private readonly string $projectSkillsDir,
        private readonly string $userSkillsDir,
    ) {}

    /**
     * Load all skills from both project and user directories.
     * Project skills take precedence over user skills with the same name.
     *
     * @return array<string, Skill> name → Skill
     */
    public function loadAll(): array
    {
        $skills = [];

        // User skills first (lower precedence)
        foreach ($this->loadFrom($this->userSkillsDir, SkillScope::User) as $skill) {
            $skills[$skill->name] = $skill;
        }

        // Project skills override user skills
        foreach ($this->loadFrom($this->projectSkillsDir, SkillScope::Project) as $skill) {
            $skills[$skill->name] = $skill;
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
        } catch (\Throwable) {
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

        return new Skill(
            name: $name,
            description: is_string($description) ? $description : '',
            content: $content,
            path: $path,
            scope: $scope,
        );
    }

    public function getProjectSkillsDir(): string
    {
        return $this->projectSkillsDir;
    }

    public function getUserSkillsDir(): string
    {
        return $this->userSkillsDir;
    }
}

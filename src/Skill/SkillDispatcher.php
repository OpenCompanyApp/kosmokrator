<?php

declare(strict_types=1);

namespace Kosmokrator\Skill;

use Kosmokrator\UI\UIManager;

class SkillDispatcher
{
    private const MANAGEMENT_COMMANDS = ['list', 'skills', 'create', 'show', 'edit', 'delete'];

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly SkillLoader $loader,
        private readonly UIManager $ui,
    ) {}

    /**
     * Dispatch a `$` command. Input should have the leading `$` already stripped.
     *
     * @return string|null Prompt to inject into the agent, or null for no injection
     */
    public function dispatch(string $raw): ?string
    {
        // Reload skills from disk on every dispatch so newly created skills are available
        $this->registry->load($this->loader);

        $raw = trim($raw);

        // Bare `$` or `$list` / `$skills` — show skill listing
        if ($raw === '' || $raw === 'list' || $raw === 'skills') {
            $this->listSkills();

            return null;
        }

        // Extract subcommand and args
        $spacePos = strpos($raw, ' ');
        $subcommand = $spacePos !== false ? substr($raw, 0, $spacePos) : $raw;
        $args = $spacePos !== false ? trim(substr($raw, $spacePos + 1)) : '';

        // Management commands
        return match (strtolower($subcommand)) {
            'create' => $this->createSkill($args),
            'show' => $this->showSkill($args),
            'edit' => $this->editSkill($args),
            'delete' => $this->deleteSkill($args),
            default => $this->invokeSkill($raw),
        };
    }

    private function listSkills(): void
    {
        $skills = $this->registry->all();

        if ($skills === []) {
            $this->ui->showNotice("No skills found.\n  Project: {$this->loader->getProjectSkillsDir()}\n  User: {$this->loader->getUserSkillsDir()}\n  Use \$create <name> to create one.");

            return;
        }

        $lines = [];
        foreach ($skills as $skill) {
            $scope = $skill->scope === SkillScope::Project ? 'project' : 'user';
            $lines[] = "  \${$skill->name}  ({$scope})  {$skill->description}";
        }

        $this->ui->showNotice("Skills:\n".implode("\n", $lines));
    }

    private function createSkill(string $name): ?string
    {
        if ($name === '') {
            $this->ui->showNotice('Usage: $create <skill-name>');

            return null;
        }

        $name = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '-', $name));
        $dir = $this->loader->getProjectSkillsDir().'/'.$name;
        $file = $dir.'/SKILL.md';

        if (is_file($file)) {
            $this->ui->showNotice("Skill already exists: {$file}");

            return null;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $template = <<<SKILL
            ---
            name: {$name}
            description: Describe when this skill should be used
            ---

            # {$name}

            Instructions for the agent go here.
            SKILL;

        file_put_contents($file, $template);

        $this->ui->showNotice("Created skill template: {$file}");

        return "A new skill template has been created at {$file}. Read the file, then help the user define what this skill should do. Ask what workflow or instructions they want, then write the SKILL.md with a clear description and detailed agent instructions.";
    }

    private function showSkill(string $name): ?string
    {
        if ($name === '') {
            $this->ui->showNotice('Usage: $show <skill-name>');

            return null;
        }

        $skill = $this->registry->get($name);
        if ($skill === null) {
            $this->ui->showNotice("Unknown skill: {$name}");

            return null;
        }

        $scope = $skill->scope === SkillScope::Project ? 'project' : 'user';
        $this->ui->showNotice("Skill: {$skill->name} ({$scope})\nPath: {$skill->path}\n\n{$skill->content}");

        return null;
    }

    private function editSkill(string $name): ?string
    {
        if ($name === '') {
            $this->ui->showNotice('Usage: $edit <skill-name>');

            return null;
        }

        $skill = $this->registry->get($name);
        if ($skill === null) {
            $this->ui->showNotice("Unknown skill: {$name}");

            return null;
        }

        $this->ui->showNotice("Editing skill: {$skill->name}");

        return "Read the skill file at {$skill->path} and help the user modify it. Show the current content, ask what they want to change, then update the file.";
    }

    private function deleteSkill(string $name): ?string
    {
        if ($name === '') {
            $this->ui->showNotice('Usage: $delete <skill-name>');

            return null;
        }

        $skill = $this->registry->get($name);
        if ($skill === null) {
            $this->ui->showNotice("Unknown skill: {$name}");

            return null;
        }

        $dir = dirname($skill->path);
        $this->removeDirectory($dir);
        $this->ui->showNotice("Deleted skill: {$skill->name} ({$dir})");

        return null;
    }

    private function invokeSkill(string $raw): ?string
    {
        $resolved = $this->registry->resolve($raw);
        if ($resolved === null) {
            // Check if it looks like a management command with wrong syntax
            $firstWord = strtolower(explode(' ', $raw)[0]);
            if (in_array($firstWord, self::MANAGEMENT_COMMANDS, true)) {
                return null; // Already handled above, shouldn't reach here
            }

            $this->ui->showNotice("Unknown skill: {$firstWord}. Type \$list to see available skills.");

            return null;
        }

        [$skill, $args] = $resolved;
        $this->ui->showNotice("Loading skill: {$skill->name}");

        return $skill->buildPrompt($args);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

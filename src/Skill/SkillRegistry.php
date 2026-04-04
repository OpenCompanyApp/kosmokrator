<?php

declare(strict_types=1);

namespace Kosmokrator\Skill;

class SkillRegistry
{
    /** @var array<string, Skill> name → Skill */
    private array $skills = [];

    public function load(SkillLoader $loader): void
    {
        $this->skills = $loader->loadAll();
    }

    /**
     * Resolve user input into a skill and its arguments.
     *
     * @return array{Skill, string}|null [Skill, args] or null if not found
     */
    public function resolve(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $spacePos = strpos($input, ' ');
        if ($spacePos !== false) {
            $name = substr($input, 0, $spacePos);
            $args = trim(substr($input, $spacePos + 1));
        } else {
            $name = $input;
            $args = '';
        }

        $name = strtolower($name);
        $skill = $this->skills[$name] ?? null;

        if ($skill === null) {
            return null;
        }

        return [$skill, $args];
    }

    public function get(string $name): ?Skill
    {
        return $this->skills[strtolower($name)] ?? null;
    }

    /** @return Skill[] */
    public function all(): array
    {
        return array_values($this->skills);
    }

    /**
     * Build autocomplete entries for the TUI.
     *
     * @return array<array{value: string, label: string, description: string}>
     */
    public function completions(): array
    {
        $items = [];
        foreach ($this->skills as $skill) {
            $items[] = [
                'value' => '$'.$skill->name,
                'label' => '$'.$skill->name,
                'description' => $skill->description,
            ];
        }

        return $items;
    }
}

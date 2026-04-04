<?php

declare(strict_types=1);

namespace Kosmokrator\Skill;

readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public string $content,
        public string $path,
        public SkillScope $scope,
    ) {}

    public function buildPrompt(string $args): string
    {
        $header = "SKILL: {$this->name}";

        if ($args !== '') {
            return "{$header}\n\nTask: {$args}\n\n---\n\n{$this->content}";
        }

        return "{$header}\n\n{$this->content}";
    }
}

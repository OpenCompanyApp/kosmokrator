<?php

declare(strict_types=1);

namespace Tests\Unit\Skill;

use Kosmokrator\Skill\Skill;
use Kosmokrator\Skill\SkillRegistry;
use Kosmokrator\Skill\SkillScope;
use PHPUnit\Framework\TestCase;

class SkillRegistryTest extends TestCase
{
    private function makeSkill(string $name, string $description = '', SkillScope $scope = SkillScope::Project): Skill
    {
        return new Skill(
            name: $name,
            description: $description,
            content: "Content for {$name}",
            path: "/fake/skills/{$name}/SKILL.md",
            scope: $scope,
        );
    }

    private function registryWith(Skill ...$skills): SkillRegistry
    {
        $registry = new SkillRegistry;

        // Use reflection to set skills directly (bypassing SkillLoader)
        $ref = new \ReflectionProperty($registry, 'skills');
        $map = [];
        foreach ($skills as $skill) {
            $map[$skill->name] = $skill;
        }
        $ref->setValue($registry, $map);

        return $registry;
    }

    public function test_resolve_by_name(): void
    {
        $skill = $this->makeSkill('deploy');
        $registry = $this->registryWith($skill);

        $result = $registry->resolve('deploy');

        $this->assertNotNull($result);
        $this->assertSame($skill, $result[0]);
        $this->assertSame('', $result[1]);
    }

    public function test_resolve_with_args(): void
    {
        $skill = $this->makeSkill('pr-review');
        $registry = $this->registryWith($skill);

        $result = $registry->resolve('pr-review 123');

        $this->assertNotNull($result);
        $this->assertSame($skill, $result[0]);
        $this->assertSame('123', $result[1]);
    }

    public function test_resolve_with_multi_word_args(): void
    {
        $skill = $this->makeSkill('deploy');
        $registry = $this->registryWith($skill);

        $result = $registry->resolve('deploy staging environment now');

        $this->assertNotNull($result);
        $this->assertSame('staging environment now', $result[1]);
    }

    public function test_resolve_unknown_returns_null(): void
    {
        $registry = $this->registryWith($this->makeSkill('deploy'));

        $this->assertNull($registry->resolve('nonexistent'));
    }

    public function test_resolve_empty_returns_null(): void
    {
        $registry = $this->registryWith($this->makeSkill('deploy'));

        $this->assertNull($registry->resolve(''));
    }

    public function test_resolve_is_case_insensitive(): void
    {
        $skill = $this->makeSkill('deploy');
        $registry = $this->registryWith($skill);

        $result = $registry->resolve('Deploy args');

        $this->assertNotNull($result);
        $this->assertSame($skill, $result[0]);
    }

    public function test_get_by_name(): void
    {
        $skill = $this->makeSkill('deploy');
        $registry = $this->registryWith($skill);

        $this->assertSame($skill, $registry->get('deploy'));
        $this->assertNull($registry->get('nonexistent'));
    }

    public function test_all_returns_all_skills(): void
    {
        $a = $this->makeSkill('alpha');
        $b = $this->makeSkill('beta');
        $registry = $this->registryWith($a, $b);

        $all = $registry->all();

        $this->assertCount(2, $all);
    }

    public function test_completions_format(): void
    {
        $skill = $this->makeSkill('deploy', 'Deploy to production');
        $registry = $this->registryWith($skill);

        $completions = $registry->completions();

        $this->assertCount(1, $completions);
        $this->assertSame('$deploy', $completions[0]['value']);
        $this->assertSame('$deploy', $completions[0]['label']);
        $this->assertSame('Deploy to production', $completions[0]['description']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Skill;

use Kosmokrator\Skill\SkillDispatcher;
use Kosmokrator\Skill\SkillLoader;
use Kosmokrator\Skill\SkillRegistry;
use Kosmokrator\UI\UIManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SkillDispatcherTest extends TestCase
{
    private string $tmpDir;

    private UIManager $ui;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/kosmokrator_dispatcher_test_'.uniqid();
        mkdir($this->tmpDir.'/project/.kosmokrator/skills', 0755, true);
        mkdir($this->tmpDir.'/user', 0755, true);

        $this->ui = $this->createMock(UIManager::class);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function makeDispatcher(?SkillLoader $loader = null): SkillDispatcher
    {
        $loader ??= new SkillLoader($this->tmpDir.'/project', $this->tmpDir.'/user');
        $registry = new SkillRegistry;

        return new SkillDispatcher($registry, $loader, $this->ui);
    }

    private function seedSkill(string $name, string $scope = 'project'): void
    {
        $base = $scope === 'user'
            ? $this->tmpDir.'/user'
            : $this->tmpDir.'/project/.kosmokrator/skills';
        $dir = $base.'/'.$name;
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/SKILL.md', "---\nname: {$name}\ndescription: {$name} skill\n---\n{$name} instructions here.");
    }

    public function test_bare_dollar_shows_list(): void
    {
        $this->ui->expects($this->once())->method('showNotice');

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('');

        $this->assertNull($result);
    }

    public function test_list_command_shows_skills(): void
    {
        $this->seedSkill('deploy');
        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('deploy'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('list');

        $this->assertNull($result);
    }

    public function test_skills_alias_shows_list(): void
    {
        $this->ui->expects($this->once())->method('showNotice');

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('skills');

        $this->assertNull($result);
    }

    public function test_create_scaffolds_and_returns_prompt(): void
    {
        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Created skill template'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('create my-new-skill');

        $this->assertNotNull($result);
        $this->assertStringContains('SKILL.md', $result);
        $this->assertTrue(is_file($this->tmpDir.'/project/.kosmokrator/skills/my-new-skill/SKILL.md'));
    }

    public function test_create_without_name_shows_usage(): void
    {
        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Usage'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('create');

        $this->assertNull($result);
    }

    public function test_show_displays_skill(): void
    {
        $this->seedSkill('deploy');
        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('deploy instructions'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('show deploy');

        $this->assertNull($result);
    }

    public function test_show_unknown_skill(): void
    {
        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Unknown skill'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('show nonexistent');

        $this->assertNull($result);
    }

    public function test_edit_returns_inject_prompt(): void
    {
        $this->seedSkill('deploy');
        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Editing skill'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('edit deploy');

        $this->assertNotNull($result);
        $this->assertStringContains('SKILL.md', $result);
    }

    public function test_delete_removes_skill(): void
    {
        $this->seedSkill('deploy');
        $this->assertTrue(is_dir($this->tmpDir.'/project/.kosmokrator/skills/deploy'));

        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Deleted skill'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('delete deploy');

        $this->assertNull($result);
        $this->assertFalse(is_dir($this->tmpDir.'/project/.kosmokrator/skills/deploy'));
    }

    public function test_invokes_skill_by_name(): void
    {
        $this->seedSkill('deploy');
        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Loading skill'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('deploy');

        $this->assertNotNull($result);
        $this->assertStringContains('SKILL: deploy', $result);
        $this->assertStringContains('deploy instructions', $result);
    }

    public function test_invokes_skill_with_args(): void
    {
        $this->seedSkill('deploy');

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('deploy staging');

        $this->assertNotNull($result);
        $this->assertStringContains('Task: staging', $result);
    }

    public function test_unknown_skill_shows_error(): void
    {
        $this->ui->expects($this->once())
            ->method('showNotice')
            ->with($this->stringContains('Unknown skill'));

        $dispatcher = $this->makeDispatcher();
        $result = $dispatcher->dispatch('nonexistent');

        $this->assertNull($result);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'",
        );
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

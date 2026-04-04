<?php

declare(strict_types=1);

namespace Kosmokrator\Skill;

enum SkillScope: string
{
    case Project = 'project';
    case User = 'user';
}

<?php

namespace Kosmokrator\Tests\Unit\Agent;

use Kosmokrator\Agent\EnvironmentContext;
use PHPUnit\Framework\TestCase;

class EnvironmentContextTest extends TestCase
{
    public function test_gather_includes_working_directory(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('Working directory:', $context);
        $this->assertStringContainsString(getcwd(), $context);
    }

    public function test_gather_includes_platform(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('Platform:', $context);
        $this->assertStringContainsString(PHP_OS_FAMILY, $context);
    }

    public function test_gather_includes_date(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString("Today's date:", $context);
        $this->assertStringContainsString(date('Y-m-d'), $context);
    }

    public function test_gather_includes_shell(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('Shell:', $context);
    }

    public function test_gather_detects_composer_project(): void
    {
        // We're running from the kosmokrator root which has composer.json
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('PHP (Composer)', $context);
        $this->assertStringContainsString('kosmokrator', $context);
    }

    public function test_gather_includes_git_info(): void
    {
        $context = EnvironmentContext::gather();

        $this->assertStringContainsString('Git branch:', $context);
    }
}

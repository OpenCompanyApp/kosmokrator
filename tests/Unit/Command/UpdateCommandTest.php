<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Container\Container;
use Kosmokrator\Command\UpdateCommand;
use Kosmokrator\Update\SelfUpdaterInterface;
use Kosmokrator\Update\UpdateCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdateCommandTest extends TestCase
{
    public function test_reports_when_already_on_latest_version(): void
    {
        $tester = $this->makeTester(
            checker: new FakeUpdateChecker('0.6.0'),
            updater: new FakeSelfUpdater('binary'),
            currentVersion: '0.6.0',
        );

        $exit = $tester->execute(['--check' => true]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Install method: static binary', $display);
        $this->assertStringContainsString('Already on the latest version (v0.6.0).', $display);
    }

    public function test_source_install_prints_manual_update_instructions(): void
    {
        $tester = $this->makeTester(
            checker: new FakeUpdateChecker('0.6.2'),
            updater: new FakeSelfUpdater('source', "cd /repo\n"."git pull\n".'composer install'),
            currentVersion: '0.6.0',
        );

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('source checkout', $display);
        $this->assertStringContainsString('Update available: v0.6.2 (current: v0.6.0)', $display);
        $this->assertStringContainsString('cd /repo', $display);
        $this->assertStringContainsString('git pull', $display);
        $this->assertStringContainsString('composer install', $display);
    }

    public function test_binary_install_updates_in_place_with_yes_flag(): void
    {
        $updater = new FakeSelfUpdater('binary', updateMessage: 'Updated to v0.6.2.');
        $tester = $this->makeTester(
            checker: new FakeUpdateChecker('0.6.2'),
            updater: $updater,
            currentVersion: '0.6.0',
        );

        $exit = $tester->execute(['--yes' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(['0.6.2'], $updater->updatedVersions);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Downloading and replacing the current executable...', $display);
        $this->assertStringContainsString('Updated to v0.6.2.', $display);
    }

    private function makeTester(
        UpdateCheckerInterface $checker,
        SelfUpdaterInterface $updater,
        string $currentVersion,
    ): CommandTester {
        $app = new Application;
        $app->addCommand(new UpdateCommand(
            new Container,
            $currentVersion,
            checkerFactory: static fn (string $version): UpdateCheckerInterface => $checker,
            updaterFactory: static fn (): SelfUpdaterInterface => $updater,
        ));

        return new CommandTester($app->get('update'));
    }
}

final class FakeUpdateChecker implements UpdateCheckerInterface
{
    public bool $cacheCleared = false;

    public function __construct(
        private readonly ?string $latest,
    ) {}

    public function fetchLatestVersion(): ?string
    {
        return $this->latest;
    }

    public function clearCache(): void
    {
        $this->cacheCleared = true;
    }
}

final class FakeSelfUpdater implements SelfUpdaterInterface
{
    /** @var list<string> */
    public array $updatedVersions = [];

    public function __construct(
        private readonly string $method,
        private readonly string $sourceInstructions = '',
        private readonly string $updateMessage = 'Updated.',
    ) {}

    public function installationMethod(): string
    {
        return $this->method;
    }

    public function sourceUpdateInstructions(): string
    {
        return $this->sourceInstructions;
    }

    public function update(string $targetVersion): string
    {
        $this->updatedVersions[] = $targetVersion;

        return $this->updateMessage;
    }
}

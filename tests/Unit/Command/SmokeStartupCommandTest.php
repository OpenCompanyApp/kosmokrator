<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Container\Container;
use Kosmokrator\Command\SmokeStartupCommand;
use Kosmokrator\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SmokeStartupCommandTest extends TestCase
{
    private ?string $originalHome = null;

    private string $tempHome;

    protected function setUp(): void
    {
        $this->originalHome = getenv('HOME') ?: null;
        $this->tempHome = sys_get_temp_dir().'/kosmokrator-smoke-test-'.uniqid();
        mkdir($this->tempHome.'/.kosmo', 0777, true);
        putenv('HOME='.$this->tempHome);
        $_ENV['HOME'] = $this->tempHome;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== null) {
            putenv('HOME='.$this->originalHome);
            $_ENV['HOME'] = $this->originalHome;
        } else {
            putenv('HOME');
            unset($_ENV['HOME']);
        }

        $this->removeDirectory($this->tempHome);
        Container::setInstance(null);
    }

    public function test_smoke_startup_reports_success(): void
    {
        $kernel = new Kernel(dirname(__DIR__, 3));
        $kernel->boot();

        $application = new Application('KosmoKrator Test', 'test');
        $application->addCommand(new SmokeStartupCommand($kernel->getContainer()));

        $tester = new CommandTester($application->find('smoke:startup'));
        $exit = $tester->execute(['--json' => true]);

        $this->assertSame(0, $exit);

        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['errors']);
        $this->assertTrue($data['checks']['integrations']['package_autoload']['core_registry']);
        $this->assertTrue($data['checks']['integrations']['package_autoload']['clickup_provider']);
        $this->assertTrue($data['checks']['commands']['has_smoke_startup']);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item->getRealPath()) : unlink((string) $item->getRealPath());
        }

        rmdir($path);
    }
}

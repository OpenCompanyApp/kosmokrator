<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Update;

use Kosmokrator\Update\SelfUpdater;
use PHPUnit\Framework\TestCase;

class SelfUpdaterTest extends TestCase
{
    public function test_resolve_asset_name_phar(): void
    {
        // When not running inside a PHAR, we can only test the non-PHAR path
        // PHAR detection returns '' when not in a PHAR context
        $updater = new SelfUpdater;
        $method = new \ReflectionMethod($updater, 'resolveAssetName');

        $asset = $method->invoke($updater);

        // Should be a platform-specific binary name (not phar, since we're not in a phar)
        $this->assertMatchesRegularExpression('/^kosmokrator-(macos|linux)-(x86_64|aarch64)$/', $asset);
    }

    public function test_resolve_asset_name_matches_current_platform(): void
    {
        $updater = new SelfUpdater;
        $method = new \ReflectionMethod($updater, 'resolveAssetName');

        $asset = $method->invoke($updater);

        $expectedOs = PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux';
        $this->assertStringContainsString($expectedOs, $asset);
    }

    public function test_is_source_installation_detects_php_script(): void
    {
        $updater = new SelfUpdater;
        $method = new \ReflectionMethod($updater, 'isSourceInstallation');

        $tmp = tempnam(sys_get_temp_dir(), 'kosmo_test_');
        file_put_contents($tmp, "#!/usr/bin/env php\n<?php\necho 'hello';\n");

        try {
            $this->assertTrue($method->invoke($updater, $tmp));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_is_source_installation_detects_php_open_tag(): void
    {
        $updater = new SelfUpdater;
        $method = new \ReflectionMethod($updater, 'isSourceInstallation');

        $tmp = tempnam(sys_get_temp_dir(), 'kosmo_test_');
        file_put_contents($tmp, "<?php\ndeclare(strict_types=1);\n");

        try {
            $this->assertTrue($method->invoke($updater, $tmp));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_is_source_installation_returns_false_for_binary(): void
    {
        $updater = new SelfUpdater;
        $method = new \ReflectionMethod($updater, 'isSourceInstallation');

        $tmp = tempnam(sys_get_temp_dir(), 'kosmo_test_');
        // ELF header (Linux binary magic bytes)
        file_put_contents($tmp, "\x7fELF".str_repeat("\x00", 60));

        try {
            $this->assertFalse($method->invoke($updater, $tmp));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_verify_download_rejects_tiny_file(): void
    {
        $updater = new SelfUpdater;
        $method = new \ReflectionMethod($updater, 'verifyDownload');

        $tmp = tempnam(sys_get_temp_dir(), 'kosmo_test_');
        file_put_contents($tmp, 'Not Found');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('too small');
            $method->invoke($updater, $tmp, 'kosmokrator-macos-aarch64');
        } finally {
            @unlink($tmp);
        }
    }
}

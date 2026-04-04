<?php

declare(strict_types=1);

namespace Kosmokrator\Update;

/**
 * Downloads and replaces the current KosmoKrator binary with a new release.
 *
 * Supports both PHAR and static binary installations. Detects the running
 * binary type and downloads the matching asset from GitHub Releases.
 */
final class SelfUpdater
{
    private const GITHUB_REPO = 'OpenCompanyApp/kosmokrator';

    /**
     * Perform an in-place update of the running binary.
     *
     * @param  string  $targetVersion  Version to update to (without 'v' prefix)
     * @return string Status message describing what happened
     *
     * @throws \RuntimeException If the update fails
     */
    public function update(string $targetVersion): string
    {
        $binaryPath = $this->resolveBinaryPath();
        $asset = $this->resolveAssetName();
        $url = 'https://github.com/'.self::GITHUB_REPO."/releases/download/v{$targetVersion}/{$asset}";

        $tmpPath = $binaryPath.'.tmp.'.getmypid();

        try {
            $this->download($url, $tmpPath);
            $this->replace($binaryPath, $tmpPath);
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            throw $e;
        }

        return "Updated to v{$targetVersion}. Restart KosmoKrator to use the new version.";
    }

    /**
     * Determine the absolute path of the currently running binary.
     */
    private function resolveBinaryPath(): string
    {
        // PHAR — running inside a .phar archive
        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            return $pharPath;
        }

        // Static binary or direct PHP — use the script path
        $path = realpath($_SERVER['argv'][0] ?? '');
        if ($path === false || ! is_file($path)) {
            throw new \RuntimeException('Cannot determine the path of the running binary.');
        }

        return $path;
    }

    /**
     * Determine which release asset to download based on the current installation type.
     */
    private function resolveAssetName(): string
    {
        // If running inside a PHAR, update with the PHAR
        if (\Phar::running(false) !== '') {
            return 'kosmokrator.phar';
        }

        // Otherwise assume static binary — match platform
        $os = PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux';
        $arch = php_uname('m');

        $archMap = [
            'x86_64' => 'x86_64',
            'amd64' => 'x86_64',
            'aarch64' => 'aarch64',
            'arm64' => 'aarch64',
        ];

        $normalizedArch = $archMap[$arch] ?? 'x86_64';

        return "kosmokrator-{$os}-{$normalizedArch}";
    }

    private function download(string $url, string $destination): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: KosmoKrator\r\n",
                'timeout' => 60,
                'follow_location' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            throw new \RuntimeException("Failed to download {$url}");
        }

        if (file_put_contents($destination, $data) === false) {
            throw new \RuntimeException("Failed to write to {$destination}");
        }
    }

    private function replace(string $binaryPath, string $tmpPath): void
    {
        // Preserve original permissions
        $perms = fileperms($binaryPath);

        if (! @rename($tmpPath, $binaryPath)) {
            // rename() fails across filesystems — fall back to copy
            if (! @copy($tmpPath, $binaryPath)) {
                throw new \RuntimeException("Failed to replace {$binaryPath}. Check file permissions.");
            }
            @unlink($tmpPath);
        }

        if ($perms !== false) {
            @chmod($binaryPath, $perms);
        } else {
            @chmod($binaryPath, 0755);
        }
    }
}

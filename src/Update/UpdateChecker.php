<?php

declare(strict_types=1);

namespace Kosmokrator\Update;

use Psr\Log\LoggerInterface;

/**
 * Checks for new KosmoKrator releases via the GitHub API.
 *
 * Caches the result to avoid hitting the API on every session start.
 * The cache lives in ~/.kosmokrator/update-check.json with a configurable TTL.
 */
final class UpdateChecker implements UpdateCheckerInterface
{
    private const GITHUB_REPO = 'OpenCompanyApp/kosmokrator';

    private const CACHE_TTL_SECONDS = 3600; // 1 hour

    private string $cachePath;

    public function __construct(
        private readonly string $currentVersion,
        private readonly ?LoggerInterface $log = null,
    ) {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
        $this->cachePath = $home.'/.kosmokrator/update-check.json';
    }

    /**
     * Check if a newer version is available.
     *
     * Returns the latest version string if an update is available, null otherwise.
     * Never throws — returns null on any failure.
     */
    public function check(): ?string
    {
        if ($this->currentVersion === 'dev' || $this->currentVersion === '') {
            return null;
        }

        $cached = $this->readCache();
        if ($cached !== null) {
            return $this->compareVersions($cached);
        }

        $latest = $this->fetchLatestVersion();
        if ($latest === null) {
            return null;
        }

        $this->writeCache($latest);

        return $this->compareVersions($latest);
    }

    /**
     * Fetch the latest release tag from GitHub.
     */
    public function fetchLatestVersion(): ?string
    {
        $url = 'https://api.github.com/repos/'.self::GITHUB_REPO.'/releases/latest';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: KosmoKrator/{$this->currentVersion}\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        try {
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return null;
            }

            $data = json_decode($response, true);
            if (! is_array($data) || ! isset($data['tag_name'])) {
                return null;
            }

            return ltrim((string) $data['tag_name'], 'v');
        } catch (\Throwable $e) {
            $this->log?->debug('Update check failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Force-clear the update cache so the next check hits the API.
     */
    public function clearCache(): void
    {
        if (file_exists($this->cachePath)) {
            @unlink($this->cachePath);
        }
    }

    private function compareVersions(string $latest): ?string
    {
        $currentNormalized = ltrim($this->currentVersion, 'v');
        $latestNormalized = ltrim($latest, 'v');

        if (version_compare($latestNormalized, $currentNormalized, '>')) {
            return $latestNormalized;
        }

        return null;
    }

    private function readCache(): ?string
    {
        if (! file_exists($this->cachePath)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($this->cachePath), true);
            if (! is_array($data) || ! isset($data['version'], $data['checked_at'])) {
                return null;
            }

            if (time() - (int) $data['checked_at'] > self::CACHE_TTL_SECONDS) {
                return null;
            }

            return (string) $data['version'];
        } catch (\Throwable $e) {
            $this->log?->warning('Update check cache read failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function writeCache(string $version): void
    {
        $dir = dirname($this->cachePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($this->cachePath, json_encode([
            'version' => $version,
            'checked_at' => time(),
        ], JSON_THROW_ON_ERROR));
    }
}

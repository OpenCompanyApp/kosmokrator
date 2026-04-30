<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

final class TelegramPollerLock
{
    /** @var resource */
    private $handle;

    private function __construct(
        private readonly string $path,
        $handle,
    ) {
        $this->handle = $handle;
    }

    public static function acquire(string $token): self
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        $dir = rtrim($home, '/').'/.kosmo/data';
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $dir.'/telegram-gateway-'.hash('sha256', $token).'.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open Telegram gateway lock file: {$path}");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another Telegram gateway worker is already polling this bot token.');
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return new self($path, $handle);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}

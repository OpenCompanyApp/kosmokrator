<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Config;

use Kosmokrator\Session\SettingsRepositoryInterface;

final class SecretConfigurator extends RuntimeConfigurator
{
    public static function forProject(string $cwd, ?string $basePath = null): self
    {
        return new self($cwd, $basePath);
    }

    public static function global(?string $basePath = null): self
    {
        return new self(null, $basePath);
    }

    public function set(string $key, string $value): self
    {
        $this->container()->make(SettingsRepositoryInterface::class)->set('global', $key, $value);

        return $this;
    }

    public function unset(string $key): self
    {
        $this->container()->make(SettingsRepositoryInterface::class)->delete('global', $key);

        return $this;
    }
}

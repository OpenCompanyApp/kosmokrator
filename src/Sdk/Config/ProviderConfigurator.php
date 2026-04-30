<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Config;

use Kosmokrator\Settings\SettingsManager;

final class ProviderConfigurator extends RuntimeConfigurator
{
    public static function forProject(string $cwd, ?string $basePath = null): self
    {
        return new self($cwd, $basePath);
    }

    public static function global(?string $basePath = null): self
    {
        return new self(null, $basePath);
    }

    public function configure(
        string $provider,
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null,
        string $scope = 'project',
        bool $makeDefault = true,
    ): self {
        $settings = $this->container()->make(SettingsManager::class);

        if ($apiKey !== null) {
            $settings->setRaw("prism.providers.{$provider}.api_key", $apiKey, $scope);
        }

        if ($baseUrl !== null) {
            $settings->setRaw("prism.providers.{$provider}.url", $baseUrl, $scope);
        }

        if ($makeDefault) {
            $settings->setRaw('kosmo.agent.default_provider', $provider, $scope);
            if ($model !== null) {
                $settings->setRaw('kosmo.agent.default_model', $model, $scope);
            }
        }

        return $this;
    }
}

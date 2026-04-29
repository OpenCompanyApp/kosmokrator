<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk\Config;

use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\YamlCredentialResolver;

final class IntegrationConfigurator extends RuntimeConfigurator
{
    public static function forProject(string $cwd, ?string $basePath = null): self
    {
        return new self($cwd, $basePath);
    }

    public static function global(?string $basePath = null): self
    {
        return new self(null, $basePath);
    }

    /**
     * @param  array<string, scalar|null>  $credentials
     * @param  array{read?: string, write?: string}  $permissions
     */
    public function configure(
        string $integration,
        string $account = 'default',
        array $credentials = [],
        bool $enabled = true,
        array $permissions = [],
        string $scope = 'project',
    ): self {
        $container = $this->container();
        $resolver = $container->make(YamlCredentialResolver::class);
        $manager = $container->make(IntegrationManager::class);
        $accountArg = $account === 'default' ? null : $account;

        if ($credentials !== []) {
            $resolver->registerAccount($integration, $account);
            foreach ($credentials as $key => $value) {
                $resolver->set($integration, $key, (string) $value, $accountArg);
            }
        }

        $manager->setEnabled($integration, $enabled, $scope);

        foreach ($permissions as $operation => $permission) {
            $manager->setPermission($integration, (string) $operation, (string) $permission, $scope);
        }

        return $this;
    }
}

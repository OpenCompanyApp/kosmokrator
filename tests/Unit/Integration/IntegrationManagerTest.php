<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Integration;

use Illuminate\Config\Repository;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use OpenCompany\IntegrationCore\Contracts\CredentialResolver;
use OpenCompany\IntegrationCore\Contracts\Tool;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;
use PHPUnit\Framework\TestCase;

final class IntegrationManagerTest extends TestCase
{
    public function test_enabled_provider_with_only_optional_credentials_is_active(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register($this->fakeProvider('coingecko', [
            ['key' => 'api_key', 'type' => 'secret', 'required' => false],
        ]));

        $settings = $this->settingsManagerWithEnabledIntegration('coingecko');

        $credentials = $this->createStub(CredentialResolver::class);

        $manager = new IntegrationManager($registry, $settings, $credentials);

        $this->assertArrayHasKey('coingecko', $manager->getActiveProviders());
    }

    public function test_enabled_provider_with_required_credentials_stays_inactive_until_configured(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register($this->fakeProvider('github', [
            ['key' => 'api_key', 'type' => 'secret', 'required' => true],
        ]));

        $settings = $this->settingsManagerWithEnabledIntegration('github');

        $credentials = $this->createStub(CredentialResolver::class);
        $credentials->method('get')->willReturn(null);

        $manager = new IntegrationManager($registry, $settings, $credentials);

        $this->assertArrayNotHasKey('github', $manager->getActiveProviders());
    }

    public function test_enabled_provider_with_required_credentials_becomes_active_when_configured(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register($this->fakeProvider('github', [
            ['key' => 'api_key', 'type' => 'secret', 'required' => true],
        ]));

        $settings = $this->settingsManagerWithEnabledIntegration('github');

        $credentials = $this->createStub(CredentialResolver::class);
        $credentials->method('get')->willReturn('token');

        $manager = new IntegrationManager($registry, $settings, $credentials);

        $this->assertArrayHasKey('github', $manager->getActiveProviders());
    }

    public function test_redirect_oauth_provider_is_discoverable_but_not_locally_runnable_by_default(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register($this->fakeProvider('google_docs', [
            ['key' => 'oauth', 'type' => 'oauth_connect', 'required' => true],
        ]));

        $settings = $this->settingsManagerWithEnabledIntegration('google_docs');
        $credentials = $this->createStub(CredentialResolver::class);
        $manager = new IntegrationManager($registry, $settings, $credentials);

        $this->assertArrayHasKey('google_docs', $manager->getDiscoverableProviders());
        $this->assertArrayNotHasKey('google_docs', $manager->getLocallyRunnableProviders());
        $this->assertFalse($manager->capabilityMetadata($registry->get('google_docs'))['cli_setup_supported']);
    }

    public function test_explicit_capabilities_override_legacy_credential_heuristics(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register(new class implements ToolProvider
        {
            public function appName(): string
            {
                return 'ticktick';
            }

            public function appMeta(): array
            {
                return ['label' => 'TickTick'];
            }

            public function tools(): array
            {
                return [];
            }

            public function isIntegration(): bool
            {
                return true;
            }

            public function createTool(string $class, array $context = []): Tool
            {
                throw new \RuntimeException('not used');
            }

            public function luaDocsPath(): ?string
            {
                return null;
            }

            public function credentialFields(): array
            {
                return [
                    ['key' => 'oauth', 'type' => 'oauth_connect', 'required' => true],
                ];
            }

            public function capabilities(): array
            {
                return [
                    'auth_strategy' => 'oauth2_authorization_code',
                    'compatibility' => [
                        'cli_setup_supported' => false,
                        'cli_runtime_supported' => true,
                    ],
                    'compatibility_summary' => 'Runtime supported with brokered credentials.',
                ];
            }
        });

        $settings = $this->settingsManagerWithEnabledIntegration('ticktick');
        $credentials = $this->createStub(CredentialResolver::class);
        $manager = new IntegrationManager($registry, $settings, $credentials);

        $this->assertArrayHasKey('ticktick', $manager->getDiscoverableProviders());
        $this->assertArrayHasKey('ticktick', $manager->getLocallyRunnableProviders());
        $this->assertFalse($manager->capabilityMetadata($registry->get('ticktick'))['cli_setup_supported']);
        $this->assertTrue($manager->capabilityMetadata($registry->get('ticktick'))['cli_runtime_supported']);
    }

    public function test_seo_capability_metadata_overrides_legacy_credential_heuristics(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register(new class implements ToolProvider
        {
            public function appName(): string
            {
                return 'google_sheets';
            }

            public function appMeta(): array
            {
                return [
                    'label' => 'Google Sheets',
                    'seo' => [
                        'auth_strategy' => 'oauth2_authorization_code',
                        'auth_summary' => 'OAuth browser setup required; proxy support planned.',
                        'cli_setup_supported' => false,
                        'cli_runtime_supported' => true,
                    ],
                ];
            }

            public function tools(): array
            {
                return [];
            }

            public function isIntegration(): bool
            {
                return true;
            }

            public function createTool(string $class, array $context = []): Tool
            {
                throw new \RuntimeException('not used');
            }

            public function luaDocsPath(): ?string
            {
                return null;
            }

            public function credentialFields(): array
            {
                return [
                    ['key' => 'token', 'type' => 'secret', 'required' => true],
                ];
            }
        });

        $settings = $this->settingsManagerWithEnabledIntegration('google_sheets');
        $credentials = $this->createStub(CredentialResolver::class);
        $manager = new IntegrationManager($registry, $settings, $credentials);
        $capabilities = $manager->capabilityMetadata($registry->get('google_sheets'));

        $this->assertSame('oauth2_authorization_code', $capabilities['auth_strategy']);
        $this->assertSame('OAuth browser setup required; proxy support planned.', $capabilities['compatibility_summary']);
        $this->assertFalse($capabilities['cli_setup_supported']);
        $this->assertTrue($capabilities['cli_runtime_supported']);
    }

    public function test_enabled_provider_with_multiple_required_credentials_stays_inactive_until_all_are_present(): void
    {
        $registry = new ToolProviderRegistry;
        $registry->register($this->fakeProvider('plane', [
            ['key' => 'api_key', 'type' => 'secret', 'required' => true],
            ['key' => 'url', 'type' => 'url', 'required' => true],
        ]));

        $settings = $this->settingsManagerWithEnabledIntegration('plane');

        $credentials = $this->createStub(CredentialResolver::class);
        $credentials->method('get')->willReturnCallback(
            static fn (string $integration, string $key, mixed $default = null): mixed => match ($key) {
                'api_key' => 'plan_live_token',
                'url' => '',
                default => $default,
            }
        );

        $manager = new IntegrationManager($registry, $settings, $credentials);

        $this->assertArrayNotHasKey('plane', $manager->getActiveProviders());
    }

    public function test_default_permission_comes_from_config(): void
    {
        $registry = new ToolProviderRegistry;
        $previousHome = getenv('HOME');
        $tempHome = sys_get_temp_dir().'/kosmo-settings-test-'.bin2hex(random_bytes(4));
        mkdir($tempHome.'/.kosmo', 0777, true);
        putenv("HOME={$tempHome}");

        try {
            $settings = new SettingsManager(
                config: new Repository(['kosmo' => ['integrations' => ['permissions_default' => 'deny']]]),
                schema: new SettingsSchema,
                store: new YamlConfigStore,
                baseConfigPath: dirname(__DIR__, 4).'/config',
            );

            $credentials = $this->createStub(CredentialResolver::class);

            $manager = new IntegrationManager($registry, $settings, $credentials);

            $this->assertSame('deny', $manager->getPermission('plane', 'write'));
            $this->assertSame('deny', $manager->getPermission('plane', 'read'));
        } finally {
            putenv($previousHome === false ? 'HOME' : "HOME={$previousHome}");
        }
    }

    private function settingsManagerWithEnabledIntegration(string $integration): SettingsManager
    {
        $settings = new SettingsManager(
            config: new Repository([]),
            schema: new SettingsSchema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 4).'/config',
        );
        $settings->setRaw("kosmo.integrations.{$integration}.enabled", true, 'global');

        return $settings;
    }

    /**
     * @param  list<array{key: string, type: string, required?: bool}>  $credentialFields
     */
    private function fakeProvider(string $name, array $credentialFields): ToolProvider
    {
        return new class($name, $credentialFields) implements ToolProvider
        {
            /**
             * @param  list<array{key: string, type: string, required?: bool}>  $credentialFields
             */
            public function __construct(
                private readonly string $name,
                private readonly array $credentialFields,
            ) {}

            public function appName(): string
            {
                return $this->name;
            }

            public function appMeta(): array
            {
                return [
                    'label' => ucfirst($this->name),
                    'description' => ucfirst($this->name).' integration',
                    'icon' => 'ph:puzzle-piece',
                ];
            }

            public function tools(): array
            {
                return [];
            }

            public function isIntegration(): bool
            {
                return true;
            }

            public function createTool(string $class, array $context = []): Tool
            {
                throw new \RuntimeException('not used');
            }

            public function luaDocsPath(): ?string
            {
                return null;
            }

            public function credentialFields(): array
            {
                return $this->credentialFields;
            }
        };
    }
}

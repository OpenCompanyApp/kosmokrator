<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\ConfigCommand;
use Kosmokrator\Command\Gateway\TelegramConfigureCommand;
use Kosmokrator\Command\Provider\ProvidersConfigureCommand;
use Kosmokrator\Command\Provider\ProvidersCustomUpsertCommand;
use Kosmokrator\Command\Provider\ProvidersLogoutCommand;
use Kosmokrator\Command\Provider\ProvidersStatusCommand;
use Kosmokrator\Command\Secrets\SecretsSetCommand;
use Kosmokrator\Command\Settings\SettingsListCommand;
use Kosmokrator\Command\Settings\SettingsOptionsCommand;
use Kosmokrator\Command\Settings\SettingsSetCommand;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\ProviderConfigurator;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SecretStore;
use Kosmokrator\Settings\SettingsCatalog;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class HeadlessConfigurationCommandsTest extends TestCase
{
    private string $originalHome;

    private string $tempHome;

    private Container $container;

    private SettingsRepository $settingsRepository;

    protected function setUp(): void
    {
        $this->originalHome = (string) getenv('HOME');
        $this->tempHome = sys_get_temp_dir().'/kk-headless-config-test-'.uniqid();
        mkdir($this->tempHome.'/.kosmokrator', 0777, true);
        putenv("HOME={$this->tempHome}");

        $configDir = dirname(__DIR__, 3).'/config';
        $config = new Repository([
            'kosmokrator' => Yaml::parseFile($configDir.'/kosmokrator.yaml'),
        ]);

        $schema = new SettingsSchema;
        $manager = new SettingsManager($config, $schema, new YamlConfigStore, $configDir);
        $this->settingsRepository = new SettingsRepository(new Database(':memory:'));
        $secretStore = new SecretStore($this->settingsRepository);

        $meta = new ProviderMeta([
            'openai' => [
                'default_model' => 'gpt-test',
                'url' => 'https://api.openai.com/v1',
                'models' => [
                    'gpt-test' => ['display_name' => 'GPT Test', 'context' => 128000, 'max_output' => 8192],
                ],
            ],
        ]);
        $registry = new RelayRegistry([
            'openai' => [
                'url' => 'https://api.openai.com/v1',
                'auth' => 'api_key',
                'driver' => 'openai',
            ],
        ]);
        $catalog = new ProviderCatalog($meta, $registry, $config, $this->settingsRepository, $this->tokenStore());

        $this->container = new Container;
        $this->container->instance('config', $config);
        $this->container->instance(SettingsSchema::class, $schema);
        $this->container->instance(SettingsManager::class, $manager);
        $this->container->instance(SettingsRepositoryInterface::class, $this->settingsRepository);
        $this->container->instance(SecretStore::class, $secretStore);
        $this->container->instance(ProviderCatalog::class, $catalog);
        $this->container->instance(SettingsCatalog::class, new SettingsCatalog($manager, $schema, $this->container));
        $this->container->instance(ProviderConfigurator::class, new ProviderConfigurator($catalog, $manager, $this->settingsRepository, $secretStore));
        $oauth = (new \ReflectionClass(CodexOAuthService::class))->newInstanceWithoutConstructor();
        $this->container->instance(CodexAuthFlow::class, new CodexAuthFlow($oauth, $this->tokenStore(), $config));
    }

    protected function tearDown(): void
    {
        putenv("HOME={$this->originalHome}");
        $this->rmDir($this->tempHome);
    }

    public function test_settings_list_exposes_array_settings_as_json(): void
    {
        $tester = new CommandTester(new SettingsListCommand($this->container));
        $exit = $tester->execute(['--category' => 'permissions', '--json' => true]);

        $this->assertSame(0, $exit);
        $data = json_decode($tester->getDisplay(), true);
        $ids = array_column($data['settings'], 'id');
        $this->assertContains('tools.safe_tools', $ids);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['categories']);
    }

    public function test_settings_list_rejects_unknown_category(): void
    {
        $tester = new CommandTester(new SettingsListCommand($this->container));
        $exit = $tester->execute(['--category' => 'nope', '--json' => true]);

        $this->assertSame(1, $exit);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($data['success']);
        $this->assertContains('permissions', $data['categories']);
    }

    public function test_settings_options_rejects_unknown_provider_context(): void
    {
        $tester = new CommandTester(new SettingsOptionsCommand($this->container));
        $exit = $tester->execute([
            'key' => 'agent.default_model',
            '--provider' => 'nope',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($data['success']);
    }

    public function test_settings_set_writes_string_list_to_yaml(): void
    {
        $tester = new CommandTester(new SettingsSetCommand($this->container));
        $exit = $tester->execute([
            'key' => 'tools.denied_tools',
            'value' => 'bash,file_write',
            '--global' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['bash', 'file_write'], $data['written_value']);
        $this->assertSame(['bash', 'file_write'], $data['effective_setting']['value']);
        $config = Yaml::parseFile($this->tempHome.'/.kosmokrator/config.yaml');
        $this->assertSame(['bash', 'file_write'], $config['kosmokrator']['tools']['denied_tools']);
    }

    public function test_settings_set_uses_provider_context_for_model_options(): void
    {
        $tester = new CommandTester(new SettingsSetCommand($this->container));
        $exit = $tester->execute([
            'key' => 'agent.default_model',
            'value' => 'gpt-test',
            '--provider' => 'openai',
            '--global' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('gpt-test', $data['written_value']);
        $config = Yaml::parseFile($this->tempHome.'/.kosmokrator/config.yaml');
        $this->assertSame('gpt-test', $config['kosmokrator']['agent']['default_model']);
    }

    public function test_config_set_validates_dynamic_model_context(): void
    {
        $tester = new CommandTester(new ConfigCommand($this->container));
        $badExit = $tester->execute([
            'action' => 'set',
            'key' => 'agent.default_model',
            'value' => 'not-a-model',
            '--provider' => 'openai',
            '--global' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $badExit);

        $tester = new CommandTester(new ConfigCommand($this->container));
        $goodExit = $tester->execute([
            'action' => 'set',
            'key' => 'agent.default_model',
            'value' => 'gpt-test',
            '--provider' => 'openai',
            '--global' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $goodExit);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('gpt-test', $data['written_value']);
    }

    public function test_provider_status_and_logout_reject_unknown_provider(): void
    {
        $status = new CommandTester(new ProvidersStatusCommand($this->container));
        $this->assertSame(1, $status->execute(['provider' => 'nope', '--json' => true]));
        $statusData = json_decode($status->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($statusData['success']);

        $logout = new CommandTester(new ProvidersLogoutCommand($this->container));
        $this->assertSame(1, $logout->execute(['provider' => 'nope', '--json' => true]));
        $logoutData = json_decode($logout->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($logoutData['success']);
    }

    public function test_providers_configure_sets_model_and_secret(): void
    {
        $tester = new CommandTester(new ProvidersConfigureCommand($this->container));
        $exit = $tester->execute([
            'provider' => 'openai',
            '--model' => 'gpt-test',
            '--api-key' => 'sk-test-secret',
            '--global' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('sk-test-secret', $this->settingsRepository->get('global', 'provider.openai.api_key'));
        $config = Yaml::parseFile($this->tempHome.'/.kosmokrator/config.yaml');
        $this->assertSame('openai', $config['kosmokrator']['agent']['default_provider']);
        $this->assertSame('gpt-test', $config['kosmokrator']['agent']['default_model']);
    }

    public function test_custom_provider_upsert_writes_definition_and_secret(): void
    {
        $tester = new CommandTester(new ProvidersCustomUpsertCommand($this->container));
        $exit = $tester->execute([
            'id' => 'local_ai',
            '--url' => 'http://localhost:8000/v1',
            '--model' => 'local-model',
            '--context' => '64000',
            '--max-output' => '4096',
            '--api-key' => 'local-secret',
            '--global' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('local-secret', $this->settingsRepository->get('global', 'provider.local_ai.api_key'));
        $config = Yaml::parseFile($this->tempHome.'/.kosmokrator/config.yaml');
        $this->assertSame('http://localhost:8000/v1', $config['relay']['providers']['local_ai']['url']);
    }

    public function test_secrets_set_never_echoes_secret_in_json(): void
    {
        $tester = new CommandTester(new SecretsSetCommand($this->container));
        $exit = $tester->execute([
            'key' => 'provider.openai.api_key',
            'value' => 'sk-super-secret-value',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('sk-super-secret-value', $tester->getDisplay());
        $this->assertSame('sk-super-secret-value', $this->settingsRepository->get('global', 'provider.openai.api_key'));
    }

    public function test_secrets_set_can_read_environment_variable(): void
    {
        putenv('KK_TEST_OPENAI_KEY=sk-env-secret');
        $tester = new CommandTester(new SecretsSetCommand($this->container));
        $exit = $tester->execute([
            'key' => 'provider.openai.api_key',
            '--env' => 'KK_TEST_OPENAI_KEY',
            '--json' => true,
        ]);

        putenv('KK_TEST_OPENAI_KEY');

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('sk-env-secret', $tester->getDisplay());
        $this->assertSame('sk-env-secret', $this->settingsRepository->get('global', 'provider.openai.api_key'));
    }

    public function test_gateway_configure_sets_token_and_settings(): void
    {
        $tester = new CommandTester(new TelegramConfigureCommand($this->container));
        $exit = $tester->execute([
            '--token' => 'telegram-secret',
            '--enabled' => 'on',
            '--session-mode' => 'thread_user',
            '--allowed-users' => '123,@maintainer',
            '--global' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('telegram-secret', $this->settingsRepository->get('global', 'gateway.telegram.token'));
        $config = Yaml::parseFile($this->tempHome.'/.kosmokrator/config.yaml');
        $this->assertSame('on', $config['kosmokrator']['gateway']['telegram']['enabled']);
        $this->assertSame('thread_user', $config['kosmokrator']['gateway']['telegram']['session_mode']);
        $this->assertSame(['123', '@maintainer'], $config['kosmokrator']['gateway']['telegram']['allowed_users']);
    }

    private function tokenStore(): CodexTokenStore
    {
        return new class implements CodexTokenStore
        {
            public function current(): ?CodexToken
            {
                return null;
            }

            public function save(CodexToken $token): CodexToken
            {
                return $token;
            }

            public function clear(): void {}
        };
    }

    private function rmDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

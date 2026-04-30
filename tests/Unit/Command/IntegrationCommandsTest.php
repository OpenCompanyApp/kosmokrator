<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\Integration\IntegrationConfigureCommand;
use Kosmokrator\Command\Integration\IntegrationDoctorCommand;
use Kosmokrator\Command\Integration\IntegrationFieldsCommand;
use Kosmokrator\Command\Integration\IntegrationListCommand;
use Kosmokrator\Command\Integration\IntegrationSchemaCommand;
use Kosmokrator\Command\Integration\IntegrationSearchCommand;
use Kosmokrator\Command\Integration\IntegrationStatusCommand;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\Runtime\IntegrationCatalog;
use Kosmokrator\Integration\YamlCredentialResolver;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use OpenCompany\IntegrationCore\Contracts\Tool;
use OpenCompany\IntegrationCore\Contracts\ToolProvider;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;
use OpenCompany\IntegrationCore\Support\ToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IntegrationCommandsTest extends TestCase
{
    private Container $container;

    private SettingsRepository $settingsRepository;

    private SettingsManager $settingsManager;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $this->settingsRepository = new SettingsRepository($db);

        $projectRoot = sys_get_temp_dir().'/kosmo-integration-command-test-'.bin2hex(random_bytes(4));
        mkdir($projectRoot, 0777, true);

        $this->settingsManager = new SettingsManager(
            config: new Repository([]),
            schema: new SettingsSchema,
            store: new YamlConfigStore,
            baseConfigPath: dirname(__DIR__, 3).'/config',
        );
        $this->settingsManager->setProjectRoot($projectRoot);

        $registry = new ToolProviderRegistry;
        $registry->register(new CommandFakeProvider);
        $registry->register(new CommandOauthProvider);

        $credentials = new YamlCredentialResolver($this->settingsRepository);
        $manager = new IntegrationManager($registry, $this->settingsManager, $credentials);

        $this->container = new Container;
        $this->container->instance(YamlCredentialResolver::class, $credentials);
        $this->container->instance(IntegrationManager::class, $manager);
        $this->container->instance(IntegrationCatalog::class, new IntegrationCatalog($registry, $manager, new LuaCatalogBuilder));
    }

    public function test_configure_stores_credentials_activation_and_permissions_headlessly(): void
    {
        $tester = new CommandTester(new IntegrationConfigureCommand($this->container));

        $exit = $tester->execute([
            'provider' => 'github',
            '--account' => 'work',
            '--set' => ['api_key=ghp_secret'],
            '--enable' => true,
            '--read' => 'allow',
            '--write' => 'deny',
            '--project' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($data['success']);
        $this->assertSame('work', $data['account']);
        $this->assertSame('ghp_secret', $this->settingsRepository->get('global', 'integration.github.accounts.work.api_key'));
        $this->assertTrue($this->settingsManager->getRaw('kosmo.integrations.github.enabled'));
        $this->assertSame('allow', $this->settingsManager->getRaw('kosmo.integrations.github.permissions.read'));
        $this->assertSame('deny', $this->settingsManager->getRaw('kosmo.integrations.github.permissions.write'));
    }

    public function test_fields_and_doctor_return_agent_friendly_json(): void
    {
        $this->settingsRepository->set('global', 'integration.github.accounts', json_encode(['default' => true]));
        $this->settingsRepository->set('global', 'integration.github.accounts.default.api_key', 'ghp_secret');
        $this->settingsManager->setRaw('kosmo.integrations.github.enabled', true, 'project');
        $this->settingsManager->setRaw('kosmo.integrations.github.permissions.read', 'allow', 'project');
        $this->settingsManager->setRaw('kosmo.integrations.github.permissions.write', 'ask', 'project');

        $fields = new CommandTester(new IntegrationFieldsCommand($this->container));
        $this->assertSame(0, $fields->execute(['provider' => 'github', '--json' => true]));
        $fieldsData = json_decode($fields->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($fieldsData['fields'][0]['configured']);

        $doctor = new CommandTester(new IntegrationDoctorCommand($this->container));
        $this->assertSame(0, $doctor->execute(['provider' => 'github', '--json' => true]));
        $doctorData = json_decode($doctor->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($doctorData['active']);
        $this->assertSame('ask', $doctorData['permissions']['write']);
        $this->assertContains('github.example', $doctorData['example_functions']);
    }

    public function test_discovery_commands_use_success_json_envelopes(): void
    {
        $list = new CommandTester(new IntegrationListCommand($this->container));
        $this->assertSame(0, $list->execute(['--json' => true]));
        $listData = json_decode($list->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($listData['success']);
        $this->assertArrayHasKey('github', $listData['providers']);

        $search = new CommandTester(new IntegrationSearchCommand($this->container));
        $this->assertSame(0, $search->execute(['query' => 'github', '--json' => true]));
        $searchData = json_decode($search->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($searchData['success']);
        $this->assertNotEmpty($searchData['functions']);

        $status = new CommandTester(new IntegrationStatusCommand($this->container));
        $this->assertSame(0, $status->execute(['--json' => true]));
        $statusData = json_decode($status->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($statusData['success']);
        $this->assertArrayHasKey('github', $statusData['providers']);
    }

    public function test_schema_errors_are_json(): void
    {
        $schema = new CommandTester(new IntegrationSchemaCommand($this->container));
        $this->assertSame(1, $schema->execute(['function' => 'missing.nope']));
        $data = json_decode($schema->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Unknown integration function', $data['error']);
    }

    public function test_empty_credentials_are_reported_as_missing(): void
    {
        $this->settingsRepository->set('global', 'integration.github.accounts', json_encode(['default' => true]));
        $this->settingsRepository->set('global', 'integration.github.accounts.default.api_key', '   ');

        $fields = new CommandTester(new IntegrationFieldsCommand($this->container));
        $this->assertSame(0, $fields->execute(['provider' => 'github', '--json' => true]));
        $fieldsData = json_decode($fields->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($fieldsData['fields'][0]['configured']);

        $doctor = new CommandTester(new IntegrationDoctorCommand($this->container));
        $this->assertSame(0, $doctor->execute(['provider' => 'github', '--json' => true]));
        $doctorData = json_decode($doctor->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('api_key', $doctorData['missing_credentials']);
    }

    public function test_non_headless_setup_integrations_remain_discoverable(): void
    {
        $fields = new CommandTester(new IntegrationFieldsCommand($this->container));
        $this->assertSame(0, $fields->execute(['provider' => 'google_docs', '--json' => true]));
        $fieldsData = json_decode($fields->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($fieldsData['cli_setup_supported']);
        $this->assertFalse($fieldsData['cli_runtime_supported']);
        $this->assertNull($fieldsData['example']);

        $doctor = new CommandTester(new IntegrationDoctorCommand($this->container));
        $this->assertSame(0, $doctor->execute(['provider' => 'google_docs', '--json' => true]));
        $doctorData = json_decode($doctor->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('oauth2_authorization_code', $doctorData['auth_strategy']);
        $this->assertFalse($doctorData['cli_setup_supported']);
        $this->assertContains('kosmo integrations:docs google_docs', $doctorData['next_commands']);

        $configure = new CommandTester(new IntegrationConfigureCommand($this->container));
        $this->assertSame(1, $configure->execute(['provider' => 'google_docs', '--enable' => true, '--json' => true]));
        $configureData = json_decode($configure->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($configureData['success']);
        $this->assertStringContainsString('does not support headless credential setup', $configureData['error']);
    }
}

final class CommandFakeProvider implements ToolProvider
{
    public function appName(): string
    {
        return 'github';
    }

    public function appMeta(): array
    {
        return ['label' => 'GitHub', 'description' => 'GitHub integration'];
    }

    public function tools(): array
    {
        return [
            'github_example' => [
                'class' => CommandFakeTool::class,
                'type' => 'read',
                'name' => 'Example',
                'description' => 'Example function',
            ],
        ];
    }

    public function isIntegration(): bool
    {
        return true;
    }

    public function createTool(string $class, array $context = []): Tool
    {
        return new CommandFakeTool;
    }

    public function luaDocsPath(): ?string
    {
        return null;
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'api_key', 'type' => 'secret', 'label' => 'API Key', 'required' => true],
        ];
    }
}

final class CommandFakeTool implements Tool
{
    public function name(): string
    {
        return 'github_example';
    }

    public function description(): string
    {
        return 'Example function';
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        return ToolResult::success(['ok' => true]);
    }
}

final class CommandOauthProvider implements ToolProvider
{
    public function appName(): string
    {
        return 'google_docs';
    }

    public function appMeta(): array
    {
        return ['label' => 'Google Docs', 'description' => 'Google Docs integration'];
    }

    public function tools(): array
    {
        return [
            'google_docs_example' => [
                'class' => CommandFakeTool::class,
                'type' => 'read',
                'name' => 'Example',
                'description' => 'Example function',
            ],
        ];
    }

    public function isIntegration(): bool
    {
        return true;
    }

    public function createTool(string $class, array $context = []): Tool
    {
        return new CommandFakeTool;
    }

    public function luaDocsPath(): ?string
    {
        return null;
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'oauth', 'type' => 'oauth_connect', 'label' => 'Connect Google', 'required' => true],
        ];
    }
}

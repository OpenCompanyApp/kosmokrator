<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\Integration\IntegrationConfigureCommand;
use Kosmokrator\Command\Integration\IntegrationDoctorCommand;
use Kosmokrator\Command\Integration\IntegrationFieldsCommand;
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
        $this->assertTrue($this->settingsManager->getRaw('integrations.github.enabled'));
        $this->assertSame('allow', $this->settingsManager->getRaw('integrations.github.permissions.read'));
        $this->assertSame('deny', $this->settingsManager->getRaw('integrations.github.permissions.write'));
    }

    public function test_fields_and_doctor_return_agent_friendly_json(): void
    {
        $this->settingsRepository->set('global', 'integration.github.accounts', json_encode(['default' => true]));
        $this->settingsRepository->set('global', 'integration.github.accounts.default.api_key', 'ghp_secret');
        $this->settingsManager->setRaw('integrations.github.enabled', true, 'project');
        $this->settingsManager->setRaw('integrations.github.permissions.read', 'allow', 'project');
        $this->settingsManager->setRaw('integrations.github.permissions.write', 'ask', 'project');

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

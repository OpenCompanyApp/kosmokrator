<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\SetupCommand;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class SetupCommandTest extends TestCase
{
    private string $originalHome;

    private string $tempHome;

    private Container $container;

    private SettingsRepository $settings;

    private SettingsManager $settingsManager;

    private SetupCommand $command;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->originalHome = (string) getenv('HOME');
        $this->tempHome = sys_get_temp_dir().'/kk-setup-test-'.uniqid();
        mkdir($this->tempHome.'/.kosmokrator', 0777, true);
        putenv("HOME={$this->tempHome}");

        $configDir = dirname(__DIR__, 3).'/config';
        $defaults = [];
        foreach (glob($configDir.'/*.yaml') as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $defaults[$key] = Yaml::parse(file_get_contents($file)) ?? [];
        }

        $config = new Repository($defaults);
        $this->settings = new SettingsRepository(new Database(':memory:'));
        $this->settingsManager = new SettingsManager(
            $config,
            new SettingsSchema,
            new YamlConfigStore,
            $configDir,
        );

        $meta = new ProviderMeta([
            'openai' => [
                'default_model' => 'gpt-5.4-mini',
                'url' => 'https://api.openai.com/v1',
                'models' => [
                    'gpt-5.4-mini' => [
                        'display_name' => 'GPT-5.4 Mini',
                        'context' => 128000,
                        'max_output' => 16384,
                    ],
                ],
            ],
        ]);

        $catalog = new ProviderCatalog(
            $meta,
            new RelayRegistry([
                'openai' => [
                    'url' => 'https://api.openai.com/v1',
                    'auth' => 'api_key',
                    'driver' => 'openai',
                ],
            ]),
            $config,
            $this->settings,
            $this->createTokenStore(),
        );

        $oauth = (new \ReflectionClass(CodexOAuthService::class))->newInstanceWithoutConstructor();
        $codex = new CodexAuthFlow($oauth, $this->createTokenStore(), $config);

        $this->container = new Container;
        $this->container->instance('config', $config);
        $this->container->instance(SettingsRepositoryInterface::class, $this->settings);
        $this->container->instance(SettingsManager::class, $this->settingsManager);
        $this->container->instance(ProviderCatalog::class, $catalog);
        $this->container->instance(CodexAuthFlow::class, $codex);

        $promptValues = ['openai', 'gpt-5.4-mini', 'sk-test-1234'];
        $this->command = new SetupCommand(
            $this->container,
            static function () use (&$promptValues): string {
                return array_shift($promptValues) ?? '';
            },
        );

        $app = new Application;
        $app->addCommand($this->command);
        $this->tester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        putenv("HOME={$this->originalHome}");
        @unlink($this->tempHome.'/.kosmokrator/config.yaml');
        @rmdir($this->tempHome.'/.kosmokrator');
        @rmdir($this->tempHome);
    }

    public function test_command_name_is_setup(): void
    {
        $this->assertSame('setup', $this->command->getName());
    }

    public function test_command_has_correct_description(): void
    {
        $this->assertSame('Configure KosmoKrator (API keys, provider, model)', $this->command->getDescription());
    }

    public function test_setup_command_can_run_without_readline_and_persists_settings(): void
    {
        ob_start();
        $exitCode = $this->tester->execute([]);
        $display = (string) ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('KosmoKrator Setup', $display);
        $this->assertStringContainsString('Settings saved', $display);
        $this->assertSame('sk-test-1234', $this->settings->get('global', 'provider.openai.api_key'));

        $globalConfig = Yaml::parseFile($this->tempHome.'/.kosmokrator/config.yaml');
        $this->assertSame('openai', $globalConfig['kosmokrator']['agent']['default_provider'] ?? null);
        $this->assertSame('gpt-5.4-mini', $globalConfig['kosmokrator']['agent']['default_model'] ?? null);
    }

    private function createTokenStore(): CodexTokenStore
    {
        return new class implements CodexTokenStore
        {
            private ?CodexToken $token = null;

            public function current(): ?CodexToken
            {
                return $this->token;
            }

            public function save(CodexToken $token): CodexToken
            {
                $this->token = $token;

                return $token;
            }

            public function clear(): void
            {
                $this->token = null;
            }
        };
    }
}

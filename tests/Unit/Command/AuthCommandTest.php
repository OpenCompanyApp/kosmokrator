<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Command;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\AuthCommand;
use Kosmokrator\LLM\Codex\CodexAuthFlow;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Session\Database;
use Kosmokrator\Session\SettingsRepository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class AuthCommandTest extends TestCase
{
    private Container $container;

    private ProviderCatalog $catalog;

    private SettingsRepository $settings;

    private CodexAuthFlow $codex;

    private CommandTester $tester;

    protected function setUp(): void
    {
        // Build real, lightweight instances with in-memory SQLite and empty config
        $db = new Database(':memory:');
        $this->settings = new SettingsRepository($db);

        $tokenStore = $this->createTokenStore();
        $config = new Repository;

        // ProviderMeta with codex (oauth) and ollama (none) so authMode returns correctly
        $meta = new ProviderMeta([
            'codex' => [
                'default_model' => 'gpt-5-codex',
                'url' => 'https://chatgpt.com/backend-api/codex',
                'models' => ['gpt-5-codex' => ['display_name' => 'GPT-5 Codex']],
            ],
            'ollama' => [
                'default_model' => 'llama3',
                'url' => 'http://localhost:11434/v1',
                'models' => ['llama3' => ['display_name' => 'Llama 3']],
            ],
        ]);

        $registry = new RelayRegistry;
        $this->catalog = new ProviderCatalog($meta, $registry, $config, $this->settings, $tokenStore);

        // CodexAuthFlow — use an uninitialized CodexOAuthService
        $oauthRef = new \ReflectionClass(CodexOAuthService::class);
        $oauth = $oauthRef->newInstanceWithoutConstructor();
        $this->codex = new CodexAuthFlow($oauth, $tokenStore, $config);

        $this->container = new Container;
        $catalog = $this->catalog;
        $settings = $this->settings;
        $codex = $this->codex;

        $this->container->singleton(ProviderCatalog::class, static fn () => $catalog);
        $this->container->singleton(SettingsRepository::class, static fn () => $settings);
        $this->container->alias(SettingsRepository::class, SettingsRepositoryInterface::class);
        $this->container->singleton(CodexAuthFlow::class, static fn () => $codex);

        $command = new AuthCommand($this->container);

        $app = new Application;
        $app->addCommand($command);
        $this->tester = new CommandTester($command);
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

    // ── Status action ─────────────────────────────────────────────────

    public function test_status_for_single_provider(): void
    {
        $exit = $this->tester->execute(['action' => 'status', 'provider' => 'codex']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('codex:', $this->tester->getDisplay());
        $this->assertStringContainsString('Not authenticated', $this->tester->getDisplay());
    }

    public function test_status_without_provider_shows_table(): void
    {
        $exit = $this->tester->execute(['action' => 'status']);

        $this->assertSame(0, $exit);
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('codex', $display);
        $this->assertStringContainsString('ollama', $display);
        $this->assertStringContainsString('Provider', $display);
    }

    public function test_status_is_default_action(): void
    {
        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
    }

    // ── Invalid action ────────────────────────────────────────────────

    public function test_invalid_action_returns_invalid(): void
    {
        $exit = $this->tester->execute(['action' => 'bogus']);

        $this->assertSame(2, $exit);
    }

    // ── Login action ──────────────────────────────────────────────────

    public function test_login_without_provider_returns_error(): void
    {
        $exit = $this->tester->execute(['action' => 'login']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Provide a provider name', $this->tester->getDisplay());
    }

    public function test_login_with_api_key_provider_stores_key(): void
    {
        // 'anthropic' is not in meta, so authMode defaults to 'api_key'
        $exit = $this->tester->execute(
            ['action' => 'login', 'provider' => 'anthropic', '--api-key' => 'sk-test-123'],
        );

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Stored API key for anthropic', $this->tester->getDisplay());

        // Verify key was actually persisted
        $stored = $this->settings->get('global', 'provider.anthropic.api_key');
        $this->assertSame('sk-test-123', $stored);
    }

    public function test_login_with_none_auth_returns_comment(): void
    {
        $exit = $this->tester->execute(['action' => 'login', 'provider' => 'ollama']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('does not require login', $this->tester->getDisplay());
    }

    // ── Logout action ─────────────────────────────────────────────────

    public function test_logout_without_provider_returns_error(): void
    {
        $exit = $this->tester->execute(['action' => 'logout']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Provide a provider name', $this->tester->getDisplay());
    }

    public function test_logout_with_api_key_provider_removes_key(): void
    {
        // First store a key
        $this->settings->set('global', 'provider.anthropic.api_key', 'sk-test-123');
        $this->assertNotNull($this->settings->get('global', 'provider.anthropic.api_key'));

        $exit = $this->tester->execute(['action' => 'logout', 'provider' => 'anthropic']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Removed API key for anthropic', $this->tester->getDisplay());
        $this->assertNull($this->settings->get('global', 'provider.anthropic.api_key'));
    }

    public function test_logout_with_oauth_provider_calls_codex_logout(): void
    {
        $exit = $this->tester->execute(['action' => 'logout', 'provider' => 'codex']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Logged out from codex', $this->tester->getDisplay());
    }

    public function test_logout_with_none_auth_returns_comment(): void
    {
        $exit = $this->tester->execute(['action' => 'logout', 'provider' => 'ollama']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('does not require auth', $this->tester->getDisplay());
    }
}

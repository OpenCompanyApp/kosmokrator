<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\LLM;

use InvalidArgumentException;
use Kosmokrator\LLM\RelayProviderRegistrar;
use Kosmokrator\LLM\RelayProviderRegistry;
use OpenCompany\PrismCodex\CodexOAuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Prism\Prism\PrismManager;

final class RelayProviderRegistrarTest extends TestCase
{
    private RelayProviderRegistry $registry;

    private CodexOAuthService $codexOAuth;

    protected function setUp(): void
    {
        $this->registry = new RelayProviderRegistry(new \Illuminate\Config\Repository([]));
        $this->codexOAuth = $this->createStub(CodexOAuthService::class);
    }

    // ---------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------

    public function test_constructor_accepts_dependencies(): void
    {
        $registrar = new RelayProviderRegistrar($this->registry, $this->codexOAuth);

        $this->assertInstanceOf(RelayProviderRegistrar::class, $registrar);
    }

    // ---------------------------------------------------------------
    // register() iterates all providers
    // ---------------------------------------------------------------

    public function test_register_calls_extend_once_per_provider(): void
    {
        $providerCount = count($this->registry->allProviders());
        $manager = $this->createMock(PrismManager::class);

        $manager->expects($this->exactly($providerCount))
            ->method('extend')
            ->willReturnCallback(fn (): PrismManager => $manager);

        $registrar = new RelayProviderRegistrar($this->registry, $this->codexOAuth);
        $registrar->register($manager);
    }

    public function test_register_passes_each_provider_name_to_extend(): void
    {
        $expectedProviders = $this->registry->allProviders();
        $actualProviders = [];
        $manager = $this->createMock(PrismManager::class);

        $manager->expects($this->exactly(count($expectedProviders)))
            ->method('extend')
            ->willReturnCallback(function (string $name, callable $factory) use (&$actualProviders, $manager): PrismManager {
                $actualProviders[] = $name;

                return $manager;
            });

        $registrar = new RelayProviderRegistrar($this->registry, $this->codexOAuth);
        $registrar->register($manager);

        sort($expectedProviders);
        sort($actualProviders);
        $this->assertSame($expectedProviders, $actualProviders);
    }

    // ---------------------------------------------------------------
    // Driver factory creates correct instances for each provider
    // ---------------------------------------------------------------

    public function test_anthropic_driver_creates_anthropic_instance(): void
    {
        $result = $this->invokeFactoryForProvider('anthropic', ['api_key' => 'test-key']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Anthropic\Anthropic::class, $result);
    }

    public function test_openai_driver_creates_openai_instance(): void
    {
        $result = $this->invokeFactoryForProvider('openai', ['api_key' => 'sk-test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\OpenAI\OpenAI::class, $result);
    }

    public function test_codex_driver_creates_codex_instance(): void
    {
        $result = $this->invokeFactoryForProvider('codex', []);

        $this->assertInstanceOf(\OpenCompany\PrismCodex\Codex::class, $result);
    }

    public function test_gemini_driver_creates_gemini_instance(): void
    {
        $result = $this->invokeFactoryForProvider('gemini', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Gemini\Gemini::class, $result);
    }

    public function test_deepseek_driver_creates_openai_instance(): void
    {
        // deepseek now uses 'openai-compatible' driver from upstream
        $result = $this->invokeFactoryForProvider('deepseek', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\OpenAI\OpenAI::class, $result);
    }

    public function test_groq_driver_creates_groq_instance(): void
    {
        $result = $this->invokeFactoryForProvider('groq', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Groq\Groq::class, $result);
    }

    public function test_mistral_driver_creates_mistral_instance(): void
    {
        $result = $this->invokeFactoryForProvider('mistral', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Mistral\Mistral::class, $result);
    }

    public function test_ollama_driver_creates_ollama_instance(): void
    {
        $result = $this->invokeFactoryForProvider('ollama', ['api_key' => '']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Ollama\Ollama::class, $result);
    }

    public function test_openrouter_driver_creates_openrouter_instance(): void
    {
        $result = $this->invokeFactoryForProvider('openrouter', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\OpenRouter\OpenRouter::class, $result);
    }

    public function test_perplexity_driver_creates_perplexity_instance(): void
    {
        $result = $this->invokeFactoryForProvider('perplexity', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Perplexity\Perplexity::class, $result);
    }

    public function test_xai_driver_creates_xai_instance(): void
    {
        $result = $this->invokeFactoryForProvider('xai', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\XAI\XAI::class, $result);
    }

    public function test_z_driver_creates_z_instance(): void
    {
        $result = $this->invokeFactoryForProvider('z', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Z\Z::class, $result);
    }

    public function test_kimi_driver_creates_deepseek_instance(): void
    {
        $result = $this->invokeFactoryForProvider('kimi', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\DeepSeek\DeepSeek::class, $result);
    }

    public function test_kimi_coding_driver_creates_deepseek_instance(): void
    {
        $result = $this->invokeFactoryForProvider('kimi-coding', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\DeepSeek\DeepSeek::class, $result);
    }

    public function test_z_api_driver_creates_z_instance(): void
    {
        // z-api maps to the 'glm' driver which resolves to Z class
        $result = $this->invokeFactoryForProvider('z-api', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Z\Z::class, $result);
    }

    public function test_minimax_driver_creates_anthropic_instance(): void
    {
        // minimax uses 'anthropic-compatible' driver which resolves to Anthropic class
        $result = $this->invokeFactoryForProvider('minimax', ['api_key' => 'test']);

        $this->assertInstanceOf(\Prism\Prism\Providers\Anthropic\Anthropic::class, $result);
    }

    // ---------------------------------------------------------------
    // Unknown driver throws InvalidArgumentException
    // ---------------------------------------------------------------

    public function test_unknown_driver_throws_invalid_argument_exception(): void
    {
        // Register a custom provider with an unsupported driver via config
        $registry = new RelayProviderRegistry(new \Illuminate\Config\Repository([
            'relay' => [
                'providers' => [
                    'mystery-provider' => [
                        'driver' => 'totally-unknown-driver',
                    ],
                ],
            ],
        ]));

        $capturedFactory = null;
        $manager = $this->createMock(PrismManager::class);
        $manager->expects($this->atLeastOnce())
            ->method('extend')
            ->willReturnCallback(function (string $name, callable $factory) use (&$capturedFactory, $manager): PrismManager {
                if ($name === 'mystery-provider') {
                    $capturedFactory = $factory;
                }

                return $manager;
            });

        $registrar = new RelayProviderRegistrar($registry, $this->codexOAuth);
        $registrar->register($manager);

        $this->assertNotNull($capturedFactory);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported provider driver [totally-unknown-driver] for [mystery-provider].');

        $capturedFactory(null, []);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Captures and invokes the factory callable registered via PrismManager::extend()
     * for the given provider name.
     */
    private function invokeFactoryForProvider(string $providerName, array $config = []): object
    {
        $capturedFactory = null;
        $manager = $this->createMock(PrismManager::class);
        $manager->expects($this->atLeastOnce())
            ->method('extend')
            ->willReturnCallback(function (string $name, callable $factory) use ($providerName, &$capturedFactory, $manager): PrismManager {
                if ($name === $providerName) {
                    $capturedFactory = $factory;
                }

                return $manager;
            });

        $registrar = new RelayProviderRegistrar($this->registry, $this->codexOAuth);
        $registrar->register($manager);

        $this->assertNotNull($capturedFactory, "Factory for provider [{$providerName}] was not registered.");

        return $capturedFactory(null, $config);
    }
}

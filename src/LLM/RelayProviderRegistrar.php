<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use InvalidArgumentException;
use OpenCompany\PrismCodex\Codex;
use OpenCompany\PrismCodex\CodexOAuthService;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Anthropic\Anthropic;
use Prism\Prism\Providers\DeepSeek\DeepSeek;
use Prism\Prism\Providers\Gemini\Gemini;
use Prism\Prism\Providers\Groq\Groq;
use Prism\Prism\Providers\Mistral\Mistral;
use Prism\Prism\Providers\Ollama\Ollama;
use Prism\Prism\Providers\OpenAI\OpenAI;
use Prism\Prism\Providers\OpenRouter\OpenRouter;
use Prism\Prism\Providers\Perplexity\Perplexity;
use Prism\Prism\Providers\XAI\XAI;
use Prism\Prism\Providers\Z\Z;

/**
 * Registers all known LLM providers into the Prism PrismManager at boot time.
 *
 * Iterates over RelayProviderRegistry's provider list and instantiates the correct
 * Prism driver (Anthropic, OpenAI, Codex, etc.) for each one based on its driver type.
 * Called during application bootstrap to make all providers available via Prism's service locator.
 */
final class RelayProviderRegistrar
{
    public function __construct(
        private readonly RelayProviderRegistry $registry,
        private readonly CodexOAuthService $codexOAuth,
    ) {}

    /**
     * Extend the Prism manager with all providers from RelayProviderRegistry.
     *
     * @param PrismManager $manager The Prism service manager to register drivers into
     */
    public function register(PrismManager $manager): void
    {
        foreach ($this->registry->allProviders() as $provider) {
            $driver = $this->registry->driver($provider);

            $manager->extend($provider, function ($app, array $config) use ($provider, $driver) {
                $url = $config['url'] ?? $this->registry->url($provider);

                return match ($driver) {
                    'codex' => new Codex(
                        oauthService: $this->codexOAuth,
                        url: $url ?: 'https://chatgpt.com/backend-api/codex',
                        accountId: $config['account_id'] ?? null,
                    ),
                    'anthropic', 'anthropic-compatible' => new Anthropic(
                        apiKey: $config['api_key'] ?? '',
                        apiVersion: $config['version'] ?? '2023-06-01',
                        url: $url ?: 'https://api.anthropic.com/v1',
                    ),
                    'deepseek', 'kimi', 'kimi-coding' => new DeepSeek(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                    ),
                    'gemini' => new Gemini(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                    ),
                    'groq' => new Groq(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                    ),
                    'minimax' => new Anthropic(
                        apiKey: $config['api_key'] ?? '',
                        apiVersion: $config['version'] ?? '2023-06-01',
                        url: $url ?: 'https://api.minimax.io/anthropic/v1',
                    ),
                    'minimax-cn' => new Anthropic(
                        apiKey: $config['api_key'] ?? '',
                        apiVersion: $config['version'] ?? '2023-06-01',
                        url: $url ?: 'https://api.minimaxi.com/anthropic/v1',
                    ),
                    'mistral' => new Mistral(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                    ),
                    'ollama' => new Ollama(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                    ),
                    'openrouter' => new OpenRouter(
                        apiKey: $config['api_key'] ?? '',
                        url: $url ?: 'https://openrouter.ai/api/v1',
                    ),
                    'perplexity' => new Perplexity(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                    ),
                    'xai' => new XAI(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                    ),
                    'z', 'glm', 'glm-coding' => new Z(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                    ),
                    'openai', 'openai-compatible' => new OpenAI(
                        apiKey: $config['api_key'] ?? '',
                        url: $url,
                        organization: $config['organization'] ?? null,
                        project: $config['project'] ?? null,
                    ),
                    default => throw new InvalidArgumentException(sprintf('Unsupported provider driver [%s] for [%s].', $driver, $provider)),
                };
            });
        }
    }
}

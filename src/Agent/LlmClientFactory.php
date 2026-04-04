<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Illuminate\Container\Container;
use Kosmokrator\Exception\AuthenticationException;
use Kosmokrator\Exception\ConfigurationException;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\LLM\RetryableLlmClient;
use Kosmokrator\UI\UIManager;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismRelay\Registry\RelayRegistry;

/**
 * Creates and configures the LLM client for an agent session.
 *
 * Validates API key / OAuth credentials, selects sync (PrismService) vs
 * async (AsyncLlmClient) based on renderer type, and wires retry notifications.
 */
final class LlmClientFactory
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Create a configured LLM client appropriate for the given renderer.
     *
     * @param  string  $rendererType  Active renderer type ('tui' or 'ansi')
     * @param  UIManager  $ui  UI manager for retry notifications
     * @return LlmClientInterface Configured client (possibly wrapped in RetryableLlmClient)
     *
     * @throws AuthenticationException|ConfigurationException
     */
    public function create(string $rendererType, UIManager $ui): LlmClientInterface
    {
        $config = $this->container->make('config');
        $provider = $config->get('kosmokrator.agent.default_provider', 'z');
        $this->validateAuth($provider);

        $registry = $this->container->make(RelayRegistry::class);
        $useAsync = $rendererType === 'tui' && $registry->supportsAsync($provider);

        /** @var LlmClientInterface $llm */
        $llm = $useAsync
            ? $this->container->make(AsyncLlmClient::class)
            : $this->container->make(PrismService::class);

        if ($llm instanceof RetryableLlmClient) {
            $llm->setOnRetry(function (int $attempt, float $delay, string $reason) use ($ui) {
                $delaySec = (int) ceil($delay);
                $ui->showNotice("⟳ Retrying in {$delaySec}s (attempt {$attempt})");
            });
        }

        return $llm;
    }

    /**
     * Validate that the provider has valid authentication configured.
     *
     * @throws AuthenticationException|ConfigurationException
     */
    private function validateAuth(string $provider): void
    {
        $providers = $this->container->make(ProviderCatalog::class);
        $authMode = $providers->authMode($provider);

        if ($authMode === 'oauth') {
            $oauth = $this->container->make(CodexOAuthService::class);
            if (! $oauth->isConfigured()) {
                throw new AuthenticationException('Codex is not authenticated. Run `kosmokrator codex:login`.');
            }
        } elseif ($authMode === 'api_key') {
            $apiKey = $providers->apiKey($provider);
            if ($apiKey === '') {
                throw new ConfigurationException("No API key configured for {$provider}.");
            }
        }
    }
}

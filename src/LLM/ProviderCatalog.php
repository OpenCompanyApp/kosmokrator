<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Illuminate\Config\Repository;
use Kosmokrator\Session\SettingsRepository;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismRelay\Meta\ProviderMeta;
use OpenCompany\PrismRelay\Registry\RelayRegistry;

final class ProviderCatalog
{
    /** @var list<string> */
    private const HIDDEN_PROVIDER_IDS = [
        'zhipuai',
    ];

    /** @var array<string, array{label: string, description: string, auth: string}> */
    private const DEFINITIONS = [
        'anthropic' => ['label' => 'Anthropic', 'description' => 'Claude models via Anthropic API key', 'auth' => 'api_key'],
        'openai' => ['label' => 'OpenAI', 'description' => 'OpenAI API models via API key', 'auth' => 'api_key'],
        'codex' => ['label' => 'Codex', 'description' => 'ChatGPT subscription models via OAuth login', 'auth' => 'oauth'],
        'gemini' => ['label' => 'Gemini', 'description' => 'Google Gemini models via API key', 'auth' => 'api_key'],
        'deepseek' => ['label' => 'DeepSeek', 'description' => 'DeepSeek chat and reasoning models via API key', 'auth' => 'api_key'],
        'groq' => ['label' => 'Groq', 'description' => 'Fast hosted inference via API key', 'auth' => 'api_key'],
        'mistral' => ['label' => 'Mistral', 'description' => 'Mistral and Codestral models via API key', 'auth' => 'api_key'],
        'xai' => ['label' => 'xAI', 'description' => 'Grok models via API key', 'auth' => 'api_key'],
        'openrouter' => ['label' => 'OpenRouter', 'description' => 'Router over multiple model providers via API key', 'auth' => 'api_key'],
        'perplexity' => ['label' => 'Perplexity', 'description' => 'Perplexity search models via API key', 'auth' => 'api_key'],
        'ollama' => ['label' => 'Ollama', 'description' => 'Local models, no remote credentials required', 'auth' => 'none'],
        'kimi' => ['label' => 'Kimi', 'description' => 'Moonshot Kimi models via API key', 'auth' => 'api_key'],
        'kimi-coding' => ['label' => 'Kimi Coding', 'description' => 'Moonshot coding-plan endpoint via API key', 'auth' => 'api_key'],
        'mimo' => ['label' => 'Xiaomi MiMo Token Plan', 'description' => 'MiMo token-plan models via token-plan key', 'auth' => 'api_key'],
        'mimo-api' => ['label' => 'Xiaomi MiMo API', 'description' => 'MiMo pay-as-you-go API via API key', 'auth' => 'api_key'],
        'minimax' => ['label' => 'MiniMax', 'description' => 'MiniMax models via API key', 'auth' => 'api_key'],
        'minimax-cn' => ['label' => 'MiniMax CN', 'description' => 'MiniMax China-region endpoint via API key', 'auth' => 'api_key'],
        'z' => ['label' => 'Z.AI', 'description' => 'Z.AI coding endpoint via API key', 'auth' => 'api_key'],
        'z-api' => ['label' => 'Z.AI API', 'description' => 'Z.AI standard API endpoint via API key', 'auth' => 'api_key'],
        'stepfun' => ['label' => 'StepFun', 'description' => 'StepFun models via API key', 'auth' => 'api_key'],
        'stepfun-plan' => ['label' => 'StepFun Plan', 'description' => 'StepFun Step Plan subscription endpoint via API key', 'auth' => 'api_key'],
    ];

    /** @var list<string> */
    private const ORDER = [
        'anthropic',
        'openai',
        'codex',
        'mimo',
        'mimo-api',
        'gemini',
        'deepseek',
        'groq',
        'mistral',
        'xai',
        'openrouter',
        'perplexity',
        'ollama',
        'kimi',
        'kimi-coding',
        'minimax',
        'minimax-cn',
        'z',
        'z-api',
        'stepfun',
        'stepfun-plan',
    ];

    public function __construct(
        private readonly ProviderMeta $meta,
        private readonly RelayRegistry $registry,
        private readonly Repository $config,
        private readonly SettingsRepository $settings,
        private readonly CodexTokenStore $codexTokens,
    ) {}

    /**
     * @return list<ProviderDefinition>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ($this->configuredProviderIds() as $provider) {
            if (! $this->isSelectableProvider($provider) || $this->isHiddenProvider($provider)) {
                continue;
            }

            $definition = $this->provider($provider);
            if ($definition !== null) {
                $providers[] = $definition;
            }
        }

        return $providers;
    }

    public function provider(string $provider): ?ProviderDefinition
    {
        if (! $this->meta->has($provider)) {
            return null;
        }

        $registryDefinition = $this->registry->provider($provider) ?? [];
        $fallback = self::DEFINITIONS[$provider] ?? [
            'label' => $this->humanize($provider),
            'description' => $this->humanize($provider).' provider',
            'auth' => $this->registry->authMode($provider),
        ];

        $definition = array_merge($fallback, array_filter([
            'label' => $registryDefinition['label'] ?? null,
            'description' => $registryDefinition['description'] ?? null,
            'auth' => $registryDefinition['auth'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        $models = [];
        foreach ($this->meta->models($provider) as $model) {
            $info = $this->meta->modelInfo($provider, $model);
            $modalities = $this->registry->modelModalities($provider, $model);
            $models[] = new ModelDefinition(
                id: $model,
                displayName: $info->displayName ?? $model,
                contextWindow: $info->contextWindow,
                maxOutput: $info->maxOutput,
                thinking: $info->thinking,
                inputPricePerMillion: $info->inputPricePerMillion,
                outputPricePerMillion: $info->outputPricePerMillion,
                pricingKind: $info->pricingKind,
                referenceInputPricePerMillion: $info->referenceInputPricePerMillion,
                referenceOutputPricePerMillion: $info->referenceOutputPricePerMillion,
                status: $info->status,
                inputModalities: $modalities['input'],
                outputModalities: $modalities['output'],
            );
        }

        $providerModalities = $this->registry->providerModalities($provider);

        return new ProviderDefinition(
            id: $provider,
            label: $definition['label'],
            description: $definition['description'],
            authMode: $definition['auth'],
            source: $this->registry->source($provider),
            driver: $this->registry->driver($provider),
            url: $this->registry->url($provider),
            defaultModel: $this->meta->defaultModel($provider) ?? ($models[0]->id ?? ''),
            models: $models,
            inputModalities: $providerModalities['input'],
            outputModalities: $providerModalities['output'],
        );
    }

    public function defaultModel(string $provider): ?string
    {
        return $this->provider($provider)?->defaultModel;
    }

    /**
     * @return list<string>
     */
    public function modelIds(string $provider): array
    {
        return array_map(
            static fn (ModelDefinition $model): string => $model->id,
            $this->provider($provider)?->models ?? [],
        );
    }

    public function supportsModel(string $provider, string $model): bool
    {
        return in_array(strtolower($model), array_map('strtolower', $this->modelIds($provider)), true);
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public function providerOptions(): array
    {
        return array_map(
            fn (ProviderDefinition $provider): array => [
                'value' => $provider->id,
                'label' => $provider->label,
                'description' => "{$provider->id} · {$provider->description} · "
                    .count($provider->models).' models · '
                    .($provider->source === 'custom' ? 'Custom' : 'Built-in'),
            ],
            $this->providers(),
        );
    }

    /**
     * @return array<string, list<array{value: string, label: string, description: string}>>
     */
    public function modelOptionsByProvider(): array
    {
        $options = [];

        foreach ($this->providers() as $provider) {
            $options[$provider->id] = array_map(
                fn (ModelDefinition $model): array => [
                    'value' => $model->id,
                    'label' => $model->displayName,
                    'description' => $model->id.' · '.$this->formatModelDescription($model),
                ],
                $provider->models,
            );
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function authModes(): array
    {
        $modes = [];
        foreach ($this->providers() as $provider) {
            $modes[$provider->id] = $provider->authMode;
        }

        return $modes;
    }

    /**
     * @return array<string, string>
     */
    public function authStatuses(): array
    {
        $statuses = [];
        foreach ($this->providers() as $provider) {
            $statuses[$provider->id] = $this->authStatus($provider->id);
        }

        return $statuses;
    }

    public function authMode(string $provider): string
    {
        return $this->provider($provider)?->authMode ?? 'api_key';
    }

    public function authStatus(string $provider): string
    {
        return match ($this->authMode($provider)) {
            'oauth' => $this->codexStatus(),
            'none' => 'No authentication required',
            default => $this->apiKeyStatus($provider),
        };
    }

    public function maskedCredential(string $provider): string
    {
        return match ($this->authMode($provider)) {
            'oauth' => '(managed by login flow)',
            'none' => '(not required)',
            default => $this->maskKey($this->apiKey($provider)),
        };
    }

    public function apiKey(string $provider): string
    {
        return (string) ($this->settings->get('global', "provider.{$provider}.api_key")
            ?? $this->config->get("prism.providers.{$provider}.api_key", ''));
    }

    private function codexStatus(): string
    {
        $token = $this->codexTokens->current();
        if ($token === null) {
            return 'Not authenticated';
        }

        $label = $token->email ?? $token->accountId ?? 'ChatGPT account';

        if ($token->isExpired()) {
            return "Expired · {$label}";
        }

        if ($token->isExpiringSoon()) {
            return "Active, refresh soon · {$label}";
        }

        return "Authenticated · {$label}";
    }

    private function apiKeyStatus(string $provider): string
    {
        $key = $this->apiKey($provider);

        if ($key === '') {
            return 'API key not configured';
        }

        return 'Configured · '.$this->maskKey($key);
    }

    /**
     * @return list<string>
     */
    private function configuredProviderIds(): array
    {
        $configured = $this->meta->allProviders();

        usort($configured, function (string $left, string $right): int {
            $leftIndex = array_search($left, self::ORDER, true);
            $rightIndex = array_search($right, self::ORDER, true);

            $leftIndex = $leftIndex === false ? PHP_INT_MAX : $leftIndex;
            $rightIndex = $rightIndex === false ? PHP_INT_MAX : $rightIndex;

            return $leftIndex <=> $rightIndex ?: strcmp($left, $right);
        });

        return $configured;
    }

    private function formatModelDescription(ModelDefinition $model): string
    {
        $parts = [
            $this->formatTokens($model->contextWindow).' ctx',
            $this->formatTokens($model->maxOutput).' out',
        ];

        if ($model->thinking) {
            $parts[] = 'thinking';
        }

        if ($model->pricingKind === 'coding_plan'
            && $model->referenceInputPricePerMillion !== null
            && $model->referenceOutputPricePerMillion !== null) {
            $parts[] = '$'.$this->trimFloat($model->referenceInputPricePerMillion)
                .'/$'.$this->trimFloat($model->referenceOutputPricePerMillion)
                .' per 1M · Coding Plan';
        } elseif ($model->pricingKind === 'token_plan') {
            $parts[] = 'Token Plan';
        } elseif ($model->pricingKind === 'public_free') {
            $parts[] = 'Free';
        } elseif ($model->inputPricePerMillion !== null && $model->outputPricePerMillion !== null) {
            $parts[] = '$'.$this->trimFloat($model->inputPricePerMillion).'/$'.$this->trimFloat($model->outputPricePerMillion).' per 1M';
        }

        if ($model->status !== null && $model->status !== '' && $model->status !== 'active') {
            $parts[] = $model->status;
        }

        return implode(' · ', $parts);
    }

    private function isSelectableProvider(string $provider): bool
    {
        return ! in_array($this->registry->driver($provider), [
            'unsupported',
            'external-process',
            'google-vertex',
            'amazon-bedrock',
        ], true);
    }

    private function isHiddenProvider(string $provider): bool
    {
        if (in_array($provider, self::HIDDEN_PROVIDER_IDS, true)) {
            return true;
        }

        foreach ($this->registry->allProviders() as $definition) {
            if (($definition['source'] ?? '') !== 'custom') {
                continue;
            }

            if (($definition['models_dev_provider'] ?? null) === $provider) {
                return true;
            }
        }

        return false;
    }

    private function formatTokens(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return $this->trimFloat($tokens / 1_000_000).'M';
        }

        if ($tokens >= 1_000) {
            return $this->trimFloat($tokens / 1_000).'k';
        }

        return (string) $tokens;
    }

    private function trimFloat(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function humanize(string $value): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $value));
    }

    private function maskKey(string $key): string
    {
        if ($key === '') {
            return '(not set)';
        }

        if (strlen($key) < 12) {
            return '***';
        }

        return substr($key, 0, 8).'...'.substr($key, -4);
    }
}

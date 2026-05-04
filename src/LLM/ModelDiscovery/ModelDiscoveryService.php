<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\ModelDiscovery;

use Illuminate\Config\Repository;
use Kosmokrator\Session\SettingsRepositoryInterface;
use OpenCompany\PrismRelay\Registry\RelayRegistry;

final class ModelDiscoveryService
{
    private const LIVE_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly RelayRegistry $registry,
        private readonly Repository $config,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ModelDiscoveryCacheRepository $cache,
    ) {}

    public function cached(string $provider, bool $freshOnly = false): ?ModelDiscoveryResult
    {
        return $this->cache->get($provider, freshOnly: $freshOnly);
    }

    public function refresh(string $provider): ModelDiscoveryResult
    {
        if (! $this->registry->hasProvider($provider)) {
            throw new \InvalidArgumentException("Unknown provider [{$provider}].");
        }

        if (! $this->canDiscoverLive($provider)) {
            throw new \InvalidArgumentException("Provider [{$provider}] does not support live model discovery.");
        }

        try {
            $models = $this->fetchLiveModels($provider);
            if ($models === []) {
                throw new \RuntimeException('Provider returned no models.');
            }

            return $this->cache->putSuccess($provider, $models, 'provider_live', self::LIVE_TTL_SECONDS);
        } catch (\Throwable $e) {
            $this->cache->putError($provider, 'provider_live', $e->getMessage());

            throw $e;
        }
    }

    public function canDiscoverLive(string $provider): bool
    {
        return match ($this->registry->driver($provider)) {
            'codex',
            'amazon-bedrock',
            'google-vertex',
            'unsupported',
            'external-process' => false,
            default => $this->endpoint($provider) !== '',
        };
    }

    public function discoveryEndpoint(string $provider): string
    {
        return $this->endpoint($provider);
    }

    /**
     * @return list<ModelDiscoveryResult>
     */
    public function cachedProviders(): array
    {
        return $this->cache->all();
    }

    /**
     * @return list<DiscoveredModel>
     */
    private function fetchLiveModels(string $provider): array
    {
        $driver = $this->registry->driver($provider);

        return match ($driver) {
            'anthropic', 'anthropic-compatible' => $this->fetchAnthropic($provider),
            'gemini' => $this->fetchGemini($provider),
            'openrouter' => $this->fetchOpenRouter($provider),
            default => $this->fetchOpenAiCompatible($provider),
        };
    }

    /**
     * @return list<DiscoveredModel>
     */
    private function fetchOpenAiCompatible(string $provider): array
    {
        $payload = $this->getJson($this->endpoint($provider), $this->authHeaders($provider));
        $items = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $models = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $models[] = new DiscoveredModel(
                id: $id,
                displayName: trim((string) ($item['name'] ?? $item['display_name'] ?? $id)),
                contextWindow: max(0, (int) ($item['context_window'] ?? $item['context_length'] ?? $item['input_token_limit'] ?? 0)),
                maxOutput: max(0, (int) ($item['max_output'] ?? $item['max_completion_tokens'] ?? $item['output_token_limit'] ?? 0)),
                status: is_string($item['status'] ?? null) ? $item['status'] : null,
            );
        }

        return $this->dedupe($models);
    }

    /**
     * @return list<DiscoveredModel>
     */
    private function fetchAnthropic(string $provider): array
    {
        $payload = $this->getJson($this->endpoint($provider), $this->authHeaders($provider));
        $items = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $models = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $models[] = new DiscoveredModel(
                id: $id,
                displayName: trim((string) ($item['display_name'] ?? $id)),
                inputModalities: ['text', 'image', 'pdf'],
                outputModalities: ['text'],
            );
        }

        return $this->dedupe($models);
    }

    /**
     * @return list<DiscoveredModel>
     */
    private function fetchGemini(string $provider): array
    {
        $payload = $this->getJson($this->endpoint($provider), $this->authHeaders($provider));
        $items = is_array($payload['models'] ?? null) ? $payload['models'] : [];

        $models = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $id = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
            if ($id === '') {
                continue;
            }

            $methods = is_array($item['supportedGenerationMethods'] ?? null) ? $item['supportedGenerationMethods'] : [];
            if ($methods !== [] && ! in_array('generateContent', $methods, true)) {
                continue;
            }

            $models[] = new DiscoveredModel(
                id: $id,
                displayName: trim((string) ($item['displayName'] ?? $item['display_name'] ?? $id)),
                contextWindow: max(0, (int) ($item['inputTokenLimit'] ?? 0)),
                maxOutput: max(0, (int) ($item['outputTokenLimit'] ?? 0)),
                inputModalities: ['text'],
                outputModalities: ['text'],
            );
        }

        return $this->dedupe($models);
    }

    /**
     * @return list<DiscoveredModel>
     */
    private function fetchOpenRouter(string $provider): array
    {
        $payload = $this->getJson($this->endpoint($provider), $this->authHeaders($provider));
        $items = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $models = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $pricing = is_array($item['pricing'] ?? null) ? $item['pricing'] : [];
            $architecture = is_array($item['architecture'] ?? null) ? $item['architecture'] : [];
            $inputModalities = $this->modalities($architecture['input_modalities'] ?? []);
            $outputModalities = $this->modalities($architecture['output_modalities'] ?? []);

            $models[] = new DiscoveredModel(
                id: $id,
                displayName: trim((string) ($item['name'] ?? $id)),
                contextWindow: max(0, (int) ($item['context_length'] ?? 0)),
                maxOutput: max(0, (int) ($item['max_completion_tokens'] ?? 0)),
                inputPricePerMillion: $this->perMillion($pricing['prompt'] ?? null),
                outputPricePerMillion: $this->perMillion($pricing['completion'] ?? null),
                inputModalities: $inputModalities,
                outputModalities: $outputModalities,
            );
        }

        return $this->dedupe($models);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function getJson(string $url, array $headers): array
    {
        $headerLines = ['Accept: application/json', 'User-Agent: kosmokrator/1.0'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = $this->httpStatus($http_response_header);
        if ($body === false || $body === '') {
            throw new \RuntimeException("GET {$url} returned an empty response.");
        }

        if ($status !== null && ($status < 200 || $status >= 300)) {
            throw new \RuntimeException("GET {$url} failed with HTTP {$status}: ".substr($body, 0, 500));
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("GET {$url} returned invalid JSON.");
        }

        return $decoded;
    }

    private function endpoint(string $provider): string
    {
        $base = rtrim((string) ($this->config->get("prism.providers.{$provider}.url") ?: $this->registry->url($provider)), '/');
        if ($base === '') {
            return '';
        }

        if ($this->registry->driver($provider) === 'gemini') {
            $url = $base.'/models';
            $key = $this->apiKey($provider);

            return $key !== '' ? $url.'?key='.rawurlencode($key) : $url;
        }

        return $base.'/models';
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $provider): array
    {
        $driver = $this->registry->driver($provider);
        $key = $this->apiKey($provider);
        if ($key === '' || $this->registry->authMode($provider) === 'none' || $driver === 'gemini') {
            return $driver === 'anthropic' || $driver === 'anthropic-compatible'
                ? ['anthropic-version' => '2023-06-01']
                : [];
        }

        if ($driver === 'anthropic' || $driver === 'anthropic-compatible') {
            return [
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ];
        }

        return ['Authorization' => 'Bearer '.$key];
    }

    private function apiKey(string $provider): string
    {
        $configKey = (string) ($this->config->get("prism.providers.{$provider}.api_key") ?? '');
        if ($configKey !== '' && ! str_starts_with($configKey, '${')) {
            return $configKey;
        }

        return (string) ($this->settings->get('global', "provider.{$provider}.api_key") ?? '');
    }

    /**
     * @param  list<string>  $headers
     */
    private function httpStatus(array $headers): ?int
    {
        $line = $headers[0] ?? null;
        if (! is_string($line) || ! preg_match('/HTTP\/\S+\s+(\d{3})/', $line, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param  list<DiscoveredModel>  $models
     * @return list<DiscoveredModel>
     */
    private function dedupe(array $models): array
    {
        $seen = [];
        $deduped = [];

        foreach ($models as $model) {
            $key = strtolower($model->id);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $model;
        }

        usort($deduped, static fn (DiscoveredModel $a, DiscoveredModel $b): int => strcasecmp($a->id, $b->id));

        return $deduped;
    }

    /**
     * @return list<string>
     */
    private function modalities(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }

    private function perMillion(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value * 1_000_000;
    }
}

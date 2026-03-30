<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismServerException;
use Psr\Log\LoggerInterface;

class RetryableLlmClient implements LlmClientInterface
{
    public function __construct(
        private readonly LlmClientInterface $inner,
        private readonly LoggerInterface $log,
        private readonly int $maxAttempts = 3,
    ) {}

    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->inner->chat($messages, $tools, $cancellation);
            } catch (\Throwable $e) {
                $attempt++;

                if (! $this->isRetryable($e) || $attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $delay = $this->calculateDelay($e, $attempt);
                $this->log->warning("LLM request failed (attempt {$attempt}/{$this->maxAttempts}), retrying in {$delay}s", [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                sleep($delay);
            }
        }
    }

    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof PrismRateLimitedException
            || $e instanceof PrismProviderOverloadedException
            || $e instanceof PrismServerException) {
            return true;
        }

        // Amp network errors
        if ($e instanceof \Amp\Http\Client\HttpException) {
            return true;
        }

        // AsyncLlmClient HTTP errors with retryable status codes
        if ($e instanceof \RuntimeException && preg_match('/API error \((429|5\d{2})\)/', $e->getMessage())) {
            return true;
        }

        return false;
    }

    private function calculateDelay(\Throwable $e, int $attempt): int
    {
        // Honor rate limit hint from Prism
        if ($e instanceof PrismRateLimitedException && $e->retryAfter !== null) {
            return min($e->retryAfter, 60);
        }

        // Exponential backoff with jitter: ~1s, ~2s, ~4s (capped at 30s)
        $base = min((int) pow(2, $attempt - 1), 30);

        return $base + random_int(0, max(1, (int) ($base * 0.5)));
    }

    public function setSystemPrompt(string $prompt): void
    {
        $this->inner->setSystemPrompt($prompt);
    }

    public function getProvider(): string
    {
        return $this->inner->getProvider();
    }

    public function setProvider(string $provider): void
    {
        $this->inner->setProvider($provider);
    }

    public function getModel(): string
    {
        return $this->inner->getModel();
    }

    public function setModel(string $model): void
    {
        $this->inner->setModel($model);
    }

    public function getTemperature(): int|float|null
    {
        return $this->inner->getTemperature();
    }

    public function setTemperature(int|float|null $temperature): void
    {
        $this->inner->setTemperature($temperature);
    }

    public function getMaxTokens(): ?int
    {
        return $this->inner->getMaxTokens();
    }

    public function setMaxTokens(?int $maxTokens): void
    {
        $this->inner->setMaxTokens($maxTokens);
    }

    /**
     * Forward non-interface methods (e.g. setApiKey, setBaseUrl) to the inner client.
     * This preserves method_exists() compatibility for dynamic dispatch in settings.
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->{$method}(...$args);
    }

    public function __isset(string $name): bool
    {
        return isset($this->inner->{$name});
    }
}

<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

use Amp\Cancellation;
use Amp\Http\Client\HttpException;
use OpenCompany\PrismRelay\Errors\ProviderError;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismServerException;
use Psr\Log\LoggerInterface;

/**
 * Retrying decorator around LlmClientInterface with exponential backoff and jitter.
 *
 * Wraps any LlmClientInterface (AsyncLlmClient, PrismService) and automatically
 * retries on rate-limit (429), server errors (5xx), and network failures.
 * Honors Retry-After headers from providers and PrismRateLimitedException hints.
 */
class RetryableLlmClient implements LlmClientInterface
{
    /**
     * @param LlmClientInterface $inner       The underlying client to delegate calls to
     * @param LoggerInterface    $log         Logger for retry warnings
     * @param int                $maxAttempts Maximum retry attempts (0 = unlimited)
     * @param \Closure|null      $onRetry     Optional callback invoked as (int $attempt, float $delay, string $error) on each retry
     */
    public function __construct(
        private readonly LlmClientInterface $inner,
        private readonly LoggerInterface $log,
        private int $maxAttempts = 0,
        private ?\Closure $onRetry = null,
    ) {}

    public function setOnRetry(?\Closure $onRetry): void
    {
        $this->onRetry = $onRetry;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        $this->maxAttempts = $maxAttempts;
    }

    /**
     * Send a chat request with automatic retry on transient failures.
     *
     * @param  Message[]    $messages     Conversation history
     * @param  Tool[]       $tools        Available tools for function calling
     * @param  Cancellation $cancellation Optional Amp cancellation token
     */
    public function chat(array $messages, array $tools = [], ?Cancellation $cancellation = null): LlmResponse
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->inner->chat($messages, $tools, $cancellation);
            } catch (\Throwable $e) {
                $attempt++;

                if (! $this->isRetryable($e) || ($this->maxAttempts > 0 && $attempt >= $this->maxAttempts)) {
                    throw $e;
                }

                $delay = $this->calculateDelay($e, $attempt);
                $this->log->warning("LLM request failed (attempt {$attempt}), retrying in {$delay}s", [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                if ($this->onRetry !== null) {
                    try {
                        ($this->onRetry)($attempt, $delay, $e->getMessage());
                    } catch (\Throwable) {
                    }
                }

                $this->smartDelay($delay, $cancellation);
            }
        }
    }

    /**
     * Determine if an exception represents a transient failure worth retrying.
     *
     * Recognizes Prism rate-limit/server exceptions, RetryableHttpException, ProviderError,
     * Amp HttpException (network-level), and legacy RuntimeException with retryable status codes.
     */
    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof PrismRateLimitedException
            || $e instanceof PrismProviderOverloadedException
            || $e instanceof PrismServerException) {
            return true;
        }

        if ($e instanceof RetryableHttpException) {
            return true;
        }

        if ($e instanceof ProviderError) {
            return $e->retryable;
        }

        // Amp network errors
        if ($e instanceof HttpException) {
            return true;
        }

        // Legacy: AsyncLlmClient HTTP errors with retryable status codes
        if ($e instanceof \RuntimeException && preg_match('/API error \((429|5\d{2})\)/', $e->getMessage())) {
            return true;
        }

        return false;
    }

    /**
     * Compute the delay before the next retry attempt.
     *
     * Honors provider-supplied Retry-After hints first, then falls back to
     * exponential backoff with ±30% jitter (2s, 4s, 8s, 16s, 32s, capped at 60s).
     */
    private function calculateDelay(\Throwable $e, int $attempt): float
    {
        // Honor rate limit hint from Prism
        if ($e instanceof PrismRateLimitedException && $e->retryAfter !== null) {
            return min((float) $e->retryAfter, 60.0);
        }

        // Honor retry-after from HTTP response headers
        if ($e instanceof RetryableHttpException && $e->retryAfterSeconds !== null) {
            return min($e->retryAfterSeconds, 60.0);
        }

        if ($e instanceof ProviderError && $e->retryAfterSeconds !== null) {
            return min((float) $e->retryAfterSeconds, 60.0);
        }

        // Exponential backoff with jitter: ~2s, ~4s, ~8s, ~16s, ~32s, ~60s, ~60s, ...
        $base = min((int) pow(2, $attempt), 60);

        return (float) ($base + random_int(0, max(1, (int) ($base * 0.3))));
    }

    /**
     * Non-blocking delay when inside a Revolt fiber (TUI mode),
     * blocking sleep as fallback (ANSI mode).
     */
    private function smartDelay(float $seconds, ?Cancellation $cancellation): void
    {
        if (\Fiber::getCurrent() !== null) {
            \Amp\delay($seconds, cancellation: $cancellation);
        } else {
            $cancellation?->throwIfRequested();
            sleep((int) ceil($seconds));
            $cancellation?->throwIfRequested();
        }
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

    /** Get the underlying client for direct access when needed (e.g. stream()). */
    public function inner(): LlmClientInterface
    {
        return $this->inner;
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

<?php

declare(strict_types=1);

$argv = $_SERVER['argv'] ?? [];

if (count($argv) < 2) {
    fwrite(STDERR, "Missing payload\n");
    exit(1);
}

$rootDir = dirname(__DIR__, 2);
require_once $rootDir.'/vendor/autoload.php';

use Kosmokrator\Kernel;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

try {
    $payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);

    if (! isset($payload['system_prompt'], $payload['user_prompt'])) {
        throw new RuntimeException('Missing system_prompt or user_prompt');
    }

    $kernel = new Kernel($rootDir);
    $kernel->boot();

    // Use AsyncLlmClient (raw HTTP) — it works for all OpenAI-compatible providers
    // including z/GLM. LlmClientInterface resolves to PrismService which 404s on GLM.
    /** @var LlmClientInterface $llm */
    $llm = $kernel->getContainer()->make(AsyncLlmClient::class);
    $response = $llm->chat([
        new SystemMessage((string) $payload['system_prompt']),
        new UserMessage((string) $payload['user_prompt']),
    ]);

    echo trim($response->text);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

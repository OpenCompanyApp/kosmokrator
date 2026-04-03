<?php

/**
 * Background worker for the completion sound system.
 *
 * Invoked as a detached CLI process by CompletionSound::play().
 * Bootstraps the application container, calls the LLM to compose music,
 * validates the script, retries on failure, and spawns Python playback.
 *
 * This process runs completely independently from the main agent REPL.
 * It never blocks user interaction.
 *
 * Usage: php compose_worker.php <base64_json_payload>
 */

declare(strict_types=1);

// Silently exit if no payload
if ($argc < 2) {
    error_log('[CompletionSound Worker] No payload argument');
    exit(1);
}

try {
    $payload = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('[CompletionSound Worker] Payload decode failed: '.$e->getMessage());
    exit(1);
}

$required = ['message', 'round_count', 'project_name', 'soundfont', 'instrument', 'max_retries', 'max_duration'];
foreach ($required as $key) {
    if (! isset($payload[$key])) {
        error_log("[CompletionSound Worker] Missing required key: {$key}");
        exit(1);
    }
}

// Bootstrap the application
$rootDir = dirname(__DIR__, 2);
require_once $rootDir.'/vendor/autoload.php';

use Kosmokrator\Audio\CompletionSound;
use Kosmokrator\Kernel;
use Kosmokrator\LLM\LlmClientInterface;
use Psr\Log\LoggerInterface;

// Create a simple file logger for the background process before any provider work
$logDir = $payload['log_dir'] ?? $rootDir.'/storage/logs';
$logFile = $logDir.'/audio.log';
@mkdir(dirname($logFile), 0755, true);

$log = new class($logFile) implements LoggerInterface
{
    public function __construct(private string $file) {}

    private function write(string $level, string $message, array $context = []): void
    {
        $ts = date('Y-m-d H:i:s');
        $ctx = $context !== [] ? ' '.json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        @file_put_contents($this->file, "[{$ts}] {$level}: {$message}{$ctx}\n", FILE_APPEND);
    }

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->write('EMERGENCY', (string) $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->write('ALERT', (string) $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->write('CRITICAL', (string) $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->write('ERROR', (string) $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->write('WARNING', (string) $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->write('NOTICE', (string) $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->write('INFO', (string) $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->write('DEBUG', (string) $message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->write(strtoupper($level), (string) $message, $context);
    }
};

try {
    $log->info('Completion sound worker booting', [
        'timeout_seconds' => (int) ($payload['llm_timeout'] ?? 60),
    ]);

    $kernel = new Kernel($rootDir);
    $kernel->boot();
    $container = $kernel->getContainer();

    $log->info('Completion sound worker booted');

    /** @var LlmClientInterface $llm */
    $llm = $container->make(LlmClientInterface::class);

    // Apply audio model/provider overrides from config
    $appConfig = $container->make('config');
    $audioProvider = $appConfig->get('kosmokrator.agent.audio_provider');
    $audioModel = $appConfig->get('kosmokrator.agent.audio_model');

    if ($audioProvider !== null && $audioProvider !== '') {
        $llm->setProvider($audioProvider);
    }
    if ($audioModel !== null && $audioModel !== '') {
        $llm->setModel($audioModel);
    }

    $sound = new CompletionSound(
        llm: $llm,
        log: $log,
        sessionId: 'background-worker',
        enabled: true,
        soundfont: $payload['soundfont'],
        maxDuration: (int) $payload['max_duration'],
        maxRetries: (int) $payload['max_retries'],
        llmTimeoutSeconds: (int) ($payload['llm_timeout'] ?? 60),
    );

    // Override the instrument with the one assigned to this session
    // via reflection since it's determined by session ID in the constructor
    $ref = new ReflectionProperty($sound, 'instrument');
    $ref->setValue($sound, (int) $payload['instrument']);

    $log->info('Worker starting composition', [
        'instrument' => $payload['instrument'],
        'message_preview' => mb_substr($payload['message'], 0, 100),
    ]);

    $sound->composeAndPlay(
        $payload['message'],
        (int) $payload['round_count'],
        $payload['project_name'],
    );

    $log->info('Worker finished');
} catch (Throwable $e) {
    $msg = '[CompletionSound Worker] FAILED: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
    $log->error('Worker failed', ['message' => $msg]);
    error_log($msg);
    @file_put_contents(
        sys_get_temp_dir().'/kosmokrator_audio_error.log',
        date('c').' '.$msg."\n",
        FILE_APPEND,
    );
    exit(1);
}

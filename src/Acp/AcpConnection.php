<?php

declare(strict_types=1);

namespace Kosmokrator\Acp;

/**
 * Newline-delimited JSON-RPC 2.0 connection for ACP stdio.
 *
 * Stdout is reserved for protocol frames. Human logs must go to stderr.
 */
final class AcpConnection
{
    private int $nextId = 1;

    /** @var array<string, \Closure(array<string, mixed>): void> */
    private array $notificationHandlers = [];

    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public function __construct(
        private $input,
        private $output,
    ) {}

    public function onNotification(string $method, \Closure $handler): void
    {
        $this->notificationHandlers[$method] = $handler;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readMessage(): ?array
    {
        $line = fgets($this->input);
        if ($line === false) {
            return null;
        }

        $line = trim($line);
        if ($line === '') {
            return $this->readMessage();
        }

        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            throw JsonRpcException::parseError('Invalid JSON-RPC frame');
        }

        return $decoded;
    }

    public function sendResult(int|string|null $id, mixed $result): void
    {
        if ($id === null) {
            return;
        }

        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    public function sendError(int|string|null $id, \Throwable $error): void
    {
        if ($id === null) {
            return;
        }

        $code = $error instanceof JsonRpcException ? $error->jsonRpcCode : -32603;
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $error->getMessage(),
            ],
        ];

        if ($error instanceof JsonRpcException && $error->data !== null) {
            $payload['error']['data'] = $error->data;
        }

        $this->send($payload);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function notify(string $method, array $params = []): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = [], int $timeoutSeconds = 120): array
    {
        $id = $this->nextId++;
        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        $started = time();
        while (true) {
            if ((time() - $started) > $timeoutSeconds) {
                throw JsonRpcException::internalError("Timed out waiting for ACP response to {$method}");
            }

            $message = $this->readMessage();
            if ($message === null) {
                throw JsonRpcException::internalError('ACP client disconnected');
            }

            if (array_key_exists('id', $message) && ($message['id'] ?? null) === $id) {
                if (isset($message['error']) && is_array($message['error'])) {
                    throw JsonRpcException::internalError((string) ($message['error']['message'] ?? 'ACP client request failed'));
                }

                $result = $message['result'] ?? [];

                return is_array($result) ? $result : [];
            }

            if (isset($message['method']) && is_string($message['method'])) {
                $this->dispatchNotification($message);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function dispatchNotification(array $message): void
    {
        $method = (string) ($message['method'] ?? '');
        $params = $message['params'] ?? [];
        if (! is_array($params)) {
            $params = [];
        }

        if (isset($this->notificationHandlers[$method])) {
            ($this->notificationHandlers[$method])($params);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw JsonRpcException::internalError('Failed to encode JSON-RPC frame');
        }

        fwrite($this->output, $json."\n");
        fflush($this->output);
    }
}

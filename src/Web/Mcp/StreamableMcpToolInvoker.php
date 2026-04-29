<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Mcp;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

final class StreamableMcpToolInvoker implements McpToolInvokerInterface
{
    private readonly HttpClient $httpClient;

    public function __construct(?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClientBuilder::buildDefault();
    }

    public function call(string $remoteUrl, string $toolName, array $arguments, array $headers = []): array|string
    {
        [$sessionId] = $this->send(
            remoteUrl: $remoteUrl,
            payload: [
                'jsonrpc' => '2.0',
                'id' => 0,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => (object) [],
                    'clientInfo' => [
                        'name' => 'kosmokrator',
                        'version' => '0.1',
                    ],
                ],
            ],
            headers: $headers,
        );

        $this->send(
            remoteUrl: $remoteUrl,
            payload: [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ],
            headers: $headers,
            sessionId: $sessionId,
            expectPayload: false,
        );

        [, $payload] = $this->send(
            remoteUrl: $remoteUrl,
            payload: [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $toolName,
                    'arguments' => $arguments,
                ],
            ],
            headers: $headers,
            sessionId: $sessionId,
        );

        if (! is_array($payload)) {
            throw new \RuntimeException('Remote MCP tool returned an invalid payload.');
        }

        if (($payload['isError'] ?? false) === true) {
            $error = $this->extractToolText($payload);
            throw new \RuntimeException($error !== '' ? $error : 'Remote MCP tool call failed.');
        }

        $text = $this->extractToolText($payload);

        return $this->decodeEmbeddedPayload($text);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function send(
        string $remoteUrl,
        array $payload,
        array $headers,
        string $sessionId = '',
        bool $expectPayload = true,
    ): array {
        $request = new Request($remoteUrl, 'POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json, text/event-stream');
        foreach ($headers as $key => $value) {
            $request->setHeader($key, $value);
        }
        if ($sessionId !== '') {
            $request->setHeader('mcp-session-id', $sessionId);
        }
        $request->setTransferTimeout(30);
        $request->setInactivityTimeout(30);
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR));

        $response = $this->httpClient->request($request);
        $status = $response->getStatus();

        if ($status < 200 || $status >= 300) {
            $body = $response->getBody()->buffer();
            throw new \RuntimeException("Remote MCP request failed ({$status}).");
        }

        $resolvedSessionId = (string) ($response->getHeader('mcp-session-id') ?? $sessionId);
        if (! $expectPayload) {
            return [$resolvedSessionId, null];
        }

        $contentType = strtolower((string) ($response->getHeader('content-type') ?? ''));
        $decoded = str_contains($contentType, 'text/event-stream')
            ? $this->decodeSsePayload($response->getBody())
            : json_decode($response->getBody()->buffer(), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Remote MCP response was not valid JSON.');
        }

        $result = $decoded['result'] ?? $decoded;
        if (! is_array($result)) {
            throw new \RuntimeException('Remote MCP result payload was invalid.');
        }

        return [$resolvedSessionId, $result];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeSsePayload(ReadableStream $stream): ?array
    {
        $buffer = '';

        while (($chunk = $stream->read()) !== null) {
            $buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);

            while (($separatorPos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $separatorPos);
                $buffer = substr($buffer, $separatorPos + 2);

                $decoded = $this->decodeSseEvent($event);
                if ($decoded !== null) {
                    return $decoded;
                }
            }
        }

        return $this->decodeSseEvent($buffer);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeSseEvent(string $event): ?array
    {
        $dataLines = [];

        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        $payload = implode("\n", $dataLines);
        if ($payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractToolText(array $payload): string
    {
        $content = $payload['content'] ?? null;
        if (! is_array($content)) {
            return '';
        }

        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                return $item['text'];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>|list<mixed>|string
     */
    private function decodeEmbeddedPayload(string $text): array|string
    {
        $value = $text;

        for ($i = 0; $i < 3; $i++) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                break;
            }

            if (is_array($decoded)) {
                return $decoded;
            }

            if (! is_string($decoded)) {
                return $value;
            }

            $value = $decoded;
        }

        return $value;
    }
}

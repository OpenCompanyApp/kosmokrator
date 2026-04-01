<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\Codex;

use Illuminate\Config\Repository;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;

final class CodexAuthFlow
{
    public function __construct(
        private readonly CodexOAuthService $oauth,
        private readonly CodexTokenStore $tokens,
        private readonly Repository $config,
    ) {}

    public function current(): ?CodexToken
    {
        return $this->tokens->current();
    }

    public function logout(): void
    {
        $this->tokens->clear();
    }

    /**
     * @param  callable(string): void|null  $notify
     */
    public function browserLogin(?callable $notify = null): CodexToken
    {
        $pkce = $this->oauth->generatePkce();
        $state = bin2hex(random_bytes(16));
        $port = (int) $this->config->get('kosmokrator.codex.oauth_port', 9876);
        $redirectUri = "http://127.0.0.1:{$port}/auth/callback";
        // Build URL ourselves — the library's buildAuthorizationUrl() includes
        // codex_cli_simplified_flow=true which makes OpenAI show a code on-screen
        // instead of redirecting back to our callback server.
        $authUrl = CodexOAuthService::ISSUER.'/oauth/authorize?'.http_build_query([
            'client_id' => CodexOAuthService::CLIENT_ID,
            'scope' => CodexOAuthService::SCOPES,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'state' => $state,
        ]);

        $this->emit($notify, 'Opening browser for ChatGPT login...');
        $this->emit($notify, "If it does not open, visit: {$authUrl}");

        $this->openBrowser($authUrl);

        $code = $this->waitForCallback($port, $state);
        if ($code === null) {
            throw new \RuntimeException('Authentication failed or timed out.');
        }

        return $this->oauth->storeTokens($this->oauth->exchangeCode($code, $pkce['verifier'], $redirectUri));
    }

    /**
     * @param  callable(string): void|null  $notify
     */
    public function deviceLogin(?callable $notify = null, int $maxWaitSeconds = 300): CodexToken
    {
        $device = $this->oauth->initiateDeviceAuth();

        $this->emit($notify, 'Visit https://auth.openai.com/codex/device');
        $this->emit($notify, 'Code: '.$device['user_code']);

        $interval = max(1, (int) ($device['interval'] ?? 5)) + 3;
        $maxAttempts = (int) ceil($maxWaitSeconds / $interval);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            sleep($interval);

            $tokens = $this->oauth->pollDeviceAuth($device['device_auth_id'], $device['user_code']);
            if ($tokens === null) {
                continue;
            }

            return $this->oauth->storeTokens($tokens);
        }

        throw new \RuntimeException('Timed out waiting for device authorization.');
    }

    /**
     * @param  callable(string): void|null  $notify
     */
    private function emit(?callable $notify, string $message): void
    {
        if ($notify !== null) {
            $notify($message);
        }
    }

    private function openBrowser(string $url): void
    {
        $command = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        @exec($command.' '.escapeshellarg($url).' >/dev/null 2>&1 &');
    }

    private function waitForCallback(int $port, string $expectedState): ?string
    {
        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if ($server === false) {
            throw new \RuntimeException("Could not start callback server on port {$port}: {$errstr}");
        }

        stream_set_timeout($server, 120);
        $client = @stream_socket_accept($server, 120);
        fclose($server);

        if ($client === false) {
            return null;
        }

        $request = (string) fread($client, 8192);
        preg_match('/GET\s+([^\s]+)/', $request, $matches);
        $path = $matches[1] ?? '';

        parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $params);

        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;
        $error = $params['error'] ?? null;

        if ($state !== $expectedState || $code === null) {
            $message = $error !== null ? "Error: {$error}" : 'Invalid callback payload.';
            fwrite($client, "HTTP/1.1 400 Bad Request\r\nContent-Type: text/html\r\n\r\n<html><body><h2>Authentication failed</h2><p>{$message}</p></body></html>");
            fclose($client);

            return null;
        }

        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n<html><body><h2>Authentication successful</h2><p>You can return to KosmoKrator.</p></body></html>");
        fclose($client);

        return (string) $code;
    }
}

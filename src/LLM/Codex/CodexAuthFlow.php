<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\Codex;

use Illuminate\Config\Repository;
use Kosmokrator\Exception\AuthenticationException;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;

/**
 * Handles OpenAI Codex OAuth authentication flows (browser-based and device-code).
 *
 * Orchestrates the PKCE-based OAuth dance for ChatGPT subscription access via the Codex API.
 * Supports two flows: browser login (opens a local callback server) and device login
 * (shows a code for the user to enter at auth.openai.com). Persists tokens via
 * SettingsCodexTokenStore. Used by the settings UI and Codex provider in RelayProviderRegistrar.
 */
final class CodexAuthFlow
{
    public function __construct(
        private readonly CodexOAuthService $oauth,
        private readonly CodexTokenStore $tokens,
        private readonly Repository $config,
    ) {}

    /**
     * @return CodexToken|null The currently stored token, or null if not authenticated
     */
    public function current(): ?CodexToken
    {
        return $this->tokens->current();
    }

    /** Remove stored tokens, effectively logging out. */
    public function logout(): void
    {
        $this->tokens->clear();
    }

    /**
     * Authenticate via browser-based OAuth with PKCE and a local callback server.
     *
     * Opens the user's browser to the OpenAI authorize endpoint, starts a temporary
     * HTTP server on localhost to receive the callback, and exchanges the auth code
     * for access/refresh tokens.
     *
     * @param  callable(string): void|null  $notify  Optional callback for TUI status messages
     * @return CodexToken The newly obtained and persisted token
     *
     * @throws AuthenticationException If authentication fails or times out
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
            throw new AuthenticationException('Authentication failed or timed out.');
        }

        return $this->oauth->storeTokens($this->oauth->exchangeCode($code, $pkce['verifier'], $redirectUri));
    }

    /**
     * Authenticate via device-code flow for headless/remote environments.
     *
     * Initiates a device auth request, displays a user code and URL, then polls
     * the token endpoint until the user completes authorization or the timeout expires.
     *
     * @param  callable(string): void|null  $notify  Optional callback for TUI status messages
     * @param  int  $maxWaitSeconds  Maximum time to wait for user authorization (default 300s)
     * @return CodexToken The newly obtained and persisted token
     *
     * @throws AuthenticationException If authorization times out
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

        throw new AuthenticationException('Timed out waiting for device authorization.');
    }

    /** Emit a message to the optional notify callback, used for TUI status updates. */
    private function emit(?callable $notify, string $message): void
    {
        if ($notify !== null) {
            $notify($message);
        }
    }

    /** Open the given URL in the user's default browser (macOS: open, Linux: xdg-open). */
    private function openBrowser(string $url): void
    {
        $command = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        @exec($command.' '.escapeshellarg($url).' >/dev/null 2>&1 &');
    }

    /**
     * Start a temporary TCP server to receive the OAuth callback and extract the auth code.
     *
     * Listens for a single connection on the given port, validates the state parameter,
     * and returns the authorization code. Sends a simple HTML success/failure page back.
     *
     * @param  int  $port  Localhost port for the callback server
     * @param  string  $expectedState  CSRF state value to validate against
     * @return string|null The authorization code, or null if the callback was invalid
     */
    private function waitForCallback(int $port, string $expectedState): ?string
    {
        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if ($server === false) {
            throw new AuthenticationException("Could not start callback server on port {$port}: {$errstr}");
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

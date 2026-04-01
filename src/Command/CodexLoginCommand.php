<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use OpenCompany\PrismCodex\CodexOAuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'codex:login', description: 'Authenticate with ChatGPT for the Codex provider')]
final class CodexLoginCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('device', null, InputOption::VALUE_NONE, 'Use the device authorization flow');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $oauth = $this->container->make(CodexOAuthService::class);

        return $input->getOption('device')
            ? $this->deviceFlow($oauth, $output)
            : $this->browserFlow($oauth, $output);
    }

    private function browserFlow(CodexOAuthService $oauth, OutputInterface $output): int
    {
        $pkce = $oauth->generatePkce();
        $state = bin2hex(random_bytes(16));
        $port = (int) $this->container->make('config')->get('kosmokrator.codex.oauth_port', 9876);
        $redirectUri = "http://127.0.0.1:{$port}/auth/callback";
        $authUrl = $oauth->buildAuthorizationUrl($pkce['challenge'], $state, $redirectUri);

        $output->writeln('Opening browser for ChatGPT login...');
        $output->writeln("If it does not open, visit:\n{$authUrl}\n");
        $this->openBrowser($authUrl);

        $code = $this->waitForCallback($port, $state, $output);
        if ($code === null) {
            $output->writeln('<error>Authentication failed or timed out.</error>');

            return Command::FAILURE;
        }

        try {
            $token = $oauth->storeTokens($oauth->exchangeCode($code, $pkce['verifier'], $redirectUri));
        } catch (\Throwable $e) {
            $output->writeln('<error>Token exchange failed: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $this->renderTokenSummary($output, $token->email, $token->accountId);

        return Command::SUCCESS;
    }

    private function deviceFlow(CodexOAuthService $oauth, OutputInterface $output): int
    {
        try {
            $device = $oauth->initiateDeviceAuth();
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to initiate device auth: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('Visit: https://auth.openai.com/codex/device');
        $output->writeln('Code: '.$device['user_code']);
        $output->writeln('');

        $interval = max(1, (int) ($device['interval'] ?? 5)) + 3;
        $maxAttempts = (int) ceil(300 / $interval);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            sleep($interval);

            try {
                $tokens = $oauth->pollDeviceAuth($device['device_auth_id'], $device['user_code']);
            } catch (\Throwable $e) {
                $output->writeln("\n<error>Device auth failed: {$e->getMessage()}</error>");

                return Command::FAILURE;
            }

            if ($tokens === null) {
                $output->write('.');

                continue;
            }

            $token = $oauth->storeTokens($tokens);
            $output->writeln('');
            $this->renderTokenSummary($output, $token->email, $token->accountId);

            return Command::SUCCESS;
        }

        $output->writeln("\n<error>Timed out waiting for authorization.</error>");

        return Command::FAILURE;
    }

    private function renderTokenSummary(OutputInterface $output, ?string $email, ?string $accountId): void
    {
        $rows = [['Status', 'Authenticated']];
        if ($email !== null) {
            $rows[] = ['Account', $email];
        }
        if ($accountId !== null) {
            $rows[] = ['Account ID', $accountId];
        }

        $output->writeln('');
        (new Table($output))
            ->setHeaders(['Property', 'Value'])
            ->setRows($rows)
            ->render();
    }

    private function openBrowser(string $url): void
    {
        $command = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        @exec($command.' '.escapeshellarg($url).' >/dev/null 2>&1 &');
    }

    private function waitForCallback(int $port, string $expectedState, OutputInterface $output): ?string
    {
        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if ($server === false) {
            $output->writeln("<error>Could not start callback server on port {$port}: {$errstr}</error>");

            return null;
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

        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n<html><body><h2>Authentication successful</h2><p>You can return to the terminal.</p></body></html>");
        fclose($client);

        return (string) $code;
    }
}

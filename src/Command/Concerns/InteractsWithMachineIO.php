<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Concerns;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait InteractsWithMachineIO
{
    protected function writeJson(OutputInterface $output, mixed $data): void
    {
        $output->writeln($this->jsonEncode($data));
    }

    protected function jsonEncode(mixed $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to encode command JSON output: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function stdinJson(): array
    {
        $stdin = stream_get_contents(STDIN);
        if (! is_string($stdin) || trim($stdin) === '') {
            return [];
        }

        try {
            $decoded = json_decode($stdin, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid stdin JSON: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid stdin JSON: expected an object.');
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    protected function rawTokens(InputInterface $input): array
    {
        return $input instanceof ArgvInput ? $input->getRawTokens(true) : [];
    }

    protected function readStdinIfPiped(): ?string
    {
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            return null;
        }

        $stdin = stream_get_contents(STDIN);

        return is_string($stdin) && trim($stdin) !== '' ? $stdin : null;
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function rawFlag(array $tokens, string $name): bool
    {
        return in_array("--{$name}", $tokens, true);
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function rawOption(array $tokens, string $name): ?string
    {
        foreach ($tokens as $index => $token) {
            if (str_starts_with($token, "--{$name}=")) {
                return substr($token, strlen($name) + 3);
            }

            if ($token === "--{$name}" && isset($tokens[$index + 1]) && ! str_starts_with($tokens[$index + 1], '--')) {
                return $tokens[$index + 1];
            }
        }

        return null;
    }

    protected function boolOption(InputInterface $input, string $name, mixed $payloadValue = null): bool
    {
        if ($input->hasOption($name) && $input->getOption($name)) {
            return true;
        }

        return $payloadValue === true || $payloadValue === 'true' || $payloadValue === 'on';
    }
}

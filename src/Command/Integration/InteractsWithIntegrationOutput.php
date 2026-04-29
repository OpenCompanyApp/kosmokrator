<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait InteractsWithIntegrationOutput
{
    protected function writeJson(OutputInterface $output, mixed $data): void
    {
        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * @return list<string>
     */
    protected function rawTokens(InputInterface $input): array
    {
        if ($input instanceof ArgvInput) {
            return $input->getRawTokens(true);
        }

        return [];
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
}

<?php

declare(strict_types=1);

namespace Kosmokrator\Bootstrap;

/**
 * Renders bootstrap failures without exposing raw PHP stack traces by default.
 */
final class BootstrapErrorRenderer
{
    /**
     * @param  resource  $stream
     */
    public static function render(\Throwable $error, mixed $stream, bool $debug = false): int
    {
        $message = self::message($error);

        fwrite($stream, "\033[38;2;255;80;60mKosmo failed to start.\033[0m\n");
        fwrite($stream, "Reason: {$message}\n\n");
        fwrite($stream, "Try: kosmo settings:doctor --json\n");
        fwrite($stream, "     kosmo providers:status --json\n");
        fwrite($stream, "     kosmo smoke:startup --json\n");

        if ($debug) {
            fwrite($stream, "\nDebug details:\n");
            fwrite($stream, get_class($error).': '.$error->getMessage()."\n");
            fwrite($stream, $error->getTraceAsString()."\n");
        } else {
            fwrite($stream, "\nSet KOSMO_BOOT_DEBUG=1 to include debug details.\n");
        }

        return 1;
    }

    private static function message(\Throwable $error): string
    {
        $message = trim($error->getMessage());
        if ($message === '') {
            $message = get_class($error);
        }

        $message = preg_replace('/\nStack trace:.*$/s', '', $message) ?? $message;
        $message = preg_replace('/\n#\d+\s+.*$/m', '', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return trim($message);
    }
}

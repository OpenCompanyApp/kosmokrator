<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

/**
 * Sanitizes error messages before they are sent to the LLM.
 *
 * Strips internal details like file paths, HTTP response bodies, stack traces,
 * and other implementation specifics that should not leak into the LLM context.
 * Keeps the error category and a generic message so the model can reason about recovery.
 */
final class ErrorSanitizer
{
    /**
     * Sanitize an error message, stripping internal details.
     */
    public static function sanitize(string $message): string
    {
        // Strip absolute file paths (Unix and Windows)
        $message = preg_replace('#(/[\w.\-]+)+/([\w.\-]+\.(php|json|yaml|yml|xml|txt|md|env|lock|sqlite|db))#i', '$2', $message);
        $message = preg_replace('#[A-Z]:\\\\[\w.\\\\-]+\\\\([\w.\-]+\.(?:php|json|yaml|yml|xml|txt|md|env|lock|sqlite|db))#i', '$1', $message);

        // Strip /Users/xxx/ and /home/xxx/ style paths
        $message = preg_replace('#/Users/[^/\s]+#', '/***', $message);
        $message = preg_replace('#/home/[^/\s]+#', '/***', $message);

        // Strip stack traces (lines starting with #N or at file:line)
        $message = preg_replace('/\n#\d+\s+.*$/m', '', $message);
        $message = preg_replace('/\nat\s+[\w\\\\]+\(.*?:\d+\).*$/m', '', $message);
        $message = preg_replace('/\nStack trace:.*$/ms', '', $message);

        // Strip HTTP response bodies (JSON blobs after status codes)
        $message = preg_replace('/\b(400|401|403|404|429|500|502|503)\s+[^:]*:\s*\{.*?\}/s', '$1 error', $message);

        // Strip API keys and tokens in error messages
        $message = preg_replace('/\b(sk-[a-zA-Z0-9]{8,})\b/', '[REDACTED_KEY]', $message);
        $message = preg_replace('/\b(bearer\s+)[\w.\-]+/i', '$1[REDACTED]', $message);

        // Strip internal class references like Kosmokrator\Something\ClassName
        $message = preg_replace('/\\\\?Kosmokrator\\\\[\w\\\\]+/m', '[internal]', $message);

        // Strip Prism namespace references
        $message = preg_replace('/\\\\?Prism\\\\[\w\\\\]+/m', '[internal]', $message);

        // Trim whitespace artifacts
        $message = trim($message);

        return $message;
    }
}

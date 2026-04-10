<?php

declare(strict_types=1);

namespace Kosmokrator\Security;

/**
 * Deterministic scanner for prompt-injection markers in persistent context.
 *
 * This intentionally stays conservative and pattern-based. It is meant to
 * reject obviously hostile persisted content before it is re-injected into
 * future system prompts.
 */
final class PromptInjectionScanner
{
    /**
     * @return string[] Human-readable issue labels
     */
    public function scan(string $text): array
    {
        $issues = [];
        $normalized = mb_strtolower($text);

        if (preg_match('/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u', $text) === 1) {
            $issues[] = 'invisible_unicode';
        }

        $patterns = [
            'ignore_previous_instructions' => '/\bignore\s+(?:all\s+)?previous\s+instructions\b/i',
            'disregard_rules' => '/\bdisregard\s+(?:all\s+)?(?:previous\s+)?(?:instructions|rules)\b/i',
            'role_hijack' => '/\b(?:you\s+are\s+now|act\s+as\s+(?:the\s+)?system|pretend\s+to\s+be\s+(?:the\s+)?system)\b/i',
            'prompt_exfiltration' => '/\b(?:reveal|print|dump|show)\b.{0,120}\b(?:system\s+prompt|hidden\s+prompt|developer\s+message)\b/i',
            'credential_exfiltration' => '/\b(?:api[_\s-]?key|token|secret|password|environment\s+variable|env\s+var)\b.{0,120}\b(?:reveal|print|dump|show|exfiltrat(?:e|ion)?)\b/i',
            'shell_secret_fetch' => '/\b(?:curl|wget)\b[^\n]{0,160}\$(?:[A-Z_][A-Z0-9_]*)/i',
        ];

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $issues[] = $label;
            }
        }

        return array_values(array_unique($issues));
    }

    public function isSafe(string $text): bool
    {
        return $this->scan($text) === [];
    }
}

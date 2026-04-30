<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiDoctor;

class DoctorCommand implements PowerCommand
{
    public function name(): string
    {
        return ':doctor';
    }

    public function aliases(): array
    {
        return [':diag'];
    }

    public function description(): string
    {
        return 'Self-diagnostic check of the environment and project';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiDoctor::class;
    }

    public function buildPrompt(string $args): string
    {
        $scope = $args !== '' ? "\n\nAdditional checks: {$args}" : '';

        return <<<PROMPT
            DOCTOR MODE — SYSTEM DIAGNOSTIC.{$scope}

            Run a comprehensive health check of the development environment and project. Check everything, report issues with severity and suggested fixes.

            ## Checks to run:

            ### 1. Runtime Environment
            - PHP version (check `php -v`, verify >= 8.4)
            - Required PHP extensions (check for: curl, mbstring, openssl, pdo_sqlite, pcntl, readline)
            - Composer version and autoload status
            - Available memory and execution limits

            ### 2. Project Health
            - Config files exist and are valid YAML (config/kosmo.yaml)
            - Dependencies installed (`vendor/` exists, no lock file conflicts)
            - No syntax errors in source files (`php -l` on key files)
            - Database/storage directories writable

            ### 3. Provider Connectivity
            - For each configured LLM provider: test API connectivity
            - Check API key format (not expired/malformed, don't log the actual key)
            - Verify model availability

            ### 4. TUI Availability
            - Check if Symfony TUI dependencies are available
            - Terminal capabilities (256 color, true color, unicode)
            - Terminal size adequacy (minimum 80x24)

            ### 5. Test Suite
            - Run the test suite, report pass/fail count
            - Check for test configuration issues

            ## Output Format:
            For each check:
            ```
            ✓ Check name — passed (details)
            ⚠ Check name — warning: explanation (suggestion)
            ✗ Check name — FAILED: explanation (fix: specific steps)
            ```

            Summary at the end: N passed, N warnings, N failures.
            PROMPT;
    }
}

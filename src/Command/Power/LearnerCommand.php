<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiLearner;

class LearnerCommand implements PowerCommand
{
    public function name(): string
    {
        return ':learner';
    }

    public function aliases(): array
    {
        return [':learn'];
    }

    public function description(): string
    {
        return 'Extract a reusable pattern from this conversation';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiLearner::class;
    }

    public function buildPrompt(string $args): string
    {
        $focus = $args !== '' ? "\n\nFocus on: {$args}" : '';

        return <<<PROMPT
            LEARNER MODE — KNOWLEDGE EXTRACTION.{$focus}

            Analyze this conversation and extract a reusable debugging/development pattern or technique that could help in future sessions.

            ## Quality Gate:
            Only save a pattern if ALL of these are true:
            1. It's GENERALIZABLE — applicable beyond this specific instance
            2. It's NON-OBVIOUS — not something any developer would already know
            3. It's ACTIONABLE — provides a concrete technique, not just an observation
            4. It has a clear TRIGGER — you can describe when to apply it

            ## Pattern Structure:
            If a pattern passes the quality gate, save it as a memory with this structure:

            - **Name**: Short descriptive name (e.g., "Fiber Deadlock Detection")
            - **Trigger**: When should this pattern be applied? (e.g., "When subagents appear stuck and concurrency is limited")
            - **Technique**: Step-by-step procedure
            - **Example**: Concrete instance from this conversation
            - **Pitfalls**: What NOT to do, common mistakes

            ## Process:
            1. Review the conversation for interesting problem-solving moments
            2. Identify the technique used (or the mistake made and lesson learned)
            3. Generalize it: strip project-specific details, keep the core pattern
            4. Apply the quality gate
            5. If it passes: save to memory. If it fails: explain why and don't save junk.

            If no pattern passes the quality gate, say so honestly. Low-quality patterns are worse than no patterns.
            PROMPT;
    }
}

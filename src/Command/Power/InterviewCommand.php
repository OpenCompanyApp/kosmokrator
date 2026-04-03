<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiInterview;

class InterviewCommand implements PowerCommand
{
    public function name(): string
    {
        return ':interview';
    }

    public function aliases(): array
    {
        return [':socrates'];
    }

    public function description(): string
    {
        return 'Socratic requirements gathering before expensive work';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiInterview::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            DEEP INTERVIEW — SOCRATIC REQUIREMENTS GATHERING.

            The user wants: "{$args}"

            Before any implementation, we need clarity. Vague requirements lead to wasted work. You will interview the user using structured questioning to reduce ambiguity below a threshold.

            ## Ambiguity Dimensions:
            Score each from 0 (crystal clear) to 100 (completely unknown):

            1. **Goal** — What exactly should the end result look like?
            2. **Constraints** — What are the boundaries (time, tech, compatibility)?
            3. **Success Criteria** — How will we know it's done correctly?
            4. **Context** — What's the broader situation this fits into?
            5. **Edge Cases** — What happens in unusual situations?
            6. **Priority** — What matters most if tradeoffs are needed?

            ## Process:

            ### Round 1: Initial Assessment
            - Read relevant code/docs to understand the current state
            - Score each dimension based on what's given and what's implied
            - Calculate overall ambiguity: weighted average (Goal=30%, Criteria=25%, Constraints=20%, Context=10%, Edges=10%, Priority=5%)
            - If overall < 20%: skip to implementation summary
            - If overall >= 20%: proceed to questioning

            ### Round 2-N: Targeted Questions
            - Ask 2-4 focused questions targeting the highest-ambiguity dimensions
            - For each question, explain WHY you're asking (what decision it unblocks)
            - After each user response, re-score and show updated ambiguity

            ### Challenge Agents (apply internally):
            - **Contrarian**: What could go wrong with the current understanding? What assumptions are risky?
            - **Simplifier**: Is there a simpler interpretation that achieves the same goal?
            - **Ontologist**: Are any terms ambiguous? (e.g., "fast" — fast to run? fast to implement?)

            ## Rules:
            - Maximum 3 rounds of questions before proceeding
            - Each round should ask the MOST DISCRIMINATING questions (biggest ambiguity reduction per question)
            - Show the ambiguity scores after each round so the user sees convergence
            - When ambiguity < 20%, produce a clear requirements summary and ask for confirmation
            - NEVER proceed to implementation within this mode — output is a requirements document only
            PROMPT;
    }
}

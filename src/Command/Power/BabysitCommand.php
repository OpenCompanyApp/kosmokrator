<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiBabysit;

class BabysitCommand implements PowerCommand
{
    public function name(): string
    {
        return ':babysit';
    }

    public function aliases(): array
    {
        return [':watch', ':shepherd'];
    }

    public function description(): string
    {
        return 'Monitor a PR until merged — handle CI, reviews, and fixes';
    }

    public function requiresArgs(): bool
    {
        return true;
    }

    public function animationClass(): string
    {
        return AnsiBabysit::class;
    }

    public function buildPrompt(string $args): string
    {
        return <<<PROMPT
            BABYSIT MODE — PR SHEPHERD. Always watching.

            Target: {$args}

            Monitor this pull request until it is merged or closed. You are the tireless guardian that keeps the PR moving forward.

            ## Initial Assessment
            1. Fetch PR details: `gh pr view <number> --json state,title,body,reviews,statusCheckRollup,mergeable,reviewDecision`
            2. Check CI status: `gh pr checks <number>`
            3. Check for review comments: `gh api repos/{owner}/{repo}/pulls/<number>/comments`
            4. Assess current state and report what needs attention

            ## Monitoring Loop
            Continuously check the PR status. For each check:

            ### If CI is failing:
            - Read the failing check logs: `gh run view <run-id> --log-failed`
            - Classify the failure:
              - **Code issue**: Analyze the error and suggest/apply a fix
              - **Flaky test**: Retry up to 2 times with `gh run rerun <run-id> --failed`
              - **Infrastructure**: Report and wait (nothing we can do)
            - After fixing, push and re-check

            ### If reviews request changes:
            - Read each review comment carefully
            - Address the feedback: make the requested changes or explain why not
            - Push fixes and re-request review

            ### If PR is approved + CI green:
            - Check mergeable status
            - Report that PR is ready to merge
            - If auto-merge is enabled, confirm it will merge automatically

            ### If PR is merged or closed:
            - Report the final state and exit

            ## Rules:
            - Check status every 2-3 minutes (use `gh` commands, not sleep loops)
            - Be conservative with fixes — only change what the review explicitly asks for
            - Never force-push unless absolutely necessary (and warn before doing so)
            - Report every significant state change to the user
            - If stuck for more than 3 cycles with no progress, ask the user for guidance
            - If monitoring for more than 60 minutes, report status and suggest the user check manually. Do not run indefinitely.
            - Keep a running log of actions taken
            PROMPT;
    }
}

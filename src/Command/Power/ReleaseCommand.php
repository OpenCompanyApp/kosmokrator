<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiRelease;

class ReleaseCommand implements PowerCommand
{
    public function name(): string
    {
        return ':release';
    }

    public function aliases(): array
    {
        return [':ship'];
    }

    public function description(): string
    {
        return 'Automated release: version bump, test, tag, publish';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiRelease::class;
    }

    public function buildPrompt(string $args): string
    {
        $version = $args !== '' ? "\n\nRequested version: {$args}" : '';

        return <<<PROMPT
            RELEASE MODE — SEALED & SHIPPED.{$version}

            Execute a structured release workflow. Every step must pass before proceeding to the next.

            ## Step 1: Pre-flight Checks
            - Verify we're on the main/master branch (or the release branch)
            - Ensure working directory is clean (`git status`)
            - Pull latest changes (`git pull`)
            - Check for uncommitted work that should be included

            ## Step 2: Detect Version Scheme
            - Check existing tags: `git tag --sort=-v:refname | head -10`
            - Detect the versioning scheme (semver, calver, etc.)
            - Check for version files: package.json, composer.json, version.php, etc.
            - If a version was requested in args, use it. Otherwise, determine the appropriate bump:
              - Check commits since last tag: `git log <last-tag>..HEAD --oneline`
              - Breaking changes → major bump
              - New features → minor bump
              - Bug fixes only → patch bump

            ## Step 3: Version Bump
            - Update ALL version references found in Step 2
            - Update CHANGELOG.md if it exists (add new section with changes since last tag)
            - List all files modified for user confirmation

            ## Step 4: Test
            - Run the full test suite
            - If tests fail, STOP and report. Do not release with failing tests.
            - Run linting/code style checks if configured

            ## Step 5: Commit & Tag
            - Stage version-bumped files
            - Commit with message: "release: vX.Y.Z"
            - Create annotated tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z"`

            ## Step 6: Push & Publish
            - NEVER push or publish without explicit user confirmation.
            - Before pushing, display a PRE-PUSH SUMMARY listing:
              1. Exact commands to be executed (e.g. `git push`, `git push origin vX.Y.Z`, `gh release create vX.Y.Z --generate-notes`)
              2. What each command will do (remote affected, visibility, etc.)
              3. Any irreversible actions
            - Wait for the user to confirm each push/publish action individually.
            - If the user declines or aborts, stop at this step and report what was completed.
            - Push commits: `git push`
            - Push tag: `git push origin vX.Y.Z`
            - Create GitHub release: `gh release create vX.Y.Z --generate-notes`
            - If composer.json exists, note that Packagist will auto-update from the tag
            - If package.json exists with publish config, run `npm publish`

            ## Step 7: Verify
            - Confirm tag exists on remote: `git ls-remote --tags origin | grep vX.Y.Z`
            - Confirm GitHub release was created: `gh release view vX.Y.Z`
            - Report the release URL

            ## Rules:
            - STOP at any failure. Never skip a failing step.
            - Ask the user before pushing (this is a visible, irreversible action)
            - Show a summary before committing: files changed, version, changelog entry
            PROMPT;
    }
}

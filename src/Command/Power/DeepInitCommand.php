<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiDeepInit;

class DeepInitCommand implements PowerCommand
{
    public function name(): string
    {
        return ':deepinit';
    }

    public function aliases(): array
    {
        return [':init', ':map'];
    }

    public function description(): string
    {
        return 'Deep codebase documentation and knowledge map generation';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiDeepInit::class;
    }

    public function buildPrompt(string $args): string
    {
        $scope = $args !== '' ? "\n\nFocus area: {$args}" : '';

        return <<<PROMPT
            DEEP INIT — FULL CODEBASE EXCAVATION.{$scope}

            Crawl the entire project and generate comprehensive documentation that gives any developer (human or AI) instant understanding of the codebase.

            ## Deliverables:

            ### 1. Architecture Overview
            - High-level system diagram (describe in text — entry points, major components, data flow)
            - Key design decisions and why they were made (infer from patterns)
            - Technology stack and framework usage

            ### 2. Component Map
            For each major directory/namespace:
            - Purpose and responsibility
            - Key classes and their roles
            - How it connects to other components
            - Important interfaces/contracts

            ### 3. Data Flow
            - How data enters the system (CLI args, API calls, config files)
            - How it's transformed and processed
            - Where state is persisted (DB, files, memory)
            - How results are output

            ### 4. Entry Points
            - All executable entry points (bin scripts, commands, handlers)
            - For each: what triggers it, what it does, what it touches

            ### 5. Testing Strategy
            - What's tested and how (unit, integration, e2e)
            - Test conventions and patterns used
            - Coverage gaps (directories without tests)

            ### 6. Developer Guide
            - How to set up the development environment
            - How to run tests
            - Code conventions and style patterns
            - Common tasks and how to accomplish them

            ## Output:
            Write the documentation to `AGENTS.md` in the project root. Use clear markdown headings and keep each section scannable. Prefer concrete file paths and class names over vague descriptions.

            ## Process:
            1. Start by reading the directory structure (glob patterns)
            2. Read key entry points and configuration files
            3. Trace the main execution paths
            4. Read representative files from each major directory
            5. Synthesize findings into the documentation structure above
            PROMPT;
    }
}

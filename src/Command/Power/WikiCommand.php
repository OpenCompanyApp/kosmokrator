<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Power;

use Kosmokrator\Command\PowerCommand;
use Kosmokrator\UI\Ansi\AnsiWiki;

class WikiCommand implements PowerCommand
{
    public function name(): string
    {
        return ':wiki';
    }

    public function aliases(): array
    {
        return [':w'];
    }

    public function description(): string
    {
        return 'Build and maintain a persistent, interlinked markdown knowledge base';
    }

    public function requiresArgs(): bool
    {
        return false;
    }

    public function animationClass(): string
    {
        return AnsiWiki::class;
    }

    public function buildPrompt(string $args): string
    {
        $wikiPath = getenv('LLM_WIKI_PATH') ?: '~/wiki';

        if ($args === '') {
            return $this->promptQuery($wikiPath, 'Browse the wiki and provide an overview of its current state, or help the user get started.');
        }

        $lower = strtolower($args);
        $firstWord = strtok($lower, ' ') ?: '';

        return match ($firstWord) {
            'init' => $this->promptInit($wikiPath, ltrim(substr($args, strlen('init')))),
            'ingest' => $this->promptIngest($wikiPath, ltrim(substr($args, strlen('ingest')))),
            'lint' => $this->promptLint($wikiPath),
            default => $this->promptQuery($wikiPath, $args),
        };
    }

    private function promptInit(string $wikiPath, string $domain): string
    {
        $domainPrompt = $domain !== ''
            ? "Domain: \"{$domain}\""
            : 'Ask the user what domain/topic the wiki should cover before creating the schema.';

        return <<<PROMPT
            WIKI INIT — CREATE A PERSISTENT KNOWLEDGE BASE.

            Wiki path: {$wikiPath}
            {$domainPrompt}

            Create a new wiki following the LLM Wiki pattern (Karpathy). The wiki is a directory of interlinked markdown files that compounds knowledge over time.

            ## Directory Structure

            Create the following layout:

            ```
            wiki/
            ├── SCHEMA.md           # Conventions, structure rules, domain config
            ├── index.md            # Content catalog — every page with one-line summary
            ├── log.md              # Chronological action log (append-only)
            ├── raw/                # Layer 1: Immutable source material
            │   ├── articles/
            │   ├── papers/
            │   ├── transcripts/
            │   └── assets/
            ├── entities/           # Layer 2: People, orgs, products, models
            ├── concepts/           # Layer 2: Topics, ideas, techniques
            ├── comparisons/        # Layer 2: Side-by-side analyses
            └── queries/            # Layer 2: Filed query results worth keeping
            ```

            ## SCHEMA.md

            Write a SCHEMA.md customized to the domain with these conventions:

            - File names: lowercase, hyphens, no spaces
            - Every wiki page has YAML frontmatter:
              ```yaml
              ---
              title: Page Title
              created: YYYY-MM-DD
              updated: YYYY-MM-DD
              type: entity | concept | comparison | query | summary
              tags: [tag1, tag2]
              sources: [raw/articles/source-name.md]
              ---
              ```
            - Use `[[wikilinks]]` to cross-reference pages
            - Always bump `updated` date when touching a page
            - Every new page must be added to `index.md`
            - Every action must be appended to `log.md`

            Include sections for: Entity pages (one per notable entity), Concept pages (one per topic), Comparison pages (side-by-side), Source summaries (companion to raw sources).

            ## index.md

            ```markdown
            # Wiki Index

            > Content catalog. Every wiki page with a one-line summary.
            > Read this first to find relevant files for any query.

            ## Entities
            <!-- entity pages listed here -->

            ## Concepts
            <!-- concept pages listed here -->

            ## Comparisons
            <!-- comparison pages listed here -->

            ## Queries
            <!-- filed query results listed here -->
            ```

            ## log.md

            ```markdown
            # Wiki Log

            > Chronological record of all wiki actions. Append-only.
            > Format: `## [YYYY-MM-DD] action | subject`
            > Actions: ingest, update, query, lint, create, delete

            ## [YYYY-MM-DD] create | Wiki initialized
            - Domain: [domain]
            - Structure created with SCHEMA.md, index.md, log.md
            ```

            Create all directories and files. Confirm the wiki is ready.
            PROMPT;
    }

    private function promptIngest(string $wikiPath, string $source): string
    {
        $sourcePrompt = $source !== ''
            ? "Source: \"{$source}\""
            : 'Ask the user for the source to ingest (URL, file path, or pasted text).';

        return <<<PROMPT
            WIKI INGEST — INTEGRATE A SOURCE INTO THE KNOWLEDGE BASE.

            Wiki path: {$wikiPath}
            {$sourcePrompt}

            Follow the LLM Wiki ingest protocol:

            ## Step 1: Capture the raw source
            - URL → fetch and save markdown to `raw/articles/`
            - File path → copy or reference in `raw/`
            - Pasted text → save to appropriate `raw/` subdirectory
            - Name descriptively: `raw/articles/source-name-YYYY.md`
            - **Never modify files in `raw/`** — sources are immutable

            ## Step 2: Discuss takeaways
            - Briefly share what's interesting and relevant to the domain
            - Let the user guide what to emphasize

            ## Step 3: Write or update wiki pages
            A single source can trigger updates across 10-15 wiki pages. This is the compounding effect.

            - Create a summary page if the source is substantial
            - Create or update entity pages for key people/orgs/tools mentioned
            - Create or update concept pages for key ideas
            - Add `[[wikilinks]]` between new and existing pages — every page should link to at least one other page
            - All pages get YAML frontmatter (title, created/updated dates, type, tags, sources)

            ## Step 4: Update navigation
            - Add new pages to `index.md` with one-line summaries
            - Append to `log.md`: `## [YYYY-MM-DD] ingest | Source Title`

            ## Step 5: Report what changed
            List every file created or updated.

            Rules:
            - Never modify files in `raw/`
            - Always update index.md and log.md — skipping this makes the wiki degrade
            - Every page should link to at least one other page (no isolated pages)
            - Frontmatter is required on every wiki page
            - Keep summaries concise — scannable in 30 seconds
            - If ingest would touch 10+ existing pages, confirm scope with user first
            PROMPT;
    }

    private function promptLint(string $wikiPath): string
    {
        return <<<PROMPT
            WIKI LINT — HEALTH-CHECK THE KNOWLEDGE BASE.

            Wiki path: {$wikiPath}

            Audit the wiki for consistency and completeness.

            ## Checks

            1. **Contradictions** — Read pages on the same topic. Flag conflicting claims with specific file paths and the contradictory statements.

            2. **Orphan pages** — Find wiki pages with no inbound `[[wikilinks]]` from other pages. These are invisible to navigation.

            3. **Stale content** — Pages whose `updated` date is old relative to newer sources that may supersede them.

            4. **Data gaps** — Topics referenced in existing pages but lacking their own dedicated page.

            5. **Index completeness** — Every wiki page should appear in `index.md`. Find any missing entries.

            6. **Cross-reference density** — Pages with fewer than 2 `[[wikilinks]]` may need better integration.

            7. **Frontmatter consistency** — Verify all pages have valid YAML frontmatter with required fields (title, created, updated, type, tags).

            ## Output

            ```
            ═══ WIKI HEALTH REPORT ═══

            Pages scanned: N
            Issues found: N

            ## Contradictions
            - [page-a.md] says "X" but [page-b.md] says "Y"

            ## Orphan Pages
            - orphan-page.md (no inbound links)

            ## Stale Pages
            - concept-x.md (updated 2026-01-15, but newer source from 2026-03-20 exists)

            ## Data Gaps
            - "Topic Y" referenced in 3 pages but has no dedicated page

            ## Missing from Index
            - unlisted-page.md

            ## Low Cross-Reference Density
            - sparse-page.md (0 wikilinks)

            ## Frontmatter Issues
            - broken-page.md (missing 'type' field)

            ## Recommendations
            - <suggested next sources to seek>
            - <suggested new pages to create>
            ```

            Append to `log.md`: `## [YYYY-MM-DD] lint | N issues found`
            PROMPT;
    }

    private function promptQuery(string $wikiPath, string $question): string
    {
        return <<<PROMPT
            WIKI QUERY — SYNTHESIZE FROM COMPILED KNOWLEDGE.

            Wiki path: {$wikiPath}
            Question: "{$question}"

            ## Protocol

            1. **Read `index.md`** to identify relevant pages
            2. **Read the relevant pages** — use grep/search if the wiki is large (100+ pages)
            3. **Synthesize an answer** from the compiled knowledge with citations (page names)
            4. **File valuable answers back** — if the answer is a substantial comparison, deep dive, or discovery, create a new page in `queries/` or `comparisons/` so it doesn't disappear into chat history. This is the compounding loop.
            5. **Update `log.md`** with the query

            ## If the wiki doesn't exist yet

            If `index.md` is not found at the wiki path, offer to initialize a new wiki with `init` mode. Ask what domain it should cover.

            ## Answer format

            - Cite wiki pages used: "According to [[concept-x]] and [[entity-y]]..."
            - If the wiki doesn't contain enough to answer fully, say so and suggest sources to ingest
            - If creating a filed answer page, include full frontmatter and add to index.md
            PROMPT;
    }
}

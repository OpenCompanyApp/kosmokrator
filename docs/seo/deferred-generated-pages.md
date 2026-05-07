# Deferred Generated SEO Pages

This file tracks generated page families that are intentionally not part of the
current indexable website structure. They may become useful again after the
domain has more authority, Search Console shows clearer demand, and each page can
carry enough unique content to stand alone.

## Current Canonical Integration Pages

The indexable generated integration structure is:

- `/integrations/{slug}`
- `/integrations/{slug}/cli`
- `/integrations/{slug}/mcp`
- `/integrations/{slug}/lua`

These pages target distinct search intents: integration overview, command-line
usage, MCP gateway setup, and Lua automation.

## Deferred Page Families

### Framework-Specific Integration Pages

Pattern:

- `/integrations/{slug}/framework/{client}`

Examples:

- `/integrations/clickup/framework/claude-code`
- `/integrations/zoho-crm/framework/langchain`
- `/integrations/vultr/framework/cursor`

Current treatment:

- Redirect to `/integrations/{slug}/mcp`.
- Keep client-specific setup as anchored sections on the canonical MCP page.
- Redirects are scoped to one retired client segment so the canonical MCP page
  cannot redirect to itself.

Reason:

- The full matrix creates many near-duplicate pages.
- Provider plus client demand is better consolidated on the stronger MCP page.
- Internal navigation is clearer when client setup is a section, not a separate
  generated landing page.

Reactivation criteria:

- Search Console shows repeated impressions for `{provider} {client}` or
  `{provider} {framework}` queries.
- The page can include unique setup notes, client-specific config, examples,
  troubleshooting, and security guidance.
- Reactivate one provider/client pair at a time rather than restoring the full
  matrix.

### Environment-Specific CLI Pages

Pattern:

- `/integrations/{slug}/cli/{environment}`

Examples:

- `/integrations/modal/cli/ci`
- `/integrations/clickup/cli/cron`
- `/integrations/github/cli/shell-scripts`

Current treatment:

- Redirect to `/integrations/{slug}/cli`.
- Keep CI, cron, shell script, headless automation, and coding-agent guidance as
  anchored sections on the canonical CLI page.
- Redirects are scoped to one retired environment segment so the canonical CLI
  page cannot redirect to itself.

Reason:

- These pages mostly differ by wrapper context.
- The canonical CLI page can rank for provider CLI queries while still covering
  CI and automation sub-intents.

Reactivation criteria:

- Search Console shows provider plus environment demand, such as `{provider} cli
  ci`, `{provider} cli cron`, or `{provider} cli github actions`.
- The page has unique examples and failure modes for that environment.
- Reactivate selectively for high-signal integrations only.

### CLI Shortcut Pages

Pattern:

- `/cli/{slug}`

Examples:

- `/cli/clickup`
- `/cli/modal`
- `/cli/ionos`

Current treatment:

- Redirect to `/integrations/{slug}/cli`.

Reason:

- The shortcut competes with the canonical CLI page for the same query.
- Keeping one canonical URL concentrates internal links, sitemap signals, and
  content depth.

Reactivation criteria:

- Short URLs become a product/marketing need.
- The shortcut is implemented as a redirect or deliberately non-indexable alias,
  not a competing indexable page.

## Weekly Review Signals

Review these Search Console patterns before reactivating any family:

- `{provider} mcp`
- `{provider} cli`
- `{provider} lua`
- `{provider} langchain`
- `{provider} claude code`
- `{provider} cursor`
- `{provider} cli ci`
- `{provider} cli cron`

Promotion should be demand-led: enrich canonical pages first, then split out a
specific page only when the query pattern deserves its own answer.

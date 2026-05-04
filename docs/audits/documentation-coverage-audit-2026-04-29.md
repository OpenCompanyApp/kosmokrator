# Documentation Coverage Audit - 2026-04-29

Scope: compare shipped code surfaces against root docs and the Astro/Starlight website documentation.

## Investigated Sources

- Console command registry from `bin/kosmokrator` and `php bin/kosmokrator list --format=json`
- Settings schema from `php bin/kosmokrator settings:list --json`
- Provider discovery from `providers:list`
- Web provider discovery from `web:providers`
- Tool implementations under `src/Tool/`, `src/Task/Tool/`, and `src/Session/Tool/`
- Runtime modules under `src/Agent/`, `src/Sdk/`, `src/Acp/`, `src/Integration/`, `src/Mcp/`, `src/Web/`, `src/Gateway/`, `src/Skill/`, and `src/Settings/`
- Existing docs under `README.md`, `docs/`, and `website/src/content/docs/docs/`

## Main Gaps Found

- The website had strong narrative pages but no single exhaustive shell CLI reference.
- The configuration page mixed conceptual docs with partial reference data; several current setting IDs were not documented by exact name.
- Web provider commands and settings were spread across configuration/headless docs but lacked a dedicated provider matrix and troubleshooting flow.
- Telegram gateway code existed with headless configuration, session routing, access controls, and status commands, but no dedicated public guide.
- Session persistence, memory behavior, session tools, and compaction were documented in pieces but not as one operational guide.
- The skill system existed in code and was briefly mentioned in command docs, but discovery paths, file format, and management commands were not documented.
- `docs/README.md` still linked historical audit files that no longer exist and did not point maintainers at the Starlight docs source.

## Implemented Documentation Updates

- Added `website/src/content/docs/docs/cli-reference.mdx`
- Added `website/src/content/docs/docs/settings-reference.mdx`
- Added `website/src/content/docs/docs/web.mdx`
- Added `website/src/content/docs/docs/gateway-telegram.mdx`
- Added `website/src/content/docs/docs/sessions-memory.mdx`
- Added `website/src/content/docs/docs/skills.mdx`
- Added `website/src/content/docs/docs/troubleshooting.mdx`
- Updated the Starlight sidebar and documentation index so the new reference pages are discoverable.
- Linked existing conceptual pages to the new CLI and settings references.
- Updated `docs/README.md` with the Starlight docs source, reference-page map, and current audit links.
- Updated `docs/architecture/overview.md` key directory coverage for Web and Gateway modules.

## Coverage Model Going Forward

Every user-facing change should update at least one of these locations:

| Change type | Required docs |
|-------------|---------------|
| New shell command or option | `website/src/content/docs/docs/cli-reference.mdx` and the relevant feature page |
| New setting | `website/src/content/docs/docs/settings-reference.mdx` and `configuration.mdx` when conceptual behavior changes |
| New provider or auth flow | `providers.mdx`, `cli-reference.mdx`, and `settings-reference.mdx` if settings changed |
| New integration behavior | `integrations.mdx` and `cli-reference.mdx` |
| New MCP behavior | `mcp.mdx`, `cli-reference.mdx`, and `settings-reference.mdx` if policy/config changed |
| New SDK method/event | `sdk.mdx` and `cli-reference.mdx` if it mirrors CLI behavior |
| New ACP method/event | `acp.mdx` |
| New web provider/tool | `web.mdx`, `tools.mdx`, and `settings-reference.mdx` |
| New gateway behavior | `gateway-telegram.mdx` or a new gateway-specific page |
| Permission behavior | `permissions.mdx`, `settings-reference.mdx`, and relevant feature pages |
| Release/install behavior | `installation.mdx`, README, and release workflow notes |

## Validation

Run after documentation changes:

```bash
cd website
npm run build
```

For command/schema drift checks:

```bash
php bin/kosmokrator list --format=json
php bin/kosmokrator settings:list --json
php bin/kosmokrator web:providers --json
```

## Second Pass - 2026-04-29

Additional checks run:

- Stale path/search scan for removed website generator paths (`website/pages`, `website/html`, `website/build.php`)
- Command reference drift check against `php bin/kosmokrator list --format=json`
- Settings reference drift check against `php bin/kosmokrator settings:list --json`
- Internal `/docs/*` link check across Starlight MDX files
- Website build with `npm run build`

Confirmed fixes:

- Corrected `docs/README.md` to describe the desktop proposal as Tauri + ACP, not NativePHP + Electron.
- Corrected `permissions.mdx` to match the shipped permission chain: blocked paths -> deny patterns -> project boundary -> session grants -> rules -> mode overrides.
- Corrected `settings-reference.mdx` context/memory effect timing to `next turn`, matching the schema.
- Replaced a broken `/docs/context-and-memory` link in `tools.mdx` with links to `/docs/context` and `/docs/sessions-memory`.

Final validation:

- Command drift check: no missing public commands.
- Setting drift check: no missing setting IDs.
- Internal docs route check: no broken `/docs/*` links.
- Astro/Starlight build: passed.

# KosmoKrator Desktop App

> Status: Proposal. The shipped product is still the terminal application. This document describes the recommended desktop wrapper architecture.

## Recommendation

Build the desktop app as a Tauri application that talks to KosmoKrator through the ACP stdio server.

The desktop app should not embed PHP internals and should not scrape terminal output. It should spawn a normal KosmoKrator process, speak Agent Client Protocol, and render KosmoKrator's structured extension events for tools, permissions, subagents, integrations, MCP, Lua, usage, and runtime state.

```text
apps/desktop
  -> Tauri Rust backend
  -> spawn: kosmokrator acp --cwd /repo
  -> newline-delimited JSON-RPC over stdio
  -> Svelte UI renders ACP + kosmokrator/* events

bin/kosmokrator
  -> Kernel
  -> ACP server
  -> AgentSessionBuilder
  -> AgentLoop
  -> normal tools, permissions, sessions, Lua, integrations, MCP, subagents
```

This keeps terminal, headless CLI, SDK, and desktop wrappers on the same runtime path.

## Repo Location

The app should live in the main repository under `apps/desktop/`.

```text
apps/desktop/
  package.json
  vite.config.ts
  src/
    main.ts
    app.css
    lib/
      acp.ts
      events.ts
      types.ts
    stores/
      runtime.ts
      session.ts
      subagents.ts
      permissions.ts
    components/
      ProjectPicker.svelte
      Workspace.svelte
      ChatTimeline.svelte
      Composer.svelte
      ToolCallCard.svelte
      PermissionDialog.svelte
      SubagentTree.svelte
      DiffPanel.svelte
      SettingsPanel.svelte
  src-tauri/
    Cargo.toml
    tauri.conf.json
    src/
      main.rs
      kosmokrator_process.rs
      acp_client.rs
      event_bridge.rs
      settings.rs
```

Keeping it in the monorepo is preferable for now because ACP extension events, runtime settings, and UI expectations will evolve alongside the CLI.

## Frontend Stack

Use Svelte + Vite, not a full SvelteKit app.

Reasons:

- Fastest path to a polished Tauri UI.
- Small bundle and simple routing model.
- Stores map cleanly to long-lived ACP session state.
- Less boilerplate than React for a desktop shell.
- Easy component boundaries for chat, tool cards, permission dialogs, subagent trees, and settings.

Recommended packages:

- Tauri 2
- Svelte + Vite + TypeScript
- Tailwind or plain CSS modules
- Bits UI or shadcn-svelte for dialogs, menus, tabs, and command palette
- CodeMirror 6 for code previews and editable snippets
- xterm.js only for an optional raw terminal/debug panel

## Process Model

The Rust backend owns the KosmoKrator child process.

Responsibilities:

- Resolve which `kosmokrator` binary to use.
- Start `kosmokrator acp --cwd <project>`.
- Frame JSON-RPC messages over stdin/stdout.
- Route stderr to diagnostics, not the normal event stream.
- Restart or close the process when the project changes.
- Emit typed Tauri events to the frontend.
- Accept frontend commands for prompt, cancel, permission response, settings changes, and session selection.

Binary resolution should support both:

- `system`: use a user-configured path or `kosmokrator` from `$PATH`.
- `sidecar`: bundle release binaries inside the Tauri app later.

MVP should use `system` first. Sidecar bundling can come after the UI and ACP bridge are stable.

## Primary UI

The desktop app should feel like KosmoKrator with a native shell around it, not a generic chat app.

Initial screens:

- Project picker: recent repos, open folder, active branch, configured runtime.
- Agent workspace: chat timeline, streaming text, tool calls, result cards, composer.
- Permission modal: ACP-backed approve/deny/always with exact tool payload.
- Subagent tree: live parent/child status, dependencies, group sequencing, failures, elapsed time.
- Session list: resume persisted sessions from the same store as the terminal.
- Diff panel: file changes, patches, and command output tied to tool calls.
- Runtime bar: mode, permission mode, provider, model, context health, token usage.
- Integrations/MCP panel: list configured providers/servers, inspect schemas, call test tools.
- Settings: provider credentials, headless config, project/global scope, binary path.
- Diagnostics: ACP traffic, process stderr, version, environment, logs.

Suggested layout:

```text
top bar:    repo / branch / mode / permission / provider / model
left rail:  sessions / files / subagents
center:     conversation timeline + tool cards + composer
right:      diff / terminal / integrations / MCP / diagnostics
bottom:     status, usage, active process, errors
```

## ACP Usage

The desktop app should use standard ACP methods where possible and KosmoKrator extension methods where needed.

Core flow:

1. Start `kosmokrator acp --cwd /repo`.
2. Send `initialize`.
3. Create or resume a session.
4. Send `session/prompt`.
5. Render streamed content and `kosmokrator/*` notifications.
6. When `session/request_permission` arrives, show a native modal and send the user's decision.
7. Keep the session resumable by terminal and desktop.

Extension events to render first:

- `kosmokrator/phase`
- `kosmokrator/text_delta`
- `kosmokrator/thinking_delta`
- `kosmokrator/tool/call`
- `kosmokrator/tool/result`
- `kosmokrator/permission/request`
- `kosmokrator/subagents/spawn`
- `kosmokrator/subagents/tree`
- `kosmokrator/subagents/complete`
- `kosmokrator/usage`
- `kosmokrator/error`

Direct extension methods should power settings and helper panels:

- runtime settings
- provider configuration
- integration list/describe/call
- MCP server/tool/schema/call
- Lua execute

## Permissions

The desktop app should preserve KosmoKrator's existing permission model.

- Guardian and Argus show approval modals for governed calls.
- Prometheus proceeds automatically except hard denies.
- Path denies and command denies remain enforced.
- Permission responses should support allow, deny, and always.
- The UI must show the exact tool name, arguments, working directory, and risk summary before approval.

The desktop app should not add a second independent permission system. It should render and answer the runtime's permission requests.

## What Not To Do

- Do not embed the PHP SDK in Tauri. A non-PHP app should use ACP.
- Do not scrape ANSI/TUI output for state.
- Do not fork a separate agent runtime for desktop.
- Do not make desktop sessions incompatible with terminal sessions.
- Do not register MCP tools as native desktop-only tools; keep MCP available through KosmoKrator runtime and Lua.

## Phased Implementation

### Phase 1: Thin ACP Shell

- Scaffold `apps/desktop` with Tauri 2, Svelte, Vite, TypeScript.
- Add Rust process manager for `kosmokrator acp`.
- Implement initialize, new session, prompt, cancel, close.
- Render text stream, tool calls, tool results, and errors.
- Add project picker and binary path setting.

### Phase 2: Terminal-Equivalent UX

- Add permission modal.
- Add session resume/list.
- Add runtime bar for mode, permission mode, provider, model.
- Add subagent tree and live status.
- Add diff/result panel for file edits and patches.

### Phase 3: Headless Runtime Panels

- Add provider credential configuration.
- Add integrations panel with docs, schema, accounts, and test calls.
- Add MCP panel with local/global server config, trust, tools, resources, prompts, and secrets.
- Add Lua execute panel for advanced workflows.

### Phase 4: Native Desktop Polish

- Add tray item and notifications.
- Add file picker and drag/drop context attachment.
- Add global shortcut to open/focus the app.
- Add sidecar binary bundling.
- Add auto-update.

## Testing

Test against a real local KosmoKrator binary and a fixture ACP server.

Required coverage:

- ACP framing and request/response correlation.
- Process crash and restart behavior.
- Permission request lifecycle.
- Session resume across terminal and desktop.
- Subagent tree rendering with nested agents.
- Large tool results and streaming output.
- Provider/integration/MCP configuration writes.
- Mobile-width desktop windows and high-DPI screenshots.

Before shipping, run Playwright or Tauri WebDriver screenshots for the main workspace at narrow, medium, and wide window sizes.

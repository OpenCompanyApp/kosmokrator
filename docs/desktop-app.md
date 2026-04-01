# KosmoKrator Desktop App

> Status: Proposal. This document describes a possible future desktop surface. The current shipped product is the terminal application.

## Concept

KosmoKrator is one product with two surfaces: terminal and desktop. The desktop app is not a companion or wrapper — it runs the same engine (AgentLoop, PrismService, ToolRegistry, Lua bridge, MCP client) with a GUI renderer instead of ANSI/TUI.

```
              KosmoKrator (the engine)
              ├── Kernel, ConfigLoader
              ├── AgentLoop, PrismService
              ├── ToolRegistry, Lua bridge
              ├── MCP client
              └── Integration loader
                       │
            ┌──────────┴──────────┐
            │                     │
      CLI surface            Desktop surface
      (bin/kosmokrator)      (NativePHP app)
            │                     │
      Symfony Console        Electron window
      + TUI renderer         + web renderer
            │                     │
      terminal               system tray
      on-demand              always-on
                             notifications
                             OAuth flows
                             global shortcuts
```

The split happens at the UI layer. `RendererInterface` already abstracts rendering — `AnsiRenderer`, `TuiRenderer`, and the desktop app adds a third: a web-based renderer that pushes events to the Electron frontend.

---

## Why NativePHP

NativePHP wraps a Laravel app in Electron (desktop) or native shells (mobile). It bundles its own static PHP binary — users install one app, no PHP or Node required.

KosmoKrator's engine already boots an Illuminate Container (via Prism's transitive `laravel/framework` dependency). The desktop surface wraps this in a minimal Laravel HTTP layer that NativePHP can host, while the core engine remains framework-agnostic.

**NativePHP provides what terminals and browsers can't:**

| Capability | Terminal | Browser | Desktop (NativePHP) |
|-----------|----------|---------|---------------------|
| System tray (always-on) | No | No | Yes |
| Native notifications | No | Limited | Yes |
| Global shortcuts | No | No | Yes |
| OAuth redirect flows | Painful (copy-paste) | Callback URL complexity | Native redirect URI |
| File dialogs | CLI path input | Browser picker | Native OS picker |
| Deep linking | No | URL only | Custom protocol (`kosmokrator://`) |
| Auto-updater | Manual | N/A | Built-in OTA |
| Offline-first | Yes | No | Yes |

---

## Architecture

### Shared Core (framework-agnostic)

```
src/
├── Kernel.php              # Boots Illuminate Container + config
├── ConfigLoader.php        # YAML → Config Repository
├── Agent/
│   ├── AgentLoop.php       # Core loop: prompt → LLM → tools → loop
│   ├── ConversationHistory.php
│   └── Middleware/
├── LLM/
│   └── PrismService.php    # Prism wrapper, provider failover
├── Tool/
│   ├── ToolInterface.php
│   ├── ToolRegistry.php
│   └── Coding/             # Built-in tools
├── Lua/
│   ├── LuaSandboxService.php
│   ├── LuaBridge.php
│   └── LuaApiDocGenerator.php
├── Mcp/
│   └── McpClient.php
├── Integration/
│   ├── IntegrationLoader.php
│   └── YamlCredentialResolver.php
└── Session/
    ├── Session.php
    └── SessionStore.php
```

This is the engine. It has no opinion about rendering.

### CLI Surface (Symfony Console)

```
bin/kosmokrator
src/
├── Command/
│   └── AgentCommand.php     # REPL loop
└── UI/
    ├── RendererInterface.php
    ├── UIManager.php
    ├── Ansi/AnsiRenderer.php
    └── Tui/TuiRenderer.php
```

### Desktop Surface (NativePHP + Electron)

```
desktop/
├── app/
│   ├── Providers/
│   │   └── NativeAppServiceProvider.php   # NativePHP window, menu, tray
│   ├── Http/
│   │   └── Controllers/
│   │       └── AgentController.php        # WebSocket bridge to AgentLoop
│   └── Renderers/
│       └── WebRenderer.php                # RendererInterface → WebSocket events
├── resources/
│   ├── views/                             # Blade/Vue frontend
│   └── js/
│       ├── app.js
│       └── components/
│           ├── ConversationView.vue       # Chat UI
│           ├── ToolCallPanel.vue          # Tool execution display
│           ├── IntegrationManager.vue     # OAuth flows, credential management
│           └── StatusBar.vue              # Model, tokens, cost
├── routes/
│   └── web.php
├── composer.json                          # Requires kosmokrator/kosmokrator + nativephp/desktop
└── package.json                           # Frontend deps (Vue, Tailwind, etc.)
```

The desktop surface is a thin Laravel app that:
1. Boots the shared KosmoKrator Kernel
2. Creates a `WebRenderer` implementing `RendererInterface`
3. Pushes render events (thinking, streaming, tool calls) over WebSocket to the Vue frontend
4. Receives user input from the frontend and feeds it to `AgentLoop`
5. NativePHP handles the Electron shell, system tray, notifications, etc.

### WebRenderer

```php
class WebRenderer implements RendererInterface
{
    public function showThinking(): void
    {
        broadcast(new AgentEvent('thinking'));
    }

    public function streamChunk(string $text): void
    {
        broadcast(new AgentEvent('chunk', ['text' => $text]));
    }

    public function showToolCall(string $name, array $args): void
    {
        broadcast(new AgentEvent('tool_call', ['name' => $name, 'args' => $args]));
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        broadcast(new AgentEvent('tool_result', [
            'name' => $name,
            'output' => $output,
            'success' => $success,
        ]));
    }

    // ... etc
}
```

Same `RendererInterface`, just broadcasting instead of printing ANSI codes.

---

## OpenCompany Connection

OpenCompany is an optional cloud backend — not required, not a separate product in this context.

```yaml
# ~/.kosmokrator/config.yaml
opencompany:
  enabled: true
  url: https://my-instance.opencompany.app
  api_key: sk-...
```

### When Connected

- Pulls available hosted integrations (ClickUp, Google, etc.)
- Syncs integration credentials (no local OAuth needed for already-configured integrations)
- Proxies tool calls for hosted-mode integrations
- Syncs sessions/conversation history (optional)
- Access to OpenCompany's vector memory and knowledge base

### When Disconnected

- Full local operation — same agent, same built-in tools, same Lua bridge
- Local integrations work (credentials in `~/.kosmokrator/integrations.yaml`)
- Local LLM via Ollama works
- MCP servers work
- Zero degradation for core coding agent functionality

The desktop app is KosmoKrator first, OpenCompany-connected second.

---

## Desktop-Specific Features

### System Tray

Agent lives in the system tray. Click to open conversation window. Badge shows when agent needs attention (tool approval, error, completion).

### Native Notifications

```
┌─────────────────────────────────┐
│ KosmoKrator                     │
│ ✓ Refactor complete — 4 files   │
│   changed, all tests passing    │
└─────────────────────────────────┘
```

Notifications for: agent completion, tool approval requests, errors, integration connection status.

### Global Shortcuts

Summon KosmoKrator from any application:

```
Cmd+Shift+K → opens KosmoKrator window with prompt focused
```

Quick-action mode: type a command, hit enter, window minimizes back to tray.

### OAuth Integration Flows

The desktop app owns a real redirect URI (`kosmokrator://oauth/callback`). Adding integrations:

1. Click "Add Gmail"
2. Browser opens Google OAuth consent screen
3. Google redirects to `kosmokrator://oauth/callback?code=...`
4. NativePHP's deep linking catches it
5. Tokens stored in credential resolver
6. Done — no copy-paste, no localhost callback server

### File Context

Native file picker for attaching context to conversations:

```
[Attach File] → OS file dialog → selected file added to conversation
```

Also: drag-and-drop files onto the conversation window.

### Auto-Updater

Ship updates via GitHub Releases. The app checks and updates silently in the background. Users always have the latest version without manual intervention.

---

## Package Structure

The desktop app is a separate Composer package that depends on the core:

```json
{
    "name": "kosmokrator/desktop",
    "require": {
        "kosmokrator/kosmokrator": "^1.0",
        "nativephp/desktop": "^2.0",
        "laravel/framework": "^13.0"
    }
}
```

The core `kosmokrator/kosmokrator` package remains CLI-first and framework-agnostic. The desktop package adds the Laravel HTTP layer and NativePHP integration on top.

This means:
- `composer global require kosmokrator/kosmokrator` → CLI agent
- Download KosmoKrator.app → desktop agent (bundles everything)
- Same engine, same config, same sessions, same integrations

---

## Rendering Surfaces Summary

| Surface | Renderer | Input | Output | Runtime |
|---------|----------|-------|--------|---------|
| Terminal (ANSI) | `AnsiRenderer` | readline | ANSI escape codes | `php bin/kosmokrator` |
| Terminal (TUI) | `TuiRenderer` | Symfony TUI InputWidget | TUI widgets + Revolt | `php bin/kosmokrator` |
| Desktop | `WebRenderer` | Vue frontend via WebSocket | Electron BrowserWindow | NativePHP (bundled PHP) |
| *(future)* Mobile | `MobileRenderer` | Native UI via EDGE | Swift/Kotlin shell | NativePHP Mobile |

All implement `RendererInterface`. The engine doesn't know which surface it's running on.

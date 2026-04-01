# KosmoKrator

AI coding agent for the terminal. Mythology-themed CLI built with PHP 8.4, Symfony Console, and a dual renderer (TUI/ANSI).

## Quick Start

```bash
bin/kosmokrator              # Run with auto-detected renderer (TUI if available, ANSI fallback)
bin/kosmokrator --renderer=ansi   # Force ANSI mode
bin/kosmokrator --no-animation    # Skip the animated intro
```

## Architecture

```
bin/kosmokrator → Kernel → AgentCommand → AgentLoop (REPL)
                                            ├── LLM client (AsyncLlmClient or PrismService)
                                            ├── UIManager → TuiRenderer | AnsiRenderer
                                            ├── ToolRegistry → tools (bash, file_read, file_write, file_edit, grep, glob)
                                            └── PermissionEvaluator → approval flow
```

### Key directories

- `src/Agent/` — AgentLoop (main REPL), ConversationHistory, AgentMode
- `src/LLM/` — LLM clients: AsyncLlmClient (Amp HTTP, async), PrismService (Prism PHP, sync)
- `src/UI/` — Rendering layer with RendererInterface
  - `UI/Tui/` — Symfony TUI widgets (EditorWidget prompt, ProgressBarWidget status, CollapsibleWidget results, MarkdownWidget responses)
  - `UI/Ansi/` — Pure ANSI fallback (MarkdownToAnsi, AnsiTableRenderer, KosmokratorTerminalTheme)
  - `UI/Theme.php` — Shared color palette, tool icons, context bar
- `src/Tool/` — Tool implementations in `Coding/`, permission system in `Permission/`
- `src/Command/` — AgentCommand (main), SetupCommand

### Rendering

Two renderers implement `RendererInterface`:
- **TuiRenderer** — Interactive Symfony TUI with widgets, Revolt event loop, EditorWidget for multi-line input
- **AnsiRenderer** — Pure ANSI escape codes, readline input, MarkdownToAnsi for response formatting

Both use `Theme` for colors and `KosmokratorTerminalTheme` for syntax highlighting via tempest/highlight.

## Development

```bash
composer install
php vendor/bin/phpunit          # Run tests
php vendor/bin/pint             # Code style (Laravel Pint)
```

### Config

Config loaded from `config/kosmokrator.yaml`, overridable via `~/.kosmokrator/config.yaml` or `.kosmokrator.yaml` in the working directory.

### Building a PHAR

```bash
php vendor/bin/box compile      # Uses box.json config
```

## Conventions

- PSR-4 autoloading: `Kosmokrator\` → `src/`
- Strict types everywhere
- No tool round limits — agent runs until the LLM finishes
- Tool approval required for: file_write, file_edit, bash (configurable in `config/kosmokrator.yaml`)
- Mythology-themed UI: planetary symbols for tool icons, mythological thinking phrases, cosmic spinner animations
- ANSI renderer uses league/commonmark for markdown parsing + tempest/highlight for code blocks
- TUI renderer uses Symfony TUI's MarkdownWidget with custom stylesheet
- PHPDoc on all public methods: `@param` with type + description, `@return` with type, one-line summary before params
- PHPDoc on classes: one-line summary of purpose, longer description if non-obvious
- No PHPDoc on trivial getters/setters or when signature is self-documenting
- Use `@var` annotations on typed properties only when the type needs clarification (e.g., array shapes)

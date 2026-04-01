<p align="center">

```
██╗  ██╗ ██████╗ ███████╗███╗   ███╗ ██████╗ ██╗  ██╗██████╗  █████╗ ████████╗ ██████╗ ██████╗
██║ ██╔╝██╔═══██╗██╔════╝████╗ ████║██╔═══██╗██║ ██╔╝██╔══██╗██╔══██╗╚══██╔══╝██╔═══██╗██╔══██╗
█████╔╝ ██║   ██║███████╗██╔████╔██║██║   ██║█████╔╝ ██████╔╝███████║   ██║   ██║   ██║██████╔╝
██╔═██╗ ██║   ██║╚════██║██║╚██╔╝██║██║   ██║██╔═██╗ ██╔══██╗██╔══██║   ██║   ██║   ██║██╔══██╗
██║  ██╗╚██████╔╝███████║██║ ╚═╝ ██║╚██████╔╝██║  ██╗██║  ██║██║  ██║   ██║   ╚██████╔╝██║  ██║
╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝    ╚═════╝ ╚═╝  ╚═╝
```

</p>

<p align="center">
    AI coding agent for the terminal
</p>

<p align="center">
    <img src="https://img.shields.io/packagist/php-v/kosmokrator/kosmokrator.svg?label=php" alt="PHP Version" />
    <img src="https://img.shields.io/packagist/l/kosmokrator/kosmokrator.svg" alt="License" />
</p>

---

KosmoKrator is a mythology-themed AI coding agent that runs in your terminal. It reads, writes, and edits files, searches your codebase, and executes shell commands — all with a permission system that keeps you in control.

Built with **PHP 8.4**, **Symfony Console**, and a dual renderer (interactive TUI / ANSI fallback). Supports multiple LLM providers via OpenAI-compatible APIs.

## Prerequisites

- **PHP 8.4+** with the `pcntl`, `posix`, and `mbstring` extensions
- **Composer 2.x**
- A terminal with ANSI color support (TUI mode requires a modern terminal like iTerm2, Kitty, or Windows Terminal)

## Quick Start

```bash
# Clone and install
git clone https://github.com/opencompany/kosmokrator.git
cd kosmokrator
composer install

# Configure your API key and provider
bin/kosmokrator setup

# Run
bin/kosmokrator                     # Auto-detects renderer (TUI if available, ANSI fallback)
bin/kosmokrator --renderer=ansi     # Force ANSI mode
bin/kosmokrator --no-animation      # Skip the animated intro
```

## Slash Commands

| Command | Description |
|---------|-------------|
| `/edit` | Full tool access — read, write, edit, search, bash |
| `/plan` | Read-only — explore and produce a detailed plan |
| `/ask` | Read-only — answer questions using file context |
| `/prometheus` | Auto-approve all tools until next prompt |
| `/reset` | Clear conversation history |
| `/clear` | Clear the screen |
| `/quit` | Exit KosmoKrator |

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
- `src/UI/` — Rendering layer with `RendererInterface`
  - `UI/Tui/` — Interactive Symfony TUI with widgets, Revolt event loop, multi-line editor
  - `UI/Ansi/` — Pure ANSI escape code fallback with Markdown-to-ANSI rendering
  - `UI/Theme.php` — Shared color palette, tool icons, context bar
- `src/Tool/` — Tool implementations (`Coding/`) and permission system (`Permission/`)
- `src/Command/` — AgentCommand (main REPL), SetupCommand

### Supported providers

Configured via `bin/kosmokrator setup` or `~/.kosmokrator/config.yaml`:

| Provider | Default model | Env var |
|----------|--------------|---------|
| Z.AI | GLM-5.1 | `ZAI_API_KEY` |
| Anthropic | Claude Sonnet 4 | `ANTHROPIC_API_KEY` |
| OpenAI | GPT-4o | `OPENAI_API_KEY` |
| Ollama | (local) | — |

Any OpenAI-compatible API can be added to `config/prism.yaml`.

### Permission system

Tools in `approval_required` (default: `file_write`, `file_edit`, `bash`) prompt before execution. Bash commands matching `blocked_commands` patterns are always denied. Session grants and `/prometheus` mode let you approve tools temporarily.

### Rendering

Two renderers implement `RendererInterface`:

- **TuiRenderer** — Full interactive TUI: editor widget with multi-line input, collapsible tool results, syntax highlighting, progress bar, slash command autocomplete, and message queuing during thinking
- **AnsiRenderer** — Pure ANSI fallback: readline input, CommonMark→ANSI markdown rendering, tempest/highlight syntax coloring

Both renderers share `Theme` for a consistent mythology-themed aesthetic — planetary tool icons, cosmic spinner animations, and mythological thinking phrases.

## Configuration

Config is loaded in layers (later overrides earlier):

1. `config/*.yaml` — bundled defaults
2. `~/.kosmokrator/config.yaml` — user config
3. `.kosmokrator.yaml` — project config (in working directory)

Environment variables (`${VAR_NAME}`) are resolved in all YAML files.

## Development

```bash
composer install
php vendor/bin/phpunit          # Run tests
php vendor/bin/pint             # Code style (Laravel Pint)
```

## Conventions

- PSR-4: `Kosmokrator\` → `src/`
- `declare(strict_types=1)` everywhere
- Agent runs until the LLM signals completion (no hard tool round limit)
- Tool approval required for mutating operations (configurable)
- Markdown responses rendered with league/commonmark + tempest/highlight

## License

MIT

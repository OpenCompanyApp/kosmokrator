# Permission Modes & Agent Modes

KosmoKrator has two orthogonal mode axes: **Agent Mode** controls which tools are available, **Permission Mode** controls how available tools get approved.

## Agent Modes

| Mode | Tools Available | Purpose |
|------|----------------|---------|
| **Edit** | All (read + write + bash + tasks) | Full code modification |
| **Plan** | Read-only (file_read, glob, grep, tasks) | Research and planning |
| **Ask** | Read-only (file_read, glob, grep, tasks) | Q&A, no modifications |

## Permission Modes

| Mode | Symbol | Behavior | Use Case |
|------|--------|----------|----------|
| **Guardian** | ◈ | Auto-approve safe ops, ask for risky ones | Day-to-day work (default) |
| **Argus** | ◉ | Ask for every write and bash command | Sensitive repos, first-time use |
| **Prometheus** | ⚡ | Auto-approve everything | Long autonomous runs |

Blocked paths and blocked commands are **always enforced** regardless of permission mode.

## Composition Matrix

Permission mode only matters in Edit mode. In Plan/Ask, all available tools are read-only so no approval is needed.

|  | **Guardian** ◈ | **Argus** ◉ | **Prometheus** ⚡ |
|--|:-:|:-:|:-:|
| **Edit** | Smart auto-approve: reads auto, project-local writes auto, safe bash auto, rest asks | Ask for every file_write, file_edit, bash | Auto-approve all tool calls |
| **Plan** | *(no approval needed — read-only tools only)* | *(no approval needed)* | *(no approval needed)* |
| **Ask** | *(no approval needed — read-only tools only)* | *(no approval needed)* | *(no approval needed)* |

## Guardian Heuristics

Guardian mode uses deterministic static analysis (no LLM call) to score each tool call:

| Tool | Auto-approve | Ask |
|------|-------------|-----|
| `file_read` | Always | Never |
| `glob` | Always | Never |
| `grep` | Always | Never |
| `task_*` | Always | Never |
| `file_write` | Path inside project root | Path outside project root |
| `file_edit` | Path inside project root | Path outside project root |
| `bash` | Command matches safe whitelist | Command not on whitelist |

### Safe Command Whitelist

Configurable in `config/kosmokrator.yaml` under `tools.guardian_safe_commands`. Defaults:

```
git *, ls *, pwd, cat *, head *, tail *, wc *, find *, which *, echo *,
php vendor/bin/phpunit*, php vendor/bin/pint*, composer *, npm *, node *,
python *, cargo *, go *, make *
```

## Deny Hierarchy

Regardless of permission mode, denials are absolute and follow this order:

1. **Blocked paths** (`tools.blocked_paths`) — checked first, overrides everything
2. **Blocked commands** (`tools.bash.blocked_commands`) — checked via PermissionRule deny patterns
3. **Permission mode** — Guardian/Argus/Prometheus logic
4. **Session grants** — per-tool "always allow" from user approval

Deny results include reasons: `"Cannot access '.env' — matches blocked pattern '*.env'"`.

## Approval Popup

When a tool requires approval (Argus mode, or Guardian for risky ops), the popup offers mode escalation:

```
Allow?  ◉ file_edit  src/Foo.php

  Allow              Execute this tool call
  Always Allow       Allow this tool for the session
  → Guardian ◈       Switch to smart auto-approve
  → Prometheus ⚡     Switch to auto-approve all
  Deny               Block and tell the LLM
```

Selecting Guardian or Prometheus switches mode for the session AND approves the current call.

## Commands

```
/edit, /plan, /ask           — switch agent mode
/guardian, /argus, /prometheus — switch permission mode
```

## Status Bar

```
Edit · Guardian ◈ · z/GLM-5.1 · 12k/200k · $0.02
Plan · z/GLM-5.1 · 12k/200k · $0.02          (permission mode hidden — irrelevant)
```

## Implementation

- `src/Tool/Permission/PermissionMode.php` — Enum (Guardian/Argus/Prometheus)
- `src/Tool/Permission/GuardianEvaluator.php` — Static risk analysis
- `src/Tool/Permission/PermissionResult.php` — Value object (action + reason + autoApproved)
- `src/Tool/Permission/PermissionEvaluator.php` — Core evaluation with mode-aware logic
- `src/Tool/Permission/PermissionRule.php` — Glob matching via `matchesGlob()`
- `config/kosmokrator.yaml` — `guardian_safe_commands`, `default_permission_mode`, `blocked_paths`

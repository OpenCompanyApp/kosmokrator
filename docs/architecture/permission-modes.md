# Permission Modes & Agent Modes

KosmoKrator has two orthogonal control axes:

- **Agent mode** decides which tools are available
- **Permission mode** decides how governed tool calls are approved

## Agent Modes

| Mode | Available tool families | Purpose |
|------|-------------------------|---------|
| **Edit** | read, write, edit, search, bash, shell sessions, subagent, task, ask-user, memory, session, Lua tools | Full coding access |
| **Plan** | read, search, bash, shell sessions, subagent, task, ask-user, memory search, session, Lua tools | Research and planning without file edits |
| **Ask** | read, search, bash, shell sessions, task, ask-user, memory search, session, Lua docs tools | Q&A without file edits, subagents, or Lua execution |

Important behavior:

- `file_write`, `file_edit`, and `apply_patch` are unavailable outside `Edit`
- `subagent` and `execute_lua` are unavailable in `Ask`
- `bash` is available in all three interactive modes
- `Ask` adds an extra read-only guard: mutative bash commands are blocked even if permission mode is permissive

## Permission Modes

| Mode | Symbol | Behavior |
|------|--------|----------|
| **Guardian** | ◈ | Auto-approve known-safe calls, ask for riskier governed calls |
| **Argus** | ◉ | Ask for every governed call |
| **Prometheus** | ⚡ | Auto-approve governed calls unless an absolute deny rule matches |

Governed calls come from the configured approval rules. By default that includes `file_write`, `file_edit`, `apply_patch`, `bash`, `shell_start`, `shell_write`, and `execute_lua`.

## How They Compose

| Agent mode | Permission behavior |
|-----------|---------------------|
| **Edit** | Full permission system applies to writes and bash |
| **Plan** | No file mutation tools exist, but bash still goes through permission evaluation |
| **Ask** | No file mutation tools exist; bash still goes through permission evaluation, and mutative bash is denied by the mode guard |

## Guardian Heuristics

Guardian uses static checks only. Current auto-approve rules are:

| Tool | Auto-approve behavior |
|------|------------------------|
| `file_read`, `glob`, `grep` | always auto-approved |
| `task_*` | always auto-approved |
| `shell_read`, `shell_kill` | always auto-approved |
| `memory_search`, `memory_save`, `session_search`, `session_read` | always auto-approved |
| `lua_list_docs`, `lua_search_docs`, `lua_read_doc` | always auto-approved |
| `file_write`, `file_edit` | auto-approved only when the resolved path is inside the project root |
| `bash` | auto-approved only when the command matches the safe-command whitelist and contains no shell operators |
| `shell_start`, `shell_write` | auto-approved only when the command/input matches the safe-command whitelist and contains no shell operators |
| `execute_lua` | auto-approved in Guardian after absolute denies and boundary checks; inner integration and MCP permissions still apply |

Blocked paths and blocked command patterns always win, regardless of permission mode.

### Safe bash patterns

Configured in `config/kosmokrator.yaml` under `tools.guardian_safe_commands`.

Representative defaults:

```text
git *
ls *
pwd
cat *
head *
tail *
wc *
find *
which *
echo *
php vendor/bin/phpunit*
php vendor/bin/pint*
composer *
npm *
node *
python *
cargo *
go *
make *
```

Commands containing shell operators such as `;`, `&&`, `|`, redirection, command substitution, or embedded newlines are not treated as safe.

## Evaluation Order

The permission evaluator applies rules in this order:

1. blocked paths
2. blocked command patterns
3. project boundary checks
4. session grants for the tool name
5. rule evaluation for `allow`, `ask`, or `deny`
6. permission-mode override (`Guardian`, `Argus`, `Prometheus`)

Implications:

- session grants can bypass future `ask` results for the same tool
- session grants do not bypass absolute deny rules or project boundary prompts
- `Prometheus` only upgrades `ask` to `allow`; it does not override denies

## Approval Flow

When approval is required, the UI can:

- allow just this call
- allow this tool for the rest of the session
- escalate to `Guardian`
- escalate to `Prometheus`
- deny the call

Changing to `Guardian` or `Prometheus` applies to the current session immediately and approves the current prompt flow.

## Related Commands

```text
/edit /plan /ask
/guardian /argus /prometheus
```

## Implementation References

- `src/Agent/AgentMode.php`
- `src/Tool/Permission/PermissionMode.php`
- `src/Tool/Permission/PermissionEvaluator.php`
- `src/Tool/Permission/GuardianEvaluator.php`
- `config/kosmokrator.yaml`

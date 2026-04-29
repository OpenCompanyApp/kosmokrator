# User Telemetry and Crash Diagnostics Plan

> Status: Plan for review.
> Scope: Add opt-in user usage telemetry and crash diagnostics so OpenCompany can identify reliability issues in real KosmoKrator installs.
> Non-scope: Developer-only local profiling, raw prompt capture, raw tool output capture, or mandatory analytics.

## Goal

KosmoKrator needs a privacy-preserving way to understand crashes, provider failures, startup failures, renderer problems, and broad usage patterns across user installs.

The system should help answer:

- Which KosmoKrator versions crash most often?
- Which commands, renderers, providers, models, tools, and OS/PHP combinations fail most often?
- Are failures concentrated in TUI mode, ANSI mode, setup, provider streaming, subagents, shell sessions, web providers, MCP, or integrations?
- Which user-facing features are actually used enough to prioritize stabilization?
- Did a release introduce a regression?

OpenTelemetry is useful here as a standard event/trace/log export format, but it should not be the user-facing product analytics system by itself. KosmoKrator should send sanitized telemetry to an OpenCompany ingestion endpoint, and that endpoint can forward data to OpenTelemetry-compatible infrastructure and crash-reporting tools.

## Principles

- Telemetry is opt-in by default.
- The app never embeds vendor write tokens for Grafana, Honeycomb, Sentry, Axiom, or similar services.
- User installs send telemetry only to an OpenCompany-controlled ingestion endpoint.
- The endpoint performs authentication, rate limiting, schema validation, redaction, sampling, and routing.
- Default telemetry is metadata-only.
- Prompts, model responses, tool outputs, command output, file contents, environment variables, API keys, hostnames, usernames, project names, and full paths are never sent by default.
- Full payload capture, if ever added, must require a separate explicit local-debug setting and should not be part of public default telemetry.
- The telemetry pipeline must be fail-closed for user experience: telemetry failures must never block agent work, input, rendering, or command execution.

## Recommended Architecture

```text
KosmoKrator user install
  -> TelemetryClient
  -> sanitizer + local queue
  -> https://telemetry.opencompany.ai/v1/events
  -> ingestion service
  -> OpenTelemetry Collector
  -> usage/error analytics backend
  -> crash grouping backend
```

The ingestion endpoint should be the trust boundary. It allows OpenCompany to change vendors, sampling, storage, retention, and routing without shipping a new CLI version or exposing third-party credentials.

## Data Classes

### Allowed by Default

- anonymous install id
- KosmoKrator version
- release channel
- PHP version
- OS family and architecture
- renderer: `tui` or `ansi`
- command name
- provider name
- model name
- tool name
- MCP/integration package name
- normalized error class
- sanitized error message
- sanitized stack trace frames
- duration, retry count, exit code, and status
- token counts and estimated cost when already available
- subagent counts, max depth, and aggregate statuses
- memory peak and current RSS buckets
- startup and shutdown reason

### Blocked by Default

- prompts
- model responses
- tool outputs
- shell command arguments
- command stdout/stderr
- file contents
- file paths, except coarse extension/category or salted hash
- project names
- git remotes
- usernames
- hostnames
- environment variables
- config values
- API keys, tokens, cookies, and credentials
- MCP payload bodies

## Event Schema

Use a small stable envelope instead of shipping arbitrary app internals.

```json
{
  "schema_version": "1.0",
  "event_id": "uuid",
  "event_type": "crash",
  "occurred_at": "2026-04-29T20:00:00Z",
  "anonymous_id": "uuid",
  "session_id": "uuid",
  "release": "0.7.1",
  "platform": {
    "os": "darwin",
    "arch": "arm64",
    "php": "8.4.6"
  },
  "runtime": {
    "renderer": "tui",
    "mode": "edit",
    "headless": false
  },
  "context": {
    "command": "agent",
    "provider": "anthropic",
    "model": "claude-sonnet-4-5",
    "tool": "subagent"
  },
  "measurements": {
    "duration_ms": 1240,
    "retry_count": 2,
    "memory_peak_mb": 418
  },
  "error": {
    "class": "RuntimeException",
    "message": "sanitized message",
    "fingerprint": "stable-hash",
    "stack": []
  }
}
```

## Event Types

### Reliability Events

- `app.startup`
- `app.shutdown`
- `app.crash`
- `command.failed`
- `llm.failed`
- `llm.stream_failed`
- `provider.failed`
- `tool.failed`
- `subagent.failed`
- `subagent.exhausted_retries`
- `mcp.failed`
- `integration.failed`
- `renderer.failed`
- `tui.input_stall`
- `event_loop.stall`

### Usage Events

- `command.started`
- `command.completed`
- `renderer.selected`
- `provider.selected`
- `model.selected`
- `tool.called`
- `subagent.spawned`
- `web_provider.used`
- `mcp_server.used`
- `integration.used`
- `slash_command.used`
- `setup.completed`

### Aggregate Metrics

- session duration
- command duration
- LLM latency
- streaming duration
- tool duration
- retry count
- token counts
- estimated cost
- subagent counts by status
- memory peak bucket

## Configuration

Add a telemetry config block:

```yaml
telemetry:
  enabled: false
  endpoint: https://telemetry.opencompany.ai/v1/events
  anonymous_id: auto
  crash_reports: true
  usage_events: true
  performance_traces: false
  sample_rate: 1.0
  crash_sample_rate: 1.0
  include_prompts: false
  include_tool_outputs: false
  include_shell_output: false
  local_queue: true
  local_queue_max_mb: 10
```

Environment overrides:

```bash
KOSMO_TELEMETRY=1
KOSMO_TELEMETRY_ENDPOINT=https://telemetry.opencompany.ai/v1/events
KOSMO_TELEMETRY_DISABLED=1
```

## Consent UX

Telemetry should be opt-in during setup and adjustable later.

Setup prompt:

```text
Help improve KosmoKrator by sending anonymous crash reports and usage diagnostics?

Sent: version, OS/PHP, renderer, command names, provider/model names, tool names,
durations, retry counts, sanitized errors, and aggregate usage counts.

Never sent by default: prompts, model responses, file contents, shell output,
environment variables, API keys, project names, hostnames, usernames, or git remotes.

[Yes] [No]
```

Commands:

```bash
bin/kosmokrator telemetry status
bin/kosmokrator telemetry enable
bin/kosmokrator telemetry disable
bin/kosmokrator telemetry inspect-last
bin/kosmokrator telemetry flush
```

`inspect-last` should print the exact sanitized payload that would be sent. This makes the privacy model auditable by users.

## Backend Plan

### Ingestion Endpoint

Build a small OpenCompany service with:

- HTTPS-only event intake.
- schema validation.
- event size limits.
- gzip support.
- anonymous install id handling.
- release/version validation.
- IP-based and install-id-based rate limits.
- server-side redaction pass.
- sampling controls by event type, version, platform, and release channel.
- dead-letter storage for invalid payloads without sensitive fields.

### Routing

Route data by type:

- crashes and exceptions to Sentry or a Sentry-compatible crash backend.
- OTel logs/events to OpenTelemetry Collector.
- metrics to Prometheus/Mimir/Grafana Cloud or equivalent.
- traces to Tempo/Honeycomb/Axiom only when `performance_traces` is enabled or sampled.

### Retention

Suggested retention:

- crash groups: 180 days.
- aggregate metrics: 365 days.
- raw event payloads: 30 days.
- high-cardinality debug traces: 7 days.

## Implementation Phases

## Phase 1: Local Telemetry Abstraction

### Fix

- Add `TelemetryClientInterface`.
- Add `NullTelemetryClient` as the default.
- Add `SanitizingTelemetryClient` decorator.
- Add `QueuedTelemetryClient` for non-blocking local buffering.
- Add `TelemetryConfig` value object.
- Wire telemetry through `AgentSessionBuilder` and command setup.

### Capture Points

- command start/completion/failure
- startup smoke failure
- uncaught exception handler
- provider/LLM failure
- tool failure
- subagent failure
- renderer failure

### Expected Impact

The codebase gets stable instrumentation points without committing to a vendor or network transport yet.

### Risk

The abstraction can become too broad. Keep it event-oriented and avoid leaking OTel SDK types through core agent code.

## Phase 2: Sanitizer and Privacy Tests

### Fix

- Add a central `TelemetrySanitizer`.
- Redact likely secrets from messages and stack traces.
- Normalize paths to extension/category/hash instead of raw paths.
- Strip command args from shell tool telemetry by default.
- Add tests for API keys, bearer tokens, env vars, home paths, git remotes, hostnames, usernames, and file contents.
- Add a snapshot-style test for every public event type.

### Expected Impact

Telemetry becomes safe enough to expose as an opt-in user feature.

### Risk

No sanitizer is perfect. This is why prompts, outputs, command bodies, and config values should be excluded structurally before the sanitizer runs.

## Phase 3: Consent, Config, and CLI Controls

### Fix

- Add setup prompt.
- Persist user choice in KosmoKrator config.
- Add `telemetry status|enable|disable|inspect-last|flush`.
- Document every collected field in website docs.
- Show telemetry state in diagnostics output.

### Expected Impact

Users can understand, verify, enable, and disable telemetry without editing YAML manually.

### Risk

Consent copy must be exact and conservative. Do not imply that no data is sent if optional crash reports are enabled.

## Phase 4: Network Transport

### Fix

- Add async fire-and-forget HTTP transport.
- Use short connect and request timeouts.
- Batch events.
- Compress payloads.
- Retry only from the local queue with capped disk usage.
- Drop telemetry silently when offline or disabled.
- Never block user input, rendering, tool calls, or shutdown on telemetry.

### Expected Impact

Enabled installs can send diagnostics without affecting the terminal experience.

### Risk

Long-running swarms can generate too many events. Add per-session and per-event-type rate limits before enabling broad usage telemetry.

## Phase 5: OpenCompany Ingestion Service

### Fix

- Build `/v1/events` endpoint.
- Validate schema and auth.
- Apply rate limits.
- Apply server-side redaction.
- Add dashboards for intake health.
- Forward to OTel Collector and crash backend.
- Add release-aware routing so prerelease builds can be sampled more heavily.

### Expected Impact

OpenCompany controls privacy, routing, and vendor choice. The CLI does not need embedded third-party credentials.

### Risk

The ingestion endpoint becomes operational infrastructure. Keep the first version boring and small.

## Phase 6: Crash Grouping and Release Regression Views

### Fix

- Compute stable error fingerprints from error class, sanitized top frame, command, renderer, and provider/tool context.
- Attach release version and git commit when available.
- Build dashboards for:
  - crashes by release
  - startup failure rate
  - provider failure rate
  - renderer failure rate
  - command failure rate
  - tool failure rate
  - subagent failure rate
  - memory peak distribution
- Add alerting for new crash fingerprints and release regressions.

### Expected Impact

Telemetry becomes directly actionable for maintenance.

### Risk

Bad fingerprints can over-group unrelated issues or split one issue into many groups. Start simple and tune after real data appears.

## Phase 7: Optional OpenTelemetry Export

### Fix

- Add server-side forwarding to OpenTelemetry Collector.
- Keep OTel SDK usage out of the main app unless there is a strong reason to emit native OTLP directly.
- If native OTLP is added later, keep it behind config and send only to the OpenCompany endpoint or user-specified local endpoint.

### Expected Impact

OpenCompany gets standard traces/logs/metrics without tying the CLI code to one observability vendor.

### Risk

Native OTel dependencies can add install complexity to a PHAR CLI. Prefer the ingestion endpoint and server-side OTel conversion first.

## Minimal First Release

Ship this first:

- `telemetry.enabled: false`
- setup opt-in prompt
- crash reports
- command failed/completed events
- provider and tool failure events
- sanitizer with tests
- local queue
- `telemetry inspect-last`
- ingestion endpoint
- Sentry crash routing
- basic dashboard by release/version/platform

Defer:

- detailed traces
- high-cardinality performance events
- prompt/tool-output debug capture
- direct user-configured OTLP exporters
- complex product analytics funnels

## Acceptance Criteria

- A user can enable or disable telemetry from setup, config, env, and CLI.
- `telemetry inspect-last` shows exactly what would be sent.
- Telemetry failures cannot block normal KosmoKrator work.
- Default telemetry contains no prompts, responses, tool outputs, shell output, env vars, credentials, project names, hostnames, usernames, git remotes, or file contents.
- Tests cover sanitizer behavior for common secret and path patterns.
- Crash events are grouped by release and fingerprint.
- Usage events are queryable by release, OS, PHP version, renderer, command, provider, model, tool, and status.
- Documentation explains what is collected, what is not collected, where it is sent, and how to opt out.

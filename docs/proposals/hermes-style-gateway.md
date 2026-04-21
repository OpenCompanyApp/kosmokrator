# Hermes-Style Gateway for KosmoKrator

> Status: Proposal. This document describes a Hermes-style external gateway surface for KosmoKrator, starting with Telegram. It is forward-looking and does not describe shipped behavior.

## Overview

KosmoKrator is currently a terminal-native agent. The next external surface should follow the Hermes model: a real gateway runtime with platform adapters, normalized inbound events, deterministic session routing, streamed outbound updates, and async approval handling.

This proposal explicitly does **not** reuse Chatogrator as the core architecture. Chatogrator is useful as a source of Telegram transport ideas, but its Laravel webhook/request model is the wrong center of gravity for KosmoKrator.

The target is:

```text
Telegram / other chat platforms
            │
     Gateway adapter layer
            │
   Normalized gateway events
            │
  Session router + approval bridge
            │
 KosmoKrator agent runtime (AgentLoop)
            │
  ToolExecutor / PermissionEvaluator
            │
  TUI / ANSI / Gateway outbound renderer
```

The gateway is a new surface over the existing engine, not a separate product and not a second agent core.

## Why Hermes-Style

Hermes gets the important architectural boundary right:

- platform adapters are isolated from the core agent
- sessions are keyed from platform/chat/thread identity
- the runtime supports polling or webhook transport
- outbound responses are streamed and edited in place
- approvals can be resolved asynchronously from the chat surface

That matches KosmoKrator's needs far better than a Laravel controller-oriented webhook package.

## Goals

- Add a first-class Telegram surface for KosmoKrator.
- Reuse the existing agent core, tool execution, permission modes, Lua bridge, and session persistence.
- Preserve KosmoKrator's security model instead of bypassing it with a separate bot-side permission layer.
- Make external chat sessions feel native: streamed replies, approvals, resumable threads, and session continuity.
- Design the gateway so additional platforms can follow the same adapter contract later.

## Non-Goals

- Rebuilding Chatogrator inside KosmoKrator.
- A generic 16-platform gateway in v1.
- Replacing the terminal UI.
- Inventing a second session store separate from KosmoKrator's SQLite database.
- Making Telegram the source of truth for settings, permissions, or agent state.

## Principles

1. One agent core, many surfaces.
2. Gateway policy must flow through existing permission and mode logic.
3. Session identity must be deterministic and explicit.
4. Platform-specific concerns belong in adapters, not in `AgentLoop`.
5. Polling-first is acceptable for v1; webhook mode is an optimization.
6. Streaming and edit-in-place matter more than fancy commands in the first release.

## Current State

KosmoKrator already has the pieces needed behind the gateway boundary:

- agent loop and session assembly in [AgentLoop.php](/Users/rutger/Projects/kosmokrator/src/Agent/AgentLoop.php) and [AgentSessionBuilder.php](/Users/rutger/Projects/kosmokrator/src/Agent/AgentSessionBuilder.php)
- session persistence in [SessionManager.php](/Users/rutger/Projects/kosmokrator/src/Session/SessionManager.php)
- permission evaluation in [PermissionEvaluator.php](/Users/rutger/Projects/kosmokrator/src/Tool/Permission/PermissionEvaluator.php)
- tool orchestration in [ToolExecutor.php](/Users/rutger/Projects/kosmokrator/src/Agent/ToolExecutor.php)
- mode and permission controls through slash commands and settings

What is missing is the gateway layer:

- external event ingestion
- external session routing
- outbound chat rendering
- async approval callbacks
- platform transport lifecycle

## Feature Map

### Core Feature Map

| Capability | Hermes | Kosmo Today | Proposed Kosmo Gateway |
|---|---|---|---|
| Platform adapters | Yes | No | Yes |
| Telegram polling | Yes | No | V1 |
| Telegram webhook | Yes | No | V2 |
| Session routing by chat/thread/user | Yes | No | V1 |
| Streamed response edits | Yes | No | V1 |
| Inline approval flow | Yes | No | V2 |
| Async approval timeout handling | Yes | No | V2 |
| Reactions / typing indicators | Yes | No | V2 |
| Media ingestion | Yes | No | V2 |
| Forum topic routing | Yes | No | V3 |
| Multi-platform gateway | Yes | No | V3+ |
| Gateway model picker | Yes | No | V2 |
| Gateway command registry | Yes | No | V1 |

### Telegram v1 Feature Map

| Feature | v1 | Notes |
|---|---|---|
| Bot token config | Yes | Stored in Kosmo settings |
| Allowed users/chats | Yes | Required for first release |
| Polling worker | Yes | Simplest reliable start |
| DM support | Yes | One session per user chat |
| Group mention gating | Yes | Mention or reply-to-bot |
| Thread/topic-aware routing | Partial | Support message threads when present |
| Streamed response editing | Yes | One visible in-progress message |
| Plain text attachments summary | Yes | Text-first fallback |
| Approval via text commands | Yes | `/approve`, `/deny`, `/status` |
| Inline keyboards | No | V2 |
| Reactions | No | V2 |
| Voice/audio input | No | V3 |
| Photos/files upload handling | Limited | Metadata in v1, rich handling later |

### Telegram v2 Feature Map

| Feature | v2 | Notes |
|---|---|---|
| Webhook mode | Yes | Optional deployment optimization |
| Inline approval buttons | Yes | Bridges to permission requests |
| Typing indicators | Yes | While agent is thinking or streaming |
| Rich media sending | Yes | Files, images, rendered artifacts |
| Gateway model selector | Yes | Limited to safe per-session overrides |
| Better group routing | Yes | Wake words, allowlisted free-response chats |
| Approval expiration and fallback | Yes | Important for unattended sessions |

## Architecture

### New Boundary

Introduce a gateway subsystem under a new namespace, for example:

```text
src/Gateway/
├── GatewayManager.php
├── GatewayRunner.php
├── Event/
│   ├── GatewayEvent.php
│   ├── MessageEvent.php
│   ├── ActionEvent.php
│   └── ApprovalEvent.php
├── Session/
│   ├── GatewaySessionKey.php
│   ├── GatewaySessionRouter.php
│   └── GatewaySessionContext.php
├── Platform/
│   ├── PlatformAdapterInterface.php
│   └── Telegram/
│       ├── TelegramAdapter.php
│       ├── TelegramClient.php
│       ├── TelegramUpdateNormalizer.php
│       ├── TelegramOutboundRenderer.php
│       └── TelegramPollingWorker.php
└── Approval/
    ├── GatewayApprovalBridge.php
    ├── PendingApprovalStore.php
    └── ApprovalResolution.php
```

The gateway should feed normalized events into the existing agent runtime rather than creating a new execution path.

### Session Routing

Kosmo needs deterministic external session keys. A good key shape is:

```text
telegram:{chat_id}
telegram:{chat_id}:{thread_id}
telegram:{chat_id}:{thread_id}:{user_id}
```

Routing policy depends on chat type:

- private chat: `telegram:{user_chat_id}`
- group with reply/mention mode: shared thread session by `chat_id`
- forum topics: `chat_id:thread_id`
- if we later need per-user isolation in shared groups, add `user_id`

These keys should map onto Kosmo's existing session persistence, not bypass it.

### Gateway Conversation Flow

1. Telegram update arrives.
2. Adapter validates source and normalizes it to `MessageEvent`.
3. Session router computes a gateway session key.
4. Gateway runner loads or creates the linked Kosmo session.
5. Gateway outbound renderer posts an initial placeholder message.
6. AgentLoop runs using the same session, tools, permissions, and settings as terminal Kosmo.
7. Stream chunks update the Telegram message in place.
8. Tool approvals route through the approval bridge.
9. Final response is committed to session history and rendered as a stable Telegram message.

## Telegram-Specific Design

### v1 Inbound Rules

- private chats are accepted if the sender is allowlisted
- groups require either:
  - direct mention of the bot, or
  - reply to a bot message
- commands are parsed first and may short-circuit the agent loop

### v1 Commands

The gateway should not duplicate the full slash command surface. Start with a small command registry:

| Command | Purpose |
|---|---|
| `/help` | Gateway-specific help |
| `/new` | Start a fresh linked Kosmo session |
| `/resume` | Reuse the current session |
| `/approve` | Approve the latest pending tool request |
| `/deny` | Deny the latest pending tool request |
| `/status` | Show current mode, model, session id, pending approvals |
| `/cancel` | Cancel the active run for this session |

This registry should be separate from CLI slash commands, but conceptually similar.

### Outbound Rendering

Telegram should get a gateway-specific renderer, not reused ANSI/TUI output.

The renderer should support:

- one editable in-progress message for streamed content
- compact tool status lines for long operations
- completion summary for long-running tasks
- approval prompts rendered as plain text in v1
- artifact/file uploads later in v2

The renderer should compress noisy terminal-only details. Telegram is not the place to mirror raw TUI phase state or verbose tool banners.

## Permissions and Approvals

The gateway must reuse Kosmo permission modes, not invent a separate one.

Rules:

- `guardian`: deny or require explicit user approval via chat
- `argus`: same underlying behavior as terminal Argus, but approval resolved over Telegram
- `prometheus`: auto-allow where Kosmo already would auto-allow

Required bridge behavior:

- when a tool requires approval, pause the gateway run
- persist the pending approval with session and message linkage
- post a concise approval request into Telegram
- resume the waiting run when approval is granted or denied

This is the main runtime difference between terminal and gateway surfaces.

## Settings and Config

Gateway configuration should live in Kosmo settings, not a separate YAML silo.

Suggested top-level settings:

```yaml
gateway:
  telegram:
    enabled: false
    token: null
    polling: true
    webhook_url: null
    allowed_users: []
    allowed_chats: []
    require_mention: true
    free_response_chats: []
    home_chat_id: null
```

These should be surfaced in `/settings`, likely in a new `Gateway` category rather than hidden under `Integrations`.

## Data Model

Minimal additional persistence:

- gateway session linkage
- pending approval records
- outbound message mapping for edit-in-place
- optional platform checkpoint/cursor state

Suggested new tables:

| Table | Purpose |
|---|---|
| `gateway_sessions` | maps platform/chat/thread identity to Kosmo session ids |
| `gateway_messages` | tracks outbound platform message ids for updates |
| `gateway_approvals` | pending and resolved approval requests |
| `gateway_checkpoints` | polling offsets or webhook bookkeeping if needed |

## Implementation Plan

### Phase 0: Preparation

- Introduce `src/Gateway/` namespace and service provider wiring.
- Define normalized gateway event types.
- Define gateway session key and persistence model.
- Add settings schema for Telegram configuration.
- Add a small internal command registry for gateway commands.

### Phase 1: Telegram MVP

- Polling worker for Telegram updates.
- Allowlist and mention/reply gating.
- Session router backed by SQLite.
- Gateway renderer with streamed text updates.
- `/help`, `/new`, `/status`, `/cancel`.
- Reuse existing session, mode, model, and permission defaults.

Exit criteria:

- a user can converse with Kosmo from Telegram
- responses stream cleanly into one message
- chat sessions resume correctly
- no unsafe tool bypass exists

### Phase 2: Approval Bridge and Better Controls

- Pending approval persistence and resume.
- `/approve` and `/deny`.
- Inline approval buttons.
- Better progress/status updates for long tool runs.
- Model override selection per gateway session.
- Better command routing and per-chat policy configuration.

Exit criteria:

- guarded tools can be approved asynchronously from Telegram
- resumed runs continue in the same agent turn cleanly
- gateway sessions can be used without the terminal open

### Phase 3: Rich Telegram Surface

- file and image uploads
- generated artifact sending
- typing indicators and reactions
- better thread/topic support
- voice note ingestion
- home-chat notifications for long-running tasks

### Phase 4: Generalized Platform Layer

- make the adapter contract stable enough for Discord/Slack/Signal later
- move Telegram-specific assumptions out of shared gateway code
- keep transport-specific rendering logic inside the adapter package

## Testing Strategy

### Unit Tests

- session key generation
- mention/reply gating rules
- command parsing
- approval state transitions
- Telegram update normalization
- outbound renderer chunk/edit behavior

### Integration Tests

- one full gateway conversation against fake Telegram updates
- approval-required tool run pause/resume
- session reuse across multiple inbound messages
- cancel and timeout handling

### Manual Validation

- DM conversation
- group mention flow
- approval flow in Guardian/Argus
- long streamed response editing
- process restart with resumed session routing

## Risks

| Risk | Why it matters | Mitigation |
|---|---|---|
| Blocking agent runs tie up poll loop | Polling worker can stall | Separate transport loop from run execution |
| Chat flooding during long streams | Telegram edit limits and UX | Throttle edits and coalesce chunks |
| Approval deadlocks | External runs may wait forever | Persist approval state with timeout and cancellation |
| Session confusion in groups | Wrong replies in shared chats | Deterministic session key strategy and explicit routing rules |
| Leaking terminal-oriented output | Telegram UX becomes noisy | Dedicated gateway renderer |

## Not In v1

- multi-platform support beyond Telegram
- forum-topic automation rules
- complex pairing flows
- gateway-side OAuth credential management
- advanced media understanding
- bot-driven swarm dashboards

## Recommendation

Build Kosmo's first gateway the Hermes way:

- adapter-based
- polling-first
- session-routed
- streamed
- approval-aware

Do not center the implementation on Chatogrator. If we reuse anything from that package, it should be narrow Telegram transport or rendering logic only, not the package architecture.

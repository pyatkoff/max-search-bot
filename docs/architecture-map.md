# AnyTour MAX architecture map

This document is the current incremental architecture map for `max-search-bot`. It is intentionally descriptive and migration-oriented: it does **not** prescribe a big-bang rewrite.

## Target flow

`Transport adapters → normalized incoming event → InteractionGuard → DialogueStateMachine → NeedValueResolver → NeedApplicationService → DialogueView → Search/Handoff`

Cross-cutting lifecycle events feed diagnostics, audit and handoff timelines. Manager business workflow remains separate from technical conversation state.

## Boundary rules

- Technical conversation states (`ai`, `waiting_manager`, `manager`, `closed`) are system-owned and are not sales stages.
- Sales pipeline state, tags, outcomes, sale data and tasks stay behind sales/task services and manager APIs.
- Dialogue state has one canonical owner. New handlers/actions should delegate state transition and deterministic need parsing instead of adding parallel status/value rules.
- Authorization and project/source visibility are backend concerns. UI code may hide controls but must not be the security boundary.
- Schema changes are forward-only migrations. Runtime DDL is not allowed outside migration infrastructure.
- Transport-specific code should normalize input/output and should not own business rules.
- Manager shift state, routing bonuses, Metrika goals and existing lead delivery are protected behavior and are not architecture-cleanup targets.

## Current ownership map

| Area | Current owner(s) to KEEP | Convergence direction |
| --- | --- | --- |
| Incoming MAX/TG transport | `handlers/MaxUpdateHandler.php`, `handlers/TelegramWebhookHandler.php`, transport/integration services | Keep adapters thin; normalize before business decisions. |
| Callback safety | `InteractionGuard`, callback action layer | Move remaining callback debounce/stale/generation decisions behind the guard. |
| Dialogue transitions | `DialogueStateMachine`, `DialogueController` | Route scattered status checks incrementally through the state machine when behavior is covered. |
| Deterministic need parsing | `NeedValueResolver` plus field parsers such as `NightsParser` / meal/date resolvers | Keep one deterministic resolver path per field; AI is fallback, not parallel state owner. |
| Need mutation/progression | `NeedApplicationService` and canonical progression helpers | Merge duplicated handler-side application/progression into this boundary in small slices. |
| Dialogue rendering | `DialogueView` and focused view helpers | Keep rendering separate from state mutation; split only when a concrete responsibility is stable. |
| Search / Tourvisor | search services and `Tourvisor*` integration boundary | Keep remote API concerns out of dialogue state ownership. |
| Handoff lifecycle | manager request/routing/push services + conversation lifecycle events | Keep request → selection → push → take → reply explainable and observable. |
| Manager visibility/auth | `ManagerAuthService`, `ProjectAccessService`, `RoutingAccessService` | Centralize authorization/visibility decisions; UI remains a consumer. |
| Manager Inbox projection | `ManagerConversationService`, `ManagerLeadInboxService` | Keep read projection separate from business mutations; extract query/policy helpers only when reuse appears. |
| Sales pipeline | `SalesPipelineService`, catalog/admin services | Keep independent from technical conversation status. |
| Tasks/reminders | `LeadTaskService` | Keep due/urgency semantics canonical here; Inbox/Kanban only project them. |
| Manager UI | `manager/index.php` plus `workspace-v2-*` CSS/JS modules | Continue module split (inbox, conversation, lead card, pipeline, media, notifications, mobile); do not rebuild in a framework without a concrete gain. |
| Migrations | `migrations/*.sql`, `MigrationRunner` | Forward-only immutable history; checksum-safe deploys. |
| Diagnostics | production/live/handoff/architecture snapshot tools + diagnostics branch | Prefer structured bounded evidence over ad-hoc logs. |
| Tests | required groups + behavior regressions + live regression fixtures | Test behavior and ownership contracts; avoid tests that merely freeze file layout without architectural value. |

## KEEP / MERGE / MOVE / DELETE

### KEEP

- Existing PHP backend and incremental service extraction strategy.
- `InteractionGuard`, `DialogueStateMachine`, `NeedValueResolver`, `NeedApplicationService` as convergence boundaries for dialogue core.
- `LeadTaskService` as task urgency/due-state owner.
- `SalesPipelineService` as business pipeline state/validation owner.
- `ManagerAuthService`, `ProjectAccessService` and `RoutingAccessService` as backend security/visibility boundaries.
- Existing Workspace V2 three-zone product structure and its progressively split assets.
- Structured diagnostics, immutable migrations and required CI gates.

### MERGE

Merge duplicated rules into an existing canonical owner when touching that area and behavior is covered:

- handler/action field parsing → `NeedValueResolver`;
- handler-side need mutation/progression → `NeedApplicationService` / canonical progression;
- callback-specific stale/duplicate checks → `InteractionGuard`;
- duplicated task urgency interpretation in UI/projections → `LeadTaskService` output;
- duplicate authorization/project/source checks in manager endpoints → shared backend auth/access services.

Do not merge technical conversation status with sales stages.

### MOVE

Incrementally move responsibilities, not whole folders:

- transport-specific normalization out of business handlers when an adapter can own it cleanly;
- query-building/projection helpers out of large manager services when a stable read-model boundary emerges;
- Workspace V2 behavior out of generic bootstrap/core files into focused modules when a feature becomes independently testable;
- structured observability construction out of business mutation code when it can be emitted through lifecycle/diagnostic helpers without changing semantics.

### DELETE

Delete only after replacement behavior is production-proven and required regressions cover it:

- obsolete duplicate parser/status maps after all call sites use canonical resolver/state-machine owners;
- dead compatibility helpers after callers are migrated;
- duplicated manager API authorization helpers after shared boundaries cover every endpoint;
- obsolete UI bootstrap DOM patching after the corresponding navigation/control is owned by canonical markup/module state.

No mass deletion or directory rewrite is authorized by this map.

## Current hotspots to observe

The production architecture inventory currently flags these as size/responsibility hotspots rather than automatic refactor targets:

- `actions/callbacks/WizardCallbackAction.php`
- `services/DialogueView.php`
- `services/DestinationResolver.php`
- `services/DestinationAreaResolver.php`
- `services/ManagerConversationService.php`
- `services/TripStateService.php`
- `services/ConversationStateRepository.php`
- `handlers/StateMessageHandler.php`
- `handlers/AiMessageHandler.php`
- `services/DialogueController.php`

A hotspot should be split only when the next product/defect change reveals a stable responsibility boundary. Line count alone is not a reason to refactor.

## Repository audit cadence

A periodic full pass should cover `handlers/`, `actions/`, `services/`, `integrations/`, `manager/`, `website/`, `cron/`, `migrations/`, `tests/` and `tools/`, then update this map only for real ownership changes. Each pass should explicitly record whether a touched responsibility is KEEP, MERGE, MOVE or DELETE.

The current inventory reports no runtime DDL outside migration infrastructure. Preserve that invariant.

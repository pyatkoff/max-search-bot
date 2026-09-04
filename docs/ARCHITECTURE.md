# MAX Search Bot — Architecture

This document is the repository-local architecture contract for autonomous development. It describes the current production shape, the target direction, and the rules for incremental refactoring.

## Product boundaries

The repository owns the MAX/Telegram/website dialogue application, manager workspace, sales-pipeline state, manager handoff/routing support, diagnostics, migrations and integration adapters used by this project.

Hard boundaries:
- do not modify neighbouring AnyTour projects from this repository;
- do not change Yandex Metrica goals or the existing lead-sending mechanism as part of architectural cleanup;
- do not mutate operator-controlled manager working status or routing bonuses to make diagnostics green.

## Current production shape

The application has grown incrementally and currently mixes several architectural generations:

- root entry points and legacy orchestration (`webhook.php`, `maxsearchclass.php`, `maxsearchbaseclass.php`, cron/tools);
- transport and normalized update handlers under `handlers/`;
- user/business actions under `actions/` and `actions/callbacks/`;
- many focused services under `services/`;
- integration contracts/adapters under `contracts/` and `integrations/`;
- manager legacy workspace and Workspace V2 under `manager/`;
- versioned schema under `migrations/`;
- required regression suite and production-derived scenarios under `tests/`.

The current decomposition is useful but responsibilities are not yet consistently layered. In particular, some handlers still contain business/state decisions, manager HTTP endpoints duplicate auth/validation plumbing, and Workspace V2 is again accumulating CSS, rendering and network code in one PHP file.

## Target dependency direction

Converge incrementally toward four conceptual layers. Physical directory movement is optional and should happen only when it improves clarity without creating a risky rewrite.

### Domain

Pure concepts and deterministic rules with no HTTP, MAX, filesystem or SQL concerns.

Examples:
- dialogue states and legal transitions;
- parsed need values and trip state;
- sales-pipeline stages/outcomes;
- handoff policy decisions;
- routing score rules.

### Application

Use cases that coordinate domain rules and repositories/adapters.

Examples:
- process incoming normalized message/callback/contact;
- resolve and apply a tourist need;
- request manager handoff;
- take/release/close a conversation;
- update lead stage/tags/outcome;
- send manager reply/media.

### Infrastructure

Persistence, transports and external systems.

Examples:
- MySQL/ConversationDb repositories;
- MAX/Telegram/Website messenger adapters;
- Tourvisor provider;
- Bitrix lead destination;
- Web Push transport;
- migration runner;
- filesystem/logging implementation.

### Interfaces

HTTP/webhook/cron/admin/UI delivery mechanisms.

Examples:
- webhook entry points;
- manager API endpoints;
- manager workspace HTML/JS/CSS;
- cron commands;
- diagnostic/export tools.

Dependencies should point inward: interfaces/infrastructure may call application/domain, but domain must not depend on HTTP/UI/transport implementations.

## Canonical dialogue flow

The target flow remains:

`Transport adapter → normalized incoming event → InteractionGuard → DialogueStateMachine → NeedValueResolver → NeedApplicationService → DialogueView → Search/Handoff`

Rules:
- `DialogueStateMachine` is the canonical owner of state-transition validity.
- deterministic parsers/`NeedValueResolver` understand known fields first;
- AI understands free text and fills gaps, but must not become a parallel state owner;
- `NeedApplicationService` is the canonical boundary for applying recognized values with upsert semantics;
- `ExistingWizardStepApplicationService` owns update-only writes to a step that already exists after the current start boundary and must never append a hidden status transition;
- presentation belongs to `DialogueView`/view-model helpers rather than state mutation code;
- confirmed production defects should become reusable regression scenarios.

## Manager workspace architecture

Workspace V2 is the forward path. The legacy `manager/index.php` remains a compatibility surface until V2 reaches feature parity and is production-proven.

Target UI responsibilities:
- Inbox: lead-centric queue, filters, urgency/SLA, unread, stage/tags;
- Conversation: original tourist transcript, manager replies, media and delivery states;
- Lead card: structured trip/contact/source/handoff/sales-pipeline data;
- Tasks/reminders: manager follow-up work;
- Admin: managers, projects, sources, routing, pipeline catalog and audit history.

Do not let `manager/workspace-v2.php` become another monolith. Incrementally extract:
- `manager/assets/workspace.css`;
- `manager/assets/workspace.js`;
- modules/components for inbox, conversation, lead card, pipeline, media and notifications where practical.

A frontend framework is not required. Prefer simple modules and existing PHP unless a framework has a clear product/maintenance payoff.

## Manager API rules

Manager endpoints must share the same invariants:
- one session/auth bootstrap;
- centralized CSRF validation;
- centralized conversation visibility/ownership authorization;
- consistent JSON success/error shape;
- technical conversation state is separate from sales-pipeline state;
- manager/admin permissions are not reimplemented ad hoc in every endpoint.

The long-term direction is a coherent manager API surface for conversations, messages/media, pipeline, tasks and admin resources. Legacy and V2 endpoints may coexist during migration, but new business rules should have one application/service owner.

## Handoff rules

Canonical product policy:
- working hours: 10:00–20:00 Europe/Kaliningrad;
- reachable eligible manager available → live handoff, phone is optional;
- no manager reply after 5 minutes → offer phone capture once, idempotently;
- outside working hours → self-service tours/site first, optional phone with truthful next-working-period expectation.

Availability, handoff presentation, fallback and lifecycle diagnostics must not be independently reimplemented in callbacks, AI actions and manager UI.

## Sales pipeline rules

Sales state is independent from technical dialogue state.

Technical state examples: `ai`, `waiting_manager`, `manager`, `closed`.

Sales state examples: stage, tags, outcome, close reason, future task/reminder and sale result.

Changing a sales stage must never implicitly mutate the technical dialogue status unless an explicit product use case is implemented and tested.

## Persistence and migrations

- production schema changes happen through numbered migrations only;
- applied migration files are immutable;
- repairs are forward-only migrations;
- migration runner records a migration only after all statements succeed;
- migrations should be retry-safe where partial execution can occur;
- runtime request paths must not silently create/alter schema.

## Tests

Use the smallest useful layer:
- pure domain/parser tests for deterministic rules;
- application/service tests for behavior and authorization;
- scenario regression corpus for confirmed production dialogue defects;
- integration-contract tests for adapters/transports;
- static source assertions only for architectural prohibitions that cannot be tested behaviorally.

Avoid fragile formatting/string assertions for ordinary behavior. A harmless code-format change should not break required CI.

## Observability

Prefer structured events with stable correlation identifiers:
- conversation id;
- request/update id;
- manager id;
- dispatch id;
- canonical state/transition;
- result/reason;
- timestamps/latency.

Diagnostics should explain lifecycle and failures without requiring reconstruction from multiple unrelated text logs. Keep live evidence bounded and avoid unnecessary personal data/secrets.

## Explicit repository convergence map

This map is intentionally conservative. It describes the expected destination of current repository areas; it is not permission for a bulk move or deletion. Every move/delete still requires behavior coverage, switched callers and production verification.

### Keep

- `handlers/`: keep as transport/interface normalization boundaries; business/state rules should continue moving inward.
- `actions/` and `actions/callbacks/`: keep as thin application entry/use-case orchestration while extracting reusable business rules to services/domain owners.
- `services/`: keep as the main incremental application/domain boundary; split only when an owner becomes clearer, not to satisfy folder aesthetics.
- `contracts/` and `integrations/`: keep as explicit external-system boundaries.
- `migrations/`: keep as the only production schema mutation path; applied migrations remain immutable.
- `tests/` and `tests/live_regressions/`: keep; favor behavior/scenario coverage over source-format assertions.
- `manager/assets/workspace-v2-*.js` and `workspace-v2-*.css`: keep the module split and continue assigning one UI responsibility per module.
- `tools/` and production diagnostic workflows: keep as bounded operational interfaces, with structured/redacted evidence.
- `services/TourSearchHandoffService.php`: keep as the single normalization owner for MAX/dialogue/claim → canonical tour-search query handoff.

### Merge / consolidate ownership

- callback validity, debounce, stale-interaction and future generation checks → `InteractionGuard` + `DialogueStateMachine`; do not add new per-callback guard implementations.
- deterministic field parsing → `NeedValueResolver`; value mutation/next-field choice → `NeedApplicationService`.
- manager endpoint auth, CSRF, visibility and JSON error semantics → shared manager HTTP/auth helpers rather than endpoint-local copies.
- handoff availability/presentation/fallback rules → one handoff application policy/service path shared by AI, callbacks and manager-facing diagnostics.
- sales-stage/tag/outcome/task mutations → their existing pipeline/task services; UI modules remain transport/presentation clients only.
- tour-search URL field normalization → `TourSearchHandoffService`; `ProjectConfig` may expose compatibility methods but must not regain mapping/business logic.

### Move incrementally when touched

- business/state decisions still embedded in `handlers/` → application services/actions after regression coverage.
- direct SQL writes in mixed orchestration/services → focused repositories/infrastructure adapters, one business owner per mutation.
- authorization/validation repeated in `manager/*.php` endpoints → centralized manager request guard/validator helpers.
- remaining large Workspace V2 rendering/network/task/pipeline concerns in PHP or broad JS modules → focused inbox/conversation/lead-card/pipeline/media/notification modules.
- legacy orchestration methods in `maxsearchbaseclass.php` / `maxsearchclass.php` → canonical services only when a concrete responsibility has a tested replacement.
- root/cron diagnostic assembly that duplicates lifecycle rules → read-only diagnostic/application services with root scripts left as thin interfaces.

### Delete only after callers are proven gone

- duplicate parser/state/handoff implementations superseded by canonical owners.
- dead compatibility methods after repository-wide caller search plus required regression confirms no remaining use.
- obsolete Workspace V2 inline CSS/JS after equivalent asset modules are loaded and visual QA is green.
- legacy manager workspace surfaces only after V2 feature parity, production usage and rollback confidence are established; `manager/index.php` is not a current deletion candidate.
- old transport/domain-specific branches only after messenger-neutral adapters and production evidence cover the same behavior.

### Never merge conceptually

- technical conversation state (`ai`, `waiting_manager`, `manager`, `closed`) with business sales stage/outcome.
- operator-controlled `is_working` with technical reachability/push subscription health.
- UI presentation state with routing eligibility or handoff policy.
- analytics/diagnostic observations with mutation logic.

## Refactoring rule

No big-bang rewrite. When touching an area for product work:
1. identify its current owner(s);
2. add/strengthen behavior regression coverage;
3. move one responsibility behind the canonical boundary;
4. switch callers;
5. production-verify;
6. only then remove dead legacy code.

Production/live defects always outrank cleanup.

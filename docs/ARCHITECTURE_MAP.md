# Architecture keep / merge / move / delete map

This is the current incremental refactoring map. It is intentionally conservative: production behavior wins over directory purity, and entries move only behind regression coverage.

## KEEP — canonical owners / forward paths

- `services/DialogueStateMachine.php` — dialogue transition validity.
- `services/NeedValueResolver.php` — deterministic need interpretation boundary.
- `services/NeedApplicationService.php` — applying recognized need values.
- `services/InteractionGuard.php` — callback concurrency/staleness safety.
- `services/ManagerRequestContext.php` — manager identity plus conversation authorization/visibility context.
- `manager/lib/ManagerHttp.php` — shared Manager HTTP/session/auth/CSRF/JSON/error boundary for thin PHP endpoints; new endpoint plumbing should converge here instead of recreating local guards.
- `services/AdminDirectoryService.php` — bounded admin directory/read snapshot composition.
- `services/AuditLogService.php` — admin audit persistence plus bounded data-minimized read projection; UI must not expose raw before/after payloads.
- `services/LeadTaskService.php` — lead task/reminder mutations, explicit pin/priority state, ordering, and canonical urgency semantics (`overdue` / `today` / `upcoming` / `unscheduled`, Europe/Kaliningrad business day).
- Sales-pipeline services/repositories — business lead state, independent from technical conversation status.
- `manager/index.php` plus focused `manager/assets/workspace-v2-*` modules — canonical forward Manager UI entrypoint, kept thin and progressively modular; do not recreate a second Workspace PHP shell.
- `manager/admin.php` + `manager/assets/admin.css` — current role-gated admin interface; keep behavior in PHP/JS thin and presentation outside the PHP monolith while splitting further only when a real slice needs it.
- `migrations/` — only owner of production schema evolution; applied files immutable.
- `services/MigrationRunner.php` — migration execution infrastructure only. DDL here is reported separately as `schema_infrastructure_ddl`; it must never be treated as permission for business/request services to own schema.
- `tests/scenarios/<suite>/` + `tests/support/ScenarioEngine.php` — reusable production-derived behavior scenarios; add new step handlers only when a real case needs them.
- `tools/production_snapshot.php`, `tools/live_session_snapshot.php`, `tools/architecture_inventory.php` — bounded operational evidence for autopilot. `runtime_ddl` is reserved for request/business runtime code; migration infrastructure is classified separately so the signal remains actionable.

## MERGE — duplicate responsibilities to converge behind one owner

- Remaining direct field parsing in handlers/actions → `NeedValueResolver`.
- Remaining direct need mutation / next-field choice → `NeedApplicationService` and canonical progression owner.
- Remaining Manager endpoint session/auth/CSRF/JSON/error plumbing → `manager/lib/ManagerHttp.php`; conversation edit visibility stays delegated to `ManagerRequestContext` rather than duplicated.
- Repeated admin directory/audit read assembly → `AdminDirectoryService` / `AuditLogService`, leaving `manager/admin.php` as an interface renderer.
- Repeated handoff policy wording/availability decisions → canonical handoff policy/application owner.
- Repeated sales-stage mutation paths → one sales-pipeline application service.
- Any remaining lead-task deadline/urgency/pinning/priority classification or mutation outside `LeadTaskService` → delegate to `LeadTaskService` and keep UI/read models projection-only.
- Production-derived bespoke regression runners → shared scenario suites where the scenario engine can represent the behavior without weakening coverage.

## MOVE — responsibilities that should leave their current layer when touched

- Business/state decisions still inside transport handlers → application/domain services.
- SQL persistence embedded in request/UI code → repositories/infrastructure services.
- Rendering/network logic remaining in `manager/index.php` → focused `manager/assets/` modules or view helpers.
- Manager UI business mutations → manager application services; PHP endpoints remain interface adapters using the shared `ManagerHttp` boundary.
- Admin rendering/business logic that grows beyond the current thin page → focused admin assets/services; do not rebuild another PHP/CSS/JS monolith.
- Structured operational decisions embedded only in text logs → typed diagnostic events/snapshots.

## DELETE — only after callers are migrated and production verification is complete

- Dead duplicate parser/state branches superseded by canonical services.
- Legacy manager UI branches after Workspace V2 feature parity and production proof.
- Ad-hoc Manager session/auth/CSRF/JSON/error helpers duplicated by `ManagerHttp` after all callers have migrated and regressions cover the boundary.
- Runtime schema-creation/alteration code after equivalent forward migrations exist.
- Bespoke regression runners when their scenario is represented by the reusable scenario engine without losing coverage.

## Audit rule

Run `php tools/architecture_inventory.php` during periodic full-repository audits. Treat its output as triage evidence, not an automatic refactor order: production/lead safety and the roadmap priority order still decide what is changed next. `runtime_ddl` should be empty unless a real request/business runtime schema owner remains; `schema_infrastructure_ddl` may contain only the migration execution boundary.
